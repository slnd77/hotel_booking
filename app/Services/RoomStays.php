<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Enums\SubBookingStatus;
use App\Models\Booking;
use App\Models\Property;
use App\Models\SubBooking;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Stay status lives on each room (sub-booking); the booking's status follows from
 * its rooms. Checking out one room early frees it for the remaining nights while
 * the rest of the party stays.
 */
class RoomStays
{
    /**
     * Room stay changes the front desk can make, keyed "from → to".
     *
     * @var array<string, string>
     */
    private const TRANSITIONS = [
        'booked>checked_in' => 'check in',
        'checked_in>checked_out' => 'check out',
        'checked_in>booked' => 'undo check-in',
        'checked_out>checked_in' => 'undo check-out',
    ];

    /**
     * Moves one room to a new stay status.
     *
     * @param  CarbonInterface|null  $at  when the guest actually arrived or left (defaults to now)
     * @param  bool  $waiveStayTimeCharges  the front desk waived the early check-in / late check-out charge
     *
     * @throws ValidationException for a change that isn't allowed
     */
    public function changeRoom(SubBooking $room, SubBookingStatus $to, ?CarbonInterface $at = null, bool $waiveStayTimeCharges = false): void
    {
        $key = "{$room->status->value}>{$to->value}";

        if (! isset(self::TRANSITIONS[$key])) {
            throw ValidationException::withMessages(['status' => "A {$this->label($room->status)} room can't be moved to {$this->label($to)}."]);
        }

        $at ??= now();
        $property = $room->booking->property;
        // Stay dates are the property's calendar days: compare with its local date, not UTC's.
        $today = $property->localTime($at)->toDateString();

        if ($to === SubBookingStatus::CheckedIn && $room->status === SubBookingStatus::Booked) {
            $this->ensureCanArrive($room->check_in_date->toDateString(), $room->check_out_date->toDateString(), $property, $at);
        }

        $attributes = ['status' => $to, ...$this->reportedTimes($room, $to, $at)];

        if ($to === SubBookingStatus::CheckedOut && $today < $room->check_out_date->toDateString() && $today > $room->check_in_date->toDateString()) {
            // Early check-out: the remaining nights go back on sale.
            $attributes['check_out_date'] = $today;
        }

        if ($room->status === SubBookingStatus::CheckedOut && $to === SubBookingStatus::CheckedIn) {
            $attributes['check_out_date'] = $room->booking->check_out->toDateString();
        }

        app(StayTimeCharges::class)->around($room, $to, $at, $waiveStayTimeCharges, function () use ($room, $attributes): void {
            $room->update($attributes);
        });
    }

    /**
     * The booking-level Check In / Check Out / Undo buttons act on every room that can make that move.
     */
    public function applyToAllRooms(Booking $booking, BookingStatus $status, ?CarbonInterface $at = null, bool $waiveStayTimeCharges = false): void
    {
        $at ??= now();

        [$from, $to] = match ($status) {
            BookingStatus::CheckedIn => [[SubBookingStatus::Booked, SubBookingStatus::CheckedOut], SubBookingStatus::CheckedIn],
            BookingStatus::CheckedOut => [[SubBookingStatus::Booked, SubBookingStatus::CheckedIn], SubBookingStatus::CheckedOut],
            BookingStatus::Confirmed => [[SubBookingStatus::CheckedIn, SubBookingStatus::CheckedOut], SubBookingStatus::Booked],
            default => [[], null],
        };

        if ($to === null) {
            return;
        }

        $today = $booking->property->localTime($at)->toDateString();

        $booking->subBookings()->whereIn('status', $from)->get()
            // Checking the booking in brings in the rooms that have arrived; a split booking's later rooms wait.
            ->reject(fn (SubBooking $room): bool => $to === SubBookingStatus::CheckedIn && $room->status === SubBookingStatus::Booked && $room->check_in_date->toDateString() > $today)
            ->each(function (SubBooking $room) use ($booking, $to, $at, $waiveStayTimeCharges): void {
                app(StayTimeCharges::class)->around($room, $to, $at, $waiveStayTimeCharges, function () use ($room, $booking, $to, $at): void {
                    $room->update([
                        'status' => $to,
                        ...$this->reportedTimes($room, $to, $at),
                        // A whole-booking move puts rooms back on the booking's own dates (a split booking's rooms keep theirs).
                        ...($booking->rooms_split ? [] : ['check_out_date' => $booking->check_out->toDateString()]),
                    ]);
                });
            });
    }

