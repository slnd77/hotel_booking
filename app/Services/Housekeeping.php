<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Property;
use App\Models\PropertyMetaField;
use App\Models\Room;
use App\Models\SubBooking;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Rooms' housekeeping status (Clean, Dirty, …) — the statuses, their colours and their
 * check-in / check-out defaults come from Booking Settings → Housekeeping.
 */
class Housekeeping
{
    /** What Booking Settings → Housekeeping starts with until it's saved. */
    private const DEFAULT_STATUSES = [
        ['name' => 'Clean', 'color' => '#e3e3e0', 'display_order' => 1, 'abbreviation' => '', 'default_on_check_in' => false, 'default_on_check_out' => false, 'allow_check_in' => true, 'hard_stop' => false],
        ['name' => 'Dirty', 'color' => '#f53003', 'display_order' => 2, 'abbreviation' => '', 'default_on_check_in' => false, 'default_on_check_out' => true, 'allow_check_in' => false, 'hard_stop' => false],
    ];

    /**
     * Every status name in use: the defaults and those saved by any of the account's properties.
     *
     * @return list<string>
     */
    public function statusNamesAcrossProperties(): array
    {
        $names = array_column(self::DEFAULT_STATUSES, 'name');
        $metaFieldId = app(PropertySettings::class)->metaFieldId('booking_settings_housekeeping');

        if ($metaFieldId !== null) {
            foreach (PropertyMetaField::query()->where('meta_fields_key_id', $metaFieldId)->pluck('data') as $data) {
                $decoded = filled($data) ? json_decode($data, true) : null;

                foreach ((array) ($decoded['statuses'] ?? []) as $status) {
                    if (is_array($status) && filled($status['name'] ?? null)) {
                        $names[] = (string) $status['name'];
                    }
                }
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * The property's statuses in display order.
     *
     * @return Collection<int, array{name: string, color: string, abbreviation: string, default_on_check_in: bool, default_on_check_out: bool, default_on_room_change: bool, allow_check_in: bool, hard_stop: bool}>
     */
    public function statuses(Property $property): Collection
    {
        return app(PropertySettings::class)->remember($property, 'housekeeping.statuses', fn (): Collection => $this->resolveStatuses($property));
    }

    /**
     * @return Collection<int, array{name: string, color: string, abbreviation: string, default_on_check_in: bool, default_on_check_out: bool, default_on_room_change: bool, allow_check_in: bool, hard_stop: bool}>
     */
    private function resolveStatuses(Property $property): Collection
    {
        $saved = app(PropertySettings::class)->json($property, 'booking_settings_housekeeping')['statuses'] ?? null;

        return collect(is_array($saved) && $saved !== [] ? $saved : self::DEFAULT_STATUSES)
            ->filter(fn ($status): bool => is_array($status) && filled($status['name'] ?? null))
            ->sortBy(fn (array $status): int => (int) ($status['display_order'] ?? 0))
            ->map(fn (array $status): array => [
                'name' => (string) $status['name'],
                'color' => (string) ($status['color'] ?? '#e3e3e0'),
                'abbreviation' => (string) ($status['abbreviation'] ?? ''),
                'default_on_check_in' => (bool) ($status['default_on_check_in'] ?? false),
                'default_on_check_out' => (bool) ($status['default_on_check_out'] ?? false),
                'default_on_room_change' => (bool) ($status['default_on_room_change'] ?? false),
                // A room in this status can be checked in (No: only after confirming; with a hard stop: not at all).
                'allow_check_in' => (bool) ($status['allow_check_in'] ?? true),
                'hard_stop' => (bool) ($status['hard_stop'] ?? false),
            ])
            ->values();
    }

    /**
     * Rooms that shouldn't take guests yet, per their housekeeping status: a hard stop refuses
     * the check-in; otherwise the front desk may confirm and check in anyway.
     *
     * @param  iterable<SubBooking>  $stays  the room stays about to check in
     * @return array{hard: list<string>, soft: list<string>}
     */
    public function checkInProblems(Property $property, iterable $stays): array
    {
        $statuses = $this->statuses($property);
        $problems = ['hard' => [], 'soft' => []];
        $rooms = Room::query()->whereKey(collect($stays)->map(fn (SubBooking $stay): ?int => $stay->room_id)->filter()->unique())->get();

        foreach ($rooms as $room) {
            $status = $statuses->firstWhere('name', $room->housekeeping_status) ?? $statuses->first();

            if ($status && ! $status['allow_check_in']) {
                $problems[$status['hard_stop'] ? 'hard' : 'soft'][] = "{$room->name} is {$status['name']}";
            }
        }

        return $problems;
    }

    /**
     * Before a check-in: refuses rooms behind a hard stop, and rooms that don't allow check-in
     * unless the front desk confirmed ("Check in anyway").
     *
     * @param  iterable<SubBooking>  $stays
     *
     * @throws ValidationException naming the rooms
     */
    public function ensureCanCheckIn(Property $property, iterable $stays, bool $confirmed): void
    {
        // A room (or dorm bed) whose guest is still in house - an overstay included - is taken, whatever the dates.
        $occupied = $this->occupiedByAnotherGuest($property, $stays);

        if ($occupied !== []) {
            throw ValidationException::withMessages(['housekeeping' => implode(', ', $occupied).' - it can\'t be checked in until that guest checks out.']);
        }

        $problems = $this->checkInProblems($property, $stays);

        if ($problems['hard'] !== []) {
            throw ValidationException::withMessages(['housekeeping' => implode(', ', $problems['hard']).' — it can\'t be checked in until housekeeping marks it ready.']);
        }

        if ($problems['soft'] !== [] && ! $confirmed) {
            throw ValidationException::withMessages(['housekeeping' => implode(', ', $problems['soft']).' — tick "Check in anyway" to check in regardless.']);
        }
    }

    /**
     * Rooms of the stays with another booking's guest in house (checked in, not checked out).
     *
     * @param  iterable<SubBooking>  $stays
     * @return list<string> e.g. "413 is occupied by booking AANA4QSS"
     */
    public function occupiedByAnotherGuest(Property $property, iterable $stays): array
    {
        $stays = collect($stays);
        $bookingId = $stays->first()?->booking_id;
        $inHouse = app(RoomInventory::class)->inHouseUnitIds($property->id, $bookingId);
        $taken = $stays->filter(fn (SubBooking $stay): bool => $stay->inventory_unit_id !== null && $inHouse->has($stay->inventory_unit_id));

        if ($taken->isEmpty()) {
            return [];
        }

        $refs = Booking::query()->whereKey($taken->map(fn (SubBooking $stay): int => $inHouse[$stay->inventory_unit_id])->unique())->pluck('booking_ref_id', 'id');

        return array_values($taken->map(fn (SubBooking $stay): string => ($stay->inventoryUnit->name ?? 'The room').' is occupied by booking '.($refs[$inHouse[$stay->inventory_unit_id]] ?? '#'.$inHouse[$stay->inventory_unit_id]))->all());
    }

    /**
     * After check-in: the rooms take the status marked "Check-in default", if any.
     *
     * @param  iterable<SubBooking>  $stays
     */
    public function markCheckedIn(Property $property, iterable $stays): void
    {
        $status = $this->statuses($property)->firstWhere('default_on_check_in', true)['name'] ?? null;
        $roomIds = collect($stays)->map(fn (SubBooking $stay): ?int => $stay->room_id)->filter()->unique();

        if ($status !== null && $roomIds->isNotEmpty()) {
            $this->mark($property, Room::query()->whereKey($roomIds)->get(), $status);
        }
    }

    /**
     * A guest in house moved to another room: the room they left takes the "Room change
     * default" status (e.g. Dirty), if any.
     */
    public function markVacatedByRoomChange(Property $property, ?int $vacatedRoomId): void
    {
        $status = $this->statuses($property)->firstWhere('default_on_room_change', true)['name'] ?? null;
        $room = $vacatedRoomId ? Room::query()->find($vacatedRoomId) : null;

        if ($status !== null && $room) {
            $this->mark($property, [$room], $status);
        }
    }

    /** The status marked as the check-out default (e.g. Dirty), if any. */
    public function checkOutDefault(Property $property): ?string
    {
        return $this->statuses($property)->firstWhere('default_on_check_out', true)['name'] ?? null;
    }

    /**
     * A room's status as shown on the chart: its own, or the first status until it's marked.
     *
     * @return array{name: string, color: string}|null
     */
    public function statusOf(Property $property, Room $room): ?array
    {
        $statuses = $this->statuses($property);
        $status = $statuses->firstWhere('name', $room->housekeeping_status) ?? $statuses->first();

        return $status ? ['name' => $status['name'], 'color' => $status['color']] : null;
    }

    /**
     * After check-out: the rooms left take the status the front desk picked in the dialog,
     * else the property's check-out default (e.g. Dirty).
     *
     * @param  iterable<SubBooking>  $stays  the room stays that checked out
     *
     * @throws ValidationException for a status that isn't one of the property's
     */
    public function markCheckedOut(Property $property, iterable $stays, ?string $status = null): void
    {
        $status = filled($status) ? $status : $this->checkOutDefault($property);
        $roomIds = collect($stays)->map(fn (SubBooking $stay): ?int => $stay->room_id)->filter()->unique()->values();

        if ($status === null || $roomIds->isEmpty()) {
            return;
        }

        $this->mark($property, Room::query()->whereKey($roomIds)->get(), $status);
    }

    /**
     * @param  iterable<Room>  $rooms
     *
     * @throws ValidationException for a status that isn't one of the property's
     */
    public function mark(Property $property, iterable $rooms, string $status): void
    {
        if (! $this->statuses($property)->contains('name', $status)) {
            throw ValidationException::withMessages(['housekeeping_status' => "\"{$status}\" isn't a housekeeping status of this property."]);
        }

        foreach ($rooms as $room) {
            $room->update(['housekeeping_status' => $status, 'housekeeping_updated_at' => now()]);
        }
    }
}
