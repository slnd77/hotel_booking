<?php

namespace App\Services;

use App\Models\Addon;
use App\Models\Booking;
use App\Models\BookingAddon;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Add-on services on bookings, from the property's catalogue. A line is priced when added
 * (price × quantity × nights and/or guests, plus its tax); the booking keeps the total in
 * addons_total, and the ledger sync charges each line to the master folio (and reverses
 * removed ones).
 */
class BookingAddons
{
    public function __construct(private BookingLedgerSync $ledgerSync) {}

    /**
     * The property's active add-ons; on the booking engine only those shown there.
     *
     * @return Collection<int, Addon>
     */
    public function catalogue(Property $property, bool $bookingEngine = false): Collection
    {
        return $property->addons()
            ->where('status', true)
            ->when($bookingEngine, fn ($query) => $query->where('show_on_booking_engine', true))
            ->get();
    }

    /**
     * What a line of the add-on costs for a stay.
     *
     * @return array{unit_price: float, quantity: int, multiplier: int, amount: float, tax_rate: float, tax_amount: float, total: float}
     */
    public function price(Addon $addon, int $quantity, int $nights, int $guests, ?float $unitPrice = null): array
    {
        $nights = max(1, $nights);
        $guests = max(1, $guests);
        $multiplier = match ($addon->pricing) {
            'per_night' => $nights,
            'per_guest' => $guests,
            'per_guest_per_night' => $guests * $nights,
            default => 1,
        };
        $unitPrice = round($unitPrice ?? (float) $addon->price, 2);
        $amount = round($unitPrice * $quantity * $multiplier, 2);
        $taxRate = (float) $addon->tax_rate;
        $tax = round($amount * $taxRate / 100, 2);

        return [
            'unit_price' => $unitPrice,
            'quantity' => $quantity,
            'multiplier' => $multiplier,
            'amount' => $amount,
            'tax_rate' => $taxRate,
            'tax_amount' => $tax,
            'total' => round($amount + $tax, 2),
        ];
    }

    /**
     * The add-ons' total for a stay not yet booked (the booking engine's checkout).
     *
     * @param  list<array{addon_id: int|string, quantity?: int|string|null}>  $lines
     */
    public function quote(Property $property, array $lines, int $nights, int $guests, bool $bookingEngine = false): float
    {
        $catalogue = $this->catalogue($property, $bookingEngine)->keyBy('id');

        return round(collect($this->normalise($lines))->sum(function (array $line) use ($catalogue, $nights, $guests): float {
            $addon = $catalogue->get($line['addon_id']);

            return $addon ? $this->price($addon, $line['quantity'], $nights, $guests)['total'] : 0.0;
        }), 2);
    }

    /**
     * Sets the booking's add-ons to these lines (one per add-on): new ones are added from the
     * catalogue, kept ones repriced for the stay as it is now (at the price they were sold
     * at), and missing ones removed.
     *
     * @param  list<array{addon_id: int|string, quantity?: int|string|null}>  $lines
     *
     * @throws ValidationException when an add-on isn't sold by the property
     */
    public function sync(Booking $booking, array $lines, string $from = 'front_desk', ?User $user = null, bool $bookingEngine = false): void
    {
        $lines = $this->normalise($lines);
        $catalogue = Addon::query()->where('property_id', $booking->property_id)->whereIn('id', array_column($lines, 'addon_id'))->get()->keyBy('id');

        DB::transaction(function () use ($booking, $lines, $catalogue, $from, $user, $bookingEngine): void {
            // Early check-in / late check-out charges aren't catalogue lines: they stay as they are.
            $existing = $booking->addons()->whereNotIn('added_from', BookingAddon::STAY_TIME_SOURCES)->get()->keyBy('addon_id');
            [$nights, $guests] = $this->stayOf($booking);

            foreach ($lines as $index => $line) {
                $addon = $catalogue->get($line['addon_id']);
                $current = $existing->get($line['addon_id']);

                if ($addon === null || ($current === null && (! $addon->status || ($bookingEngine && ! $addon->show_on_booking_engine)))) {
                    throw ValidationException::withMessages(["addons.{$index}.addon_id" => 'This add-on is not available.']);
                }

                if ($current !== null) {
                    $current->update($this->price($addon, $line['quantity'], $nights, $guests, (float) $current->unit_price));

                    continue;
                }

                $booking->addons()->create([
                    ...$this->price($addon, $line['quantity'], $nights, $guests),
                    'addon_id' => $addon->id,
                    'name' => $addon->name,
                    'pricing' => $addon->pricing,
                    'sac_hsn_code' => $addon->sac_hsn_code,
                    'added_from' => $from,
                    'created_by' => $user?->id,
                ]);
            }

            $kept = array_column($lines, 'addon_id');
            $existing->reject(fn (BookingAddon $line): bool => in_array((int) $line->addon_id, $kept, true))->each->delete();

            $this->refreshTotal($booking);
        });
    }

    /**
     * Adds one add-on (or more of one already on the booking).
     */
    public function add(Booking $booking, Addon $addon, int $quantity, ?User $user = null): BookingAddon
    {
        abort_unless($addon->property_id === $booking->property_id, 404);

        $lines = $booking->addons()->whereNotIn('added_from', BookingAddon::STAY_TIME_SOURCES)->whereNotNull('addon_id')->get()->mapWithKeys(fn (BookingAddon $line): array => [$line->addon_id => ['addon_id' => $line->addon_id, 'quantity' => $line->quantity]])->all();
        $lines[$addon->id] = ['addon_id' => $addon->id, 'quantity' => ($lines[$addon->id]['quantity'] ?? 0) + $quantity];

        $this->sync($booking, array_values($lines), 'front_desk', $user);

        return $booking->addons()->where('addon_id', $addon->id)->firstOrFail();
    }

    public function remove(BookingAddon $line): void
    {
        DB::transaction(function () use ($line): void {
            $line->delete();
            $this->refreshTotal($line->booking);
        });
    }

    /**
     * Stores the add-ons total and has the ledger follow.
     */
    public function refreshTotal(Booking $booking): void
    {
        $booking->forceFill(['addons_total' => round((float) $booking->addons()->sum('total'), 2)])->save();
        $this->ledgerSync->schedule($booking->id);
    }

    /**
     * @return array{0: int, 1: int} nights and guests (adults and children)
     */
    private function stayOf(Booking $booking): array
    {
        $rooms = $booking->rooms()->get(['adults', 'children']);

        return [
            BookingPricing::nights($booking->check_in->toDateString(), $booking->check_out->toDateString()),
            (int) $rooms->sum(fn ($room): int => (int) $room->adults + (int) $room->children),
        ];
    }

    /**
     * One line per add-on with a quantity of at least 1.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return list<array{addon_id: int, quantity: int}>
     */
    private function normalise(array $lines): array
    {
        return array_values(collect($lines)
            ->map(fn (array $line): array => ['addon_id' => (int) $line['addon_id'], 'quantity' => max(1, (int) ($line['quantity'] ?? 1))])
            ->unique('addon_id')
            ->all());
    }
}