    /**
     * Validation for the reported check-in / check-out time sent with a stay change: optional
     * (defaults to now), and not in the future.
     *
     * @return array<string, array<int, string>>
     */
    public static function reportedAtRules(): array
    {
        return ['reported_at' => ['nullable', 'date', 'before_or_equal:'.now()->addMinutes(5)->toIso8601String()]];
    }

    /**
     * The reported time as an instant in UTC (the browser sends it with its UTC offset).
     */
    public static function reportedAt(?string $value): CarbonInterface
    {
        return filled($value) ? Carbon::parse($value)->utc() : now()->utc();
    }

    public const UNPAID_PAY_AT_HOTEL = 'It is set payable at hotel and it is not fully paid.';

    public const UNPAID_NEEDS_PERMISSION = 'Checkout with an outstanding balance requires authorization.';

    public const OUTSTANDING_CHECKOUT_PERMISSION = 'checkout_on_outstanding_payments';

    /**
     * A pay-at-hotel booking settles at the desk, so its guests can't check out while it's
     * unpaid. Other bookings (paid to an OTA, billed later …) check out with an amount due only
     * when the user holds "checkout_on_outstanding_payments".
     *
     * @param  float  $amountDue  what is still owed for what is checking out
     * @param  User|null  $user  who is checking out; null skips the permission check (system jobs)
     *
     * @throws ValidationException when a pay-at-hotel booking still owes, or the user may not leave a balance
     */
    public function ensureCanCheckOut(Booking $booking, float $amountDue, ?User $user = null): void
    {
        if (round($amountDue, 2) <= 0) {
            return;
        }

        if ($booking->pay_at_hotel) {
            throw ValidationException::withMessages(['status' => self::UNPAID_PAY_AT_HOTEL]);
        }

        if ($user !== null && ! $user->hasAnyRole(PermissionCatalog::fullAccessRoles())
            && ! $user->checkPermissionTo(self::OUTSTANDING_CHECKOUT_PERMISSION) && ! $user->checkPermissionTo('checkout on outstanding payments')) {
            throw ValidationException::withMessages(['status' => self::UNPAID_NEEDS_PERMISSION]);
        }
    }

    /**
     * What's still owed when these rooms check out: their own folios' balances — and the
     * booking's whole outstanding amount when no room stays behind.
     *
     * @param  Collection<int, SubBooking>  $rooms
     */
    public function amountDueForCheckOut(Booking $booking, Collection $rooms): float
    {
        $roomsDue = (float) $rooms->sum(fn (SubBooking $room): float => max(0, (float) ($room->folio?->balance() ?? 0)));
        $othersStay = $booking->subBookings()->where('status', SubBookingStatus::CheckedIn)->whereKeyNot($rooms->modelKeys())->exists();

        return $othersStay ? $roomsDue : max($roomsDue, (float) $booking->outstanding);
    }

    /**
     * Guests arrive from the check-in date until the day before departure — the property's
     * local calendar days.
     *
     * @throws ValidationException outside that window
     */
    public function ensureCanArrive(string $checkIn, string $checkOut, Property $property, ?CarbonInterface $at = null): void
    {
        $today = $property->localTime($at)->toDateString();

        if ($today < $checkIn || $today >= $checkOut) {
            throw ValidationException::withMessages(['status' => 'Check-in is only possible from '.Carbon::parse($checkIn)->format('d M Y').' until the day before check-out ('.Carbon::parse($checkOut)->format('d M Y').').']);
        }
    }

