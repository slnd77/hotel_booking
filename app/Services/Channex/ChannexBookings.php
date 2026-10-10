<?php

namespace App\Services\Channex;

use App\Enums\BookingStatus;
use App\Enums\SubBookingStatus;
use App\Models\Booking;
use App\Models\BookingChannel;
use App\Models\ChannexBookingRevision;
use App\Models\ChannexConnection;
use App\Models\Guest;
use App\Models\SubBooking;
use App\Services\BookingPricing;
use App\Services\RatePackages;
use App\Services\RoomInventory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Pulls OTA bookings from Channex's booking revision feed and applies each revision: a new
 * booking is created, a modified one updated, a cancelled one cancelled. A revision is
 * acknowledged only once applied, so one that failed (e.g. an unmapped room type) comes
 * back on the next pull after the mapping is fixed.
 */
class ChannexBookings
{
    public function __construct(private RoomInventory $inventory) {}

    /**
     * @return array{applied: int, failed: int}
     */
    public function pull(ChannexConnection $connection): array
    {
        $client = ChannexClient::for($connection);
        $applied = 0;
        $failed = 0;

        try {
            $feed = $client->bookingFeed($connection->channex_property_id);
        } catch (ChannexException $exception) {
            $connection->forceFill(['last_error' => $exception->getMessage()])->save();

            throw $exception;
        }

        foreach ($feed as $row) {
            $revision = $this->record($connection, $row);

            if ($revision->processed_at === null) {
                $this->apply($connection, $revision);
            }

            if ($revision->processed_at === null) {
                $failed++;

                continue;
            }

            $client->acknowledge($revision->revision_id);
            $applied++;
        }

        $connection->forceFill(['last_pulled_at' => now()] + ($failed === 0 ? ['last_error' => null] : []))->save();

        return ['applied' => $applied, 'failed' => $failed];
    }

    /**
     * @param  array<string, mixed>  $row  a feed entry: {id, attributes}
     */
    private function record(ChannexConnection $connection, array $row): ChannexBookingRevision
    {
        $attributes = $row['attributes'] ?? [];

        return ChannexBookingRevision::query()->firstOrCreate(
            ['revision_id' => $row['id'] ?? $attributes['id']],
            [
                'channex_connection_id' => $connection->id,
                'property_id' => $connection->property_id,
                'channex_booking_id' => $attributes['booking_id'],
                'status' => $attributes['status'] ?? 'new',
                'ota_name' => $attributes['ota_name'] ?? null,
                'ota_reservation_code' => $attributes['ota_reservation_code'] ?? null,
                'payload' => $attributes,
            ],
        );
    }

    /**
     * Applies one revision; on failure keeps the reason on it and leaves it unprocessed.
     */
    public function apply(ChannexConnection $connection, ChannexBookingRevision $revision): void
    {
        try {
            $booking = DB::transaction(function () use ($connection, $revision): ?Booking {
                $existing = $this->bookingFor($revision);

                return match (true) {
                    $revision->status === 'cancelled' => $this->cancel($existing),
                    $existing !== null => $this->update($connection, $existing, $revision->payload),
                    default => $this->create($connection, $revision->payload),
                };
            });

            $revision->forceFill(['booking_id' => $booking?->id, 'processed_at' => now(), 'error' => null])->save();
        } catch (Throwable $exception) {
            if (! $exception instanceof ChannexException) {
                report($exception);
            }

            $revision->forceFill(['error' => $exception->getMessage()])->save();
        }
    }

    /**
     * The booking an earlier revision of the same Channex booking created.
     */
    private function bookingFor(ChannexBookingRevision $revision): ?Booking
    {
        $bookingId = ChannexBookingRevision::query()
            ->where('channex_booking_id', $revision->channex_booking_id)
            ->whereNotNull('booking_id')
            ->latest('id')
            ->value('booking_id');

        return $bookingId ? Booking::query()->whereKey($bookingId)->first() : null;
    }

