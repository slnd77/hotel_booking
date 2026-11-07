<?php

namespace App\Services;

use App\Enums\OutletBillStatus;
use App\Models\OutletBill;
use App\Models\OutletGuestOrder;
use App\Models\OutletItem;
use App\Models\OutletKot;
use App\Models\OutletTable;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Ordering from a table's QR code: the guest picks items from the outlet's menu (priced here,
 * never by the page) and sends the order; it waits on the POS until staff accept it — its items
 * go on the table's open bill (a new one when there is none) and, if asked, to the kitchen — or
 * reject it.
 */
class OutletGuestOrders
{
    /** Orders a table may have waiting at once: more are refused (a guard against spam). */
    public const MAX_PENDING_PER_TABLE = 5;

    public function __construct(private OutletBills $bills, private OutletPricing $pricing) {}

    /**
     * @param  list<array{outlet_item_id: int, quantity: int}>  $lines
     *
     * @throws ValidationException
     */
    public function place(OutletTable $table, array $lines, ?string $guestName, ?string $notes, ?string $ip): OutletGuestOrder
    {
        $table->loadMissing('outlet.property');
        $outlet = $table->outlet;

        if (! $outlet->status || ! $outlet->qr_ordering || ! $table->status) {
            throw ValidationException::withMessages(['items' => "{$outlet->name} isn't taking orders from the table right now. Please ask the staff."]);
        }

        if (OutletGuestOrder::query()->where('outlet_table_id', $table->id)->where('status', OutletGuestOrder::PENDING)->count() >= self::MAX_PENDING_PER_TABLE) {
            throw ValidationException::withMessages(['items' => 'This table already has orders waiting for the staff. Please wait for them to be confirmed.']);
        }

        $businessDate = $this->bills->businessDate($outlet->property);
        $catalogue = OutletItem::query()->where('outlet_id', $outlet->id)->where('status', true)->whereIn('id', array_column($lines, 'outlet_item_id'))->get()->keyBy('id');
        $items = [];

        foreach (array_values($lines) as $index => $line) {
            $item = $catalogue->get((int) $line['outlet_item_id']);
            $price = $item ? $this->pricing->priceFor($item, $businessDate) : null;

            if ($item === null || $price === null) {
                throw ValidationException::withMessages(["items.{$index}.outlet_item_id" => 'This item is not available today.']);
            }

            $items[] = ['outlet_item_id' => $item->id, 'name' => $item->name, 'quantity' => (int) $line['quantity'], 'unit_price' => $price];
        }

        return OutletGuestOrder::query()->create([
            'property_id' => $outlet->property_id,
            'outlet_id' => $outlet->id,
            'outlet_table_id' => $table->id,
            'status' => OutletGuestOrder::PENDING,
            'guest_name' => $guestName,
            'notes' => $notes,
            'items' => $items,
            'total' => round(array_sum(array_map(fn (array $line): float => $line['unit_price'] * $line['quantity'], $items)), 2),
            'ip_address' => $ip,
        ]);
    }

    /**
     * Adds the order's items to the table's open bill (opening one when there is none) at
     * today's prices, and sends them to the kitchen when asked.
     *
     * @return array{bill: OutletBill, kot: OutletKot|null}
     */
    public function accept(OutletGuestOrder $order, ?User $user, bool $sendToKitchen): array
    {
        $this->assertPending($order);

        return DB::transaction(function () use ($order, $user, $sendToKitchen): array {
            $order->loadMissing(['outlet', 'table']);
            $bill = OutletBill::query()
                ->where('outlet_id', $order->outlet_id)
                ->where('status', OutletBillStatus::Open)
                ->when($order->outlet_table_id, fn ($query) => $query->where('outlet_table_id', $order->outlet_table_id))
                ->latest('id')
                ->first()
                ?? $this->bills->open($order->outlet, $user, ['guest_name' => $order->guest_name, 'outlet_table_id' => $order->outlet_table_id, 'notes' => 'QR order']);

            // The bill's lines (at their prices) plus the order's (repriced for today).
            $lines = $bill->items()->get()->map(fn ($line): array => ['outlet_item_id' => $line->outlet_item_id, 'quantity' => (int) $line->quantity, 'unit_price' => (float) $line->unit_price])->all();

            foreach ($order->items as $line) {
                $lines[] = ['outlet_item_id' => (int) $line['outlet_item_id'], 'quantity' => (int) $line['quantity']];
            }

            $bill = $this->bills->setItems($bill, $lines, (float) $bill->discount, mayOverridePrice: true);
            $kot = $sendToKitchen ? $this->bills->sendKot($bill, $user, $order->notes ? "QR order: {$order->notes}" : 'QR order') : null;

            $order->update(['status' => OutletGuestOrder::ACCEPTED, 'outlet_bill_id' => $bill->id, 'handled_by' => $user?->id, 'handled_at' => now()]);

            return ['bill' => $bill, 'kot' => $kot];
        });
    }

    public function reject(OutletGuestOrder $order, ?User $user, string $reason): OutletGuestOrder
    {
        $this->assertPending($order);
        $order->update(['status' => OutletGuestOrder::REJECTED, 'reject_reason' => $reason, 'handled_by' => $user?->id, 'handled_at' => now()]);

        return $order;
    }

    private function assertPending(OutletGuestOrder $order): void
    {
        if ($order->status !== OutletGuestOrder::PENDING) {
            throw ValidationException::withMessages(['order' => 'This order was already '.$order->status.'.']);
        }
    }
}