    /**
     * The reported (actual) check-in and check-out times a stay change records or undoes.
     *
     * @return array{checked_in_at?: ?CarbonInterface, checked_out_at?: ?CarbonInterface}
     *
     * @throws ValidationException when the reported check-out is before the room's check-in
     */
    private function reportedTimes(SubBooking $room, SubBookingStatus $to, CarbonInterface $at): array
    {
        if ($to === SubBookingStatus::CheckedOut && $room->checked_in_at?->greaterThan($at)) {
            throw ValidationException::withMessages(['reported_at' => 'The check-out time can\'t be before the room\'s check-in ('.$room->booking->property->localTime($room->checked_in_at)->format('d/m/Y g:i a').').']);
        }

        return match ($to) {
            SubBookingStatus::CheckedIn => $room->status === SubBookingStatus::CheckedOut
                ? ['checked_out_at' => null]
                : ['checked_in_at' => $at, 'checked_out_at' => null],
            SubBookingStatus::CheckedOut => ['checked_in_at' => $room->checked_in_at ?? $at, 'checked_out_at' => $at],
            SubBookingStatus::Booked => ['checked_in_at' => null, 'checked_out_at' => null],
            default => [],
        };
    }

    /**
     * The booking status its rooms add up to: checked out once every staying room has
     * left, checked in while anyone is (or was) in, confirmed before arrival. Null when
     * the rooms don't decide it (e.g. the booking is cancelled or on hold).
     */
    public function derivedBookingStatus(Booking $booking): ?BookingStatus
    {
        $stays = $booking->subBookings()
            ->whereIn('status', [SubBookingStatus::Booked, SubBookingStatus::CheckedIn, SubBookingStatus::CheckedOut, SubBookingStatus::NoShow])
            ->pluck('status');

        // None of the rooms came: a no-show. Otherwise no-show rooms don't count.
        if ($stays->isNotEmpty() && $stays->every(fn (SubBookingStatus $status): bool => $status === SubBookingStatus::NoShow)) {
            return BookingStatus::NoShow;
        }

        $stays = $stays->reject(fn (SubBookingStatus $status): bool => $status === SubBookingStatus::NoShow);

        if ($stays->isEmpty()) {
            return null;
        }

        if ($stays->every(fn (SubBookingStatus $status): bool => $status === SubBookingStatus::CheckedOut)) {
            return BookingStatus::CheckedOut;
        }

        return $stays->contains(fn (SubBookingStatus $status): bool => $status !== SubBookingStatus::Booked)
            ? BookingStatus::CheckedIn
            : BookingStatus::Confirmed;
    }

    /**
     * After the booking itself changes (dates moved, cancelled, put on hold …) its rooms
     * follow: they take the booking's dates, and booking-wide statuses. While the booking
     * is live, a room keeps its own arrival/departure status — and an early check-out its date.
     */
    public function followBooking(Booking $booking): void
    {
        $mapped = self::statusForBooking($booking->status);

        foreach ($booking->subBookings()->get() as $room) {
            $keepsOwnStay = self::isLiveStay($booking->status) && self::isLiveStay($room->status);
            $keepsCheckOutDate = $keepsOwnStay && $room->status === SubBookingStatus::CheckedOut;

            // A split booking's rooms keep their own dates; the booking's dates just span them.
            if (! $booking->rooms_split) {
                $room->fill([
                    'check_in_date' => $booking->check_in->toDateString(),
                    'check_out_date' => $keepsCheckOutDate ? $room->check_out_date->toDateString() : $booking->check_out->toDateString(),
                ]);
            }

            $room->fill(['status' => $keepsOwnStay ? $room->status : $mapped]);

            if ($room->isDirty()) {
                $room->save();
            }
        }
    }