    private function cancel(?Booking $booking): ?Booking
    {
        if ($booking !== null && $booking->status !== BookingStatus::Cancelled) {
            $booking->update(['status' => BookingStatus::Cancelled]);
        }

        return $booking;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function create(ChannexConnection $connection, array $payload): Booking
    {
        $rooms = $this->rooms($connection, $payload);

        $booking = Booking::query()->create([
            'property_id' => $connection->property_id,
            'booking_ref_id' => $this->reference($payload),
            'booking_channel_id' => $this->channel($payload)?->id,
            'source' => Booking::SOURCE_OTA,
            'type' => 'online',
            'booking_type' => 'online',
            'status' => BookingStatus::Confirmed,
            'same_plan_for_all_rooms' => false,
            'pay_at_hotel' => ($payload['payment_collect'] ?? 'property') === 'property',
            ...$this->amounts($payload, $rooms),
            'room_bill_to' => 'to_guest',
            'extras_bill_to' => 'to_guest',
            'special_instructions' => $payload['notes'] ?? null,
            'internal_notes' => $this->internalNote($payload),
        ]);

        if ($guest = $this->guest($connection, $payload['customer'] ?? [])) {
            $booking->guests()->syncWithoutDetaching([$guest->id]);
            $booking->forceFill(['guest_id' => $guest->id])->saveQuietly();
        }

        $this->createRooms($booking, $rooms);

        return $booking;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function update(ChannexConnection $connection, Booking $booking, array $payload): Booking
    {
        $rooms = $this->rooms($connection, $payload);
        $inHouse = $booking->rooms()->whereIn('status', [SubBookingStatus::CheckedIn, SubBookingStatus::CheckedOut])->exists();

        $booking->update([
            ...$this->amounts($payload, $rooms),
            'status' => $booking->status === BookingStatus::Cancelled ? BookingStatus::Confirmed : $booking->status,
            'special_instructions' => $payload['notes'] ?? $booking->special_instructions,
            'internal_notes' => $this->internalNote($payload),
        ]);

        // A guest already in the house keeps their rooms; only the amounts follow the OTA.
        if (! $inHouse) {
            $booking->rooms()->get()->each(fn (SubBooking $room) => $room->delete());
            $this->createRooms($booking, $rooms);
        }

        return $booking;
    }

    /**
     * The revision's rooms, each resolved to its mapped room category and meal plan.
     *
     * @param  array<string, mixed>  $payload
     * @return non-empty-list<array{room_category_id: int, meal_plan: string, package_id: int|null, package_name: mixed, check_in: string, check_out: string, adults: int, children: int, infants: int, amount: float, nights: int}>
     *
     * @throws ChannexException when a room type isn't mapped
     */
    private function rooms(ChannexConnection $connection, array $payload): array
    {
        $categories = $connection->roomMappings()->pluck('room_category_id', 'channex_room_type_id');
        $ratePlans = $connection->rateMappings()->get(['meal_plan', 'package_id', 'channex_rate_plan_id'])->keyBy('channex_rate_plan_id');
        // A package rate plan: the room is recorded as sold under that package.
        $packageNames = collect(app(RatePackages::class)->all($connection->property))->pluck('name', 'id');
        $rooms = [];

        foreach ($payload['rooms'] ?? [] as $room) {
            $categoryId = $categories[$room['room_type_id'] ?? ''] ?? null;

            if ($categoryId === null) {
                throw new ChannexException('Room type '.($room['room_type_id'] ?? '?').' is not mapped to a room category.');
            }

            $checkIn = $room['checkin_date'] ?? $payload['arrival_date'];
            $checkOut = $room['checkout_date'] ?? $payload['departure_date'];
            // An unmapped (or missing) rate plan sells the room only (EP).
            $ratePlan = $ratePlans->get($room['rate_plan_id'] ?? '');

            $rooms[] = [
                'room_category_id' => (int) $categoryId,
                'meal_plan' => $ratePlan->meal_plan ?? 'EP',
                'package_id' => $ratePlan?->package_id,
                'package_name' => $ratePlan?->package_id !== null ? $packageNames->get($ratePlan->package_id) : null,
                'check_in' => $checkIn,
                'check_out' => $checkOut,
                'adults' => max(1, (int) ($room['occupancy']['adults'] ?? 1)),
                'children' => (int) ($room['occupancy']['children'] ?? 0),
                'infants' => (int) ($room['occupancy']['infants'] ?? 0),
                'amount' => round((float) ($room['amount'] ?? 0), 2),
                'nights' => max(1, BookingPricing::nights($checkIn, $checkOut)),
            ];
        }

        if ($rooms === []) {
            throw new ChannexException('The booking has no rooms.');
        }

        return $rooms;
    }

    /**
     * @param  non-empty-list<array{room_category_id: int, meal_plan: string, package_id: int|null, package_name: mixed, check_in: string, check_out: string, adults: int, children: int, infants: int, amount: float, nights: int}>  $rooms
     */
    private function createRooms(Booking $booking, array $rooms): void
    {
        $taken = $this->inventory->unavailableRoomIds($booking->property_id, $booking->check_in->toDateString(), $booking->check_out->toDateString(), $booking->id);
        $freeBeds = $this->inventory->freeBedsByRoom($booking->property_id, $booking->check_in->toDateString(), $booking->check_out->toDateString());

        foreach ($rooms as $room) {
            // OTA bookings are already sold: take a free room, or leave it unassigned.
            $roomId = $this->inventory->firstFreeRoomId($room['room_category_id'], $taken, $freeBeds);

            if ($roomId !== null) {
                $taken = $taken->push($roomId);
            }

            $booking->rooms()->create([
                'room_category_id' => $room['room_category_id'],
                'room_id' => $roomId,
                'meal_plan' => $room['meal_plan'],
                'package_id' => $room['package_id'],
                'package_name' => $room['package_name'],
                'adults' => $room['adults'],
                'children' => $room['children'],
                'infants' => $room['infants'],
                'rate_per_night' => round($room['amount'] / $room['nights'], 2),
                'check_in_date' => $room['check_in'],
                'check_out_date' => $room['check_out'],
            ]);
        }
    }

    /**
     * Dates and money: the OTA's total is what the booking is worth (taxes included).
     *
     * @param  array<string, mixed>  $payload
     * @param  non-empty-list<array{check_in: string, check_out: string, amount: float}>  $rooms
     * @return array<string, mixed>
     */
    private function amounts(array $payload, array $rooms): array
    {
        $roomTotal = round(array_sum(array_column($rooms, 'amount')), 2);
        $total = round((float) ($payload['amount'] ?? $roomTotal), 2);

        return [
            'check_in' => $payload['arrival_date'] ?? min(array_column($rooms, 'check_in')),
            'check_out' => $payload['departure_date'] ?? max(array_column($rooms, 'check_out')),
            'room_total' => $total,
            'discount' => 0,
            'taxes' => 0,
            'total' => $total,
            'outstanding' => $total,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function reference(array $payload): string
    {
        $code = Str::limit((string) ($payload['ota_reservation_code'] ?? $payload['unique_id'] ?? ''), 40, '');

        return $code !== '' ? $code : Str::upper(Str::random(8));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function channel(array $payload): ?BookingChannel
    {
        $name = trim((string) ($payload['ota_name'] ?? ''));

        return $name === '' ? null : BookingChannel::query()->firstOrCreate(['name' => $name], ['is_active' => true]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function internalNote(array $payload): string
    {
        $collect = ($payload['payment_collect'] ?? 'property') === 'ota' ? 'prepaid to the OTA' : 'to be paid at the hotel';

        return trim("Channex: {$payload['ota_name']} booking {$payload['ota_reservation_code']}, {$collect}.");
    }

    /**
     * @param  array<string, mixed>  $customer
     */
    private function guest(ChannexConnection $connection, array $customer): ?Guest
    {
        $firstName = trim((string) ($customer['name'] ?? ''));
        $lastName = trim((string) ($customer['surname'] ?? ''));
        $email = trim((string) ($customer['mail'] ?? ''));
        $phone = trim((string) ($customer['phone'] ?? ''));

        if ($firstName === '' && $lastName === '' && $email === '' && $phone === '') {
            return null;
        }

        // A returning guest is matched by phone, else by e-mail.
        $guest = match (true) {
            $phone !== '' => Guest::query()->where('property_id', $connection->property_id)->where('phone', $phone)->first(),
            $email !== '' => Guest::query()->where('property_id', $connection->property_id)->whereJsonContains('emails', $email)->first(),
            default => null,
        };

        return $guest ?? Guest::query()->create([
            'property_id' => $connection->property_id,
            'first_name' => $firstName !== '' ? $firstName : 'Guest',
            'last_name' => $lastName,
            'phone' => $phone !== '' ? $phone : null,
            'emails' => $email !== '' ? [$email] : [],
        ]);
    }
}
