<?php

namespace App\Http\Controllers;

use App\Models\Currency;
use App\Models\OutletGuestOrder;
use App\Models\OutletItem;
use App\Models\OutletTable;
use App\Services\OutletBills;
use App\Services\OutletGuestOrders;
use App\Services\OutletPricing;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Ordering from a table's QR code: the outlet's menu with today's prices, a basket, and the
 * order sent to the POS for the staff to confirm. The URL carries only the table's random token.
 */
class TableOrderController extends Controller
{
    public function show(Request $request, OutletTable $outletTable, OutletPricing $pricing, OutletBills $bills): Response
    {
        $outletTable->load('outlet.property.currency');
        $outlet = $outletTable->outlet;
        abort_unless($outlet->status && $outletTable->status, 404);
        $businessDate = $bills->businessDate($outlet->property);

        $items = $outlet->items()
            ->where('status', true)
            ->with(['displayCategory', 'taxRate'])
            ->get()
            ->map(fn (OutletItem $item): array => [
                'id' => $item->id,
                'name' => $item->name,
                'category' => $item->displayCategory->name ?? 'Others',
                'category_order' => $item->displayCategory->display_order ?? PHP_INT_MAX,
                'price' => $pricing->priceFor($item, $businessDate),
                'tax_rate' => $item->taxRate && $item->taxRate->status ? (float) $item->taxRate->rate : 0.0,
            ])
            ->filter(fn (array $item): bool => $item['price'] !== null)
            ->sortBy('category_order')
            ->values();

        // This browser's orders for the table, so the guest sees whether they were confirmed.
        $mine = array_map('intval', (array) $request->session()->get("table-orders.{$outletTable->id}", []));

        return Inertia::render('TableOrder/Show', [
            'token' => $outletTable->qr_token,
            'outlet' => ['name' => $outlet->name, 'property' => $outlet->property->name],
            'table' => $outletTable->name,
            'ordering' => (bool) $outlet->qr_ordering,
            'currency' => Currency::forProperty($outlet->property)['symbol'],
            'items' => $items,
            'orders' => OutletGuestOrder::query()->whereIn('id', $mine)->latest('id')->limit(10)->get()
                ->map(fn (OutletGuestOrder $order): array => ['id' => $order->id, 'status' => $order->status, 'total' => (float) $order->total, 'items' => $order->items, 'reject_reason' => $order->reject_reason]),
        ]);
    }

    public function store(Request $request, OutletTable $outletTable, OutletGuestOrders $orders): RedirectResponse
    {
        $data = $request->validate([
            'guest_name' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:500'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.outlet_item_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:20'],
        ]);

        $order = $orders->place($outletTable, array_values($data['items']), $data['guest_name'] ?? null, $data['notes'] ?? null, $request->ip());
        $request->session()->push("table-orders.{$outletTable->id}", $order->id);

        return back()->with('success', 'Your order has been sent. The staff will confirm it shortly.');
    }
}