    /**
     * Lets each room of the booking keep its own dates from now on.
     *
     * @throws ValidationException when the booking can't be split
     */
    public function split(Booking $booking): void
    {
        if ($booking->rooms_split) {
            return;
        }

        if ($booking->rooms()->count() < 2) {
            throw ValidationException::withMessages(['booking' => 'Only a booking with more than one room can be split.']);
        }

        if (! in_array($booking->status, [BookingStatus::Confirmed, BookingStatus::Pending, BookingStatus::OnHold, BookingStatus::CheckedIn], true)) {
            throw ValidationException::withMessages(['booking' => "A {$booking->status->label()} booking can't be split."]);
        }

        $booking->forceFill(['rooms_split' => true])->saveQuietly();
    }

    /**
     * The booking's total before and after giving one room new dates, without saving.
     * Only a change in that room's nights moves the price — the stored price is kept and
     * adjusted by the difference, so a booking priced under older rates or taxes isn't
     * re-priced just because a room shifted.
     *
     * @return array{current_total: float, new_total: float, difference: float, delta: array{room_total: float, taxes: float, total: float}}
     */
    public function previewRoomDates(SubBooking $room, string $checkIn, string $checkOut): array
    {
        $booking = $room->booking;
        $pricing = app(BookingPricing::class);
        $rooms = $booking->rooms()->get();

        $priced = function (bool $withNewDates) use ($rooms, $room, $checkIn, $checkOut, $pricing, $booking): array {
            return $pricing->totals($booking->property, array_values($rooms->map(fn (SubBooking $each): array => [
                'rate_per_night' => (float) $each->rate_per_night,
                'nights' => $withNewDates && $each->is($room)
                    ? BookingPricing::nights($checkIn, $checkOut)
                    : BookingPricing::nights($each->check_in_date->toDateString(), $each->check_out_date->toDateString()),
            ])->all()), (float) $booking->discount);
        };

        $before = $priced(false);
        $after = $priced(true);
        $delta = [
            'room_total' => round($after['room_total'] - $before['room_total'], 2),
            'taxes' => round($after['taxes'] - $before['taxes'], 2),
            'total' => round($after['total'] - $before['total'], 2),
        ];
        $current = round((float) $booking->total, 2);

        return ['current_total' => $current, 'new_total' => round($current + $delta['total'], 2), 'difference' => $delta['total'], 'delta' => $delta];
    }

    /**
     * Gives one room its own dates (splitting the booking if needed), re-spans the
     * booking's dates and adjusts its price for the room's change in nights.
     *
     * @return array{current_total: float, new_total: float, difference: float, delta: array{room_total: float, taxes: float, total: float}}
     *
     * @throws ValidationException when the room can't take those dates
     */
    public function changeRoomDates(SubBooking $room, string $checkIn, string $checkOut, ?int $roomId = null): array
    {
        $this->validateRoomDates($room, $checkIn, $checkOut, $roomId);
        $price = $this->previewRoomDates($room, $checkIn, $checkOut);

        DB::transaction(function () use ($room, $checkIn, $checkOut, $roomId, $price): void {
            $booking = $room->booking;
            $this->split($booking);

            $room->update(array_filter([
                'check_in_date' => $checkIn,
                'check_out_date' => $checkOut,
                'room_id' => $roomId,
            ], fn ($value): bool => $value !== null));

            $this->respanBooking($booking->fresh(), $price['delta']);
        });

        return $price;
    }

    /**
     * A split booking's dates span its rooms; a price change (from a room's nights
     * changing) is added to the stored price.
     *
     * @param  array{room_total: float, taxes: float, total: float}|null  $priceDelta
     */
    public function respanBooking(Booking $booking, ?array $priceDelta = null): void
    {
        $rooms = $booking->rooms()->get();

        if ($rooms->isEmpty()) {
            return;
        }

        $booking->forceFill([
            'check_in' => $rooms->min(fn (SubBooking $room): string => $room->check_in_date->toDateString()),
            'check_out' => $rooms->max(fn (SubBooking $room): string => $room->check_out_date->toDateString()),
            ...($priceDelta === null ? [] : [
                'room_total' => round((float) $booking->room_total + $priceDelta['room_total'], 2),
                'taxes' => round((float) $booking->taxes + $priceDelta['taxes'], 2),
                'total' => round((float) $booking->total + $priceDelta['total'], 2),
            ]),
        ])->saveQuietly();

        // Quiet save: the ledger still has to pick up the new price.
        app(BookingLedgerSync::class)->schedule($booking->id);
    }

    /**
     * @throws ValidationException
     */
    private function validateRoomDates(SubBooking $room, string $checkIn, string $checkOut, ?int $roomId): void
    {
        $booking = $room->booking;

        if ($checkOut <= $checkIn) {
            throw ValidationException::withMessages(['check_out' => 'Check-out must be after check-in.']);
        }

        if (! in_array($room->status, [SubBookingStatus::Hold, SubBookingStatus::Booked, SubBookingStatus::CheckedIn], true)) {
            throw ValidationException::withMessages(['check_in' => "A {$this->label($room->status)} room's dates can't be changed."]);
        }

        $checkInChanged = $checkIn !== $room->check_in_date->toDateString();

        if ($checkInChanged && $room->status === SubBookingStatus::CheckedIn) {
            throw ValidationException::withMessages(['check_in' => 'This room is already checked in, so only its check-out date can change.']);
        }

        if ($checkInChanged && $checkIn < $booking->property->today()) {
            throw ValidationException::withMessages(['check_in' => 'A room cannot be moved into the past.']);
        }

        $inventory = app(RoomInventory::class);
        $targetRoomId = $roomId ?? $room->room_id;

        if ($targetRoomId !== null && $inventory->unavailableRoomIds($booking->property_id, $checkIn, $checkOut, null, $room->id)->contains($targetRoomId)) {
            throw ValidationException::withMessages(['room_id' => 'That room is already booked or blocked for those dates.']);
        }

        $capacity = $inventory->capacityByCategory($booking->property_id)->only([$room->room_category_id]);
        $available = $inventory->availabilityByCategory($booking->property_id, $capacity, $checkIn, $checkOut, null, $room->id);

        if ((int) ($available[$room->room_category_id] ?? 0) < 1) {
            throw ValidationException::withMessages(['check_in' => 'No room of this type is free for those dates.']);
        }
    }

    /**
     * The status a room takes from its booking's status.
     */
    public static function statusForBooking(?BookingStatus $status): SubBookingStatus
    {
        return match ($status) {
            BookingStatus::OnHold => SubBookingStatus::Hold,
            BookingStatus::Enquiry => SubBookingStatus::Quote,
            BookingStatus::CheckedIn => SubBookingStatus::CheckedIn,
            BookingStatus::CheckedOut => SubBookingStatus::CheckedOut,
            BookingStatus::Cancelled, BookingStatus::ExpiredQuote => SubBookingStatus::Cancelled,
            BookingStatus::NoShow => SubBookingStatus::NoShow,
            default => SubBookingStatus::Booked,
        };
    }

    /**
     * Booked, in-house or departed — the statuses rooms manage for themselves.
     */
    private static function isLiveStay(BookingStatus|SubBookingStatus $status): bool
    {
        return in_array($status, [
            BookingStatus::Confirmed, BookingStatus::Pending, BookingStatus::CheckedIn, BookingStatus::CheckedOut,
            SubBookingStatus::Booked, SubBookingStatus::CheckedIn, SubBookingStatus::CheckedOut,
        ], true);
    }

    public function label(SubBookingStatus $status): string
    {
        return str_replace('_', ' ', $status->value);
    }

    /**
     * The action name for a move, e.g. "check out".
     */
    public function actionFor(SubBookingStatus $from, SubBookingStatus $to): ?string
    {
        return self::TRANSITIONS["{$from->value}>{$to->value}"] ?? null;
    }
}
