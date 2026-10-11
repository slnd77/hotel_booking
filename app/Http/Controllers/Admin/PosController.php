<?php

namespace App\Http\Controllers\Admin;

use App\Enums\BookingStatus;
use App\Enums\OutletBillStatus;
use App\Enums\PaymentMode;
use App\Enums\SubBookingStatus;
use App\Http\Controllers\Concerns\HandlesOutletBills;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\OutletBillResource;
use App\Mail\OutletBillMail;
use App\Models\ActivityLog;
use App\Models\Guest;
use App\Models\Outlet;
use App\Models\OutletBill;
use App\Models\OutletGuestOrder;
use App\Models\OutletItem;
use App\Models\OutletKot;
use App\Models\OutletTable;
use App\Models\Property;
use App\Models\SubBooking;
use App\Services\BookingPrints;
use App\Services\OutletBills;
use App\Services\OutletGuestOrders;
use App\Services\OutletPricing;
use App\Services\PaymentDistributionService;
use App\Services\PosPrints;
use App\Services\PosSalesReport;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\LaravelPdf\PdfBuilder;

/**
 * POS: bill an outlet's items, send them to the kitchen, then charge the bill to an in-house
 * room's folio (optionally taking a payment there too), settle it at the outlet (in one payment
 * or split between payers), or void it. Bills can be split and moved between the tables of the
 * outlet's floor plan, and orders guests place from a table's QR code are accepted here.
 * Bills and kitchen tickets print from the browser or on the outlet's network printers.
 */
class PosController extends Controller
{
    use HandlesOutletBills;

    public function index(): Response
    {
        return Inertia::render('admin/pos/Index');
    }

    public function show(Request $request, Property $property, OutletPricing $pricing, PaymentDistributionService $windows, OutletBills $outletBills): Response
    {
        $outlets = $property->outlets()->where('status', true)->get();
        $outlet = $outlets->firstWhere('id', $request->integer('outlet')) ?? $outlets->first();
        $businessDate = $outletBills->businessDate($property);

        $bills = $outlet
            ? OutletBill::query()
                ->where('outlet_id', $outlet->id)
                ->where(fn ($query) => $query->where('status', OutletBillStatus::Open)->orWhereDate('business_date', $businessDate))
                ->with(['items', 'kots.items', 'booking:id,booking_ref_id', 'payment:id,receipt_number'])
                ->latest('id')
                ->limit(100)
                ->get()
            : collect();

        return Inertia::render('admin/pos/Show', [
            'property' => $property->only(['id', 'name']),
            'businessDate' => $businessDate,
            'outlets' => $outlets->map->only(['id', 'name'])->values(),
            'outletId' => $outlet?->id,
            'printers' => $outlet ? [
                'paper' => $outlet->printer_setting,
                'network' => filled($outlet->printer_host),
                'cash_drawer' => $outlet->cash_drawer,
                'kot_paper' => $outlet->kot_printer_setting,
                'kot_network' => filled($outlet->kot_printer_host),
            ] : null,
            'items' => $outlet ? $outlet->items()->where('status', true)->with(['displayCategory:id,name', 'taxRate'])->get()->map(fn (OutletItem $item): array => [
                'id' => $item->id,
                'name' => $item->name,
                'category' => $item->displayCategory->name ?? 'Others',
                'price' => $pricing->priceFor($item, $businessDate),
                'tax_rate' => $item->taxRate && $item->taxRate->status ? (float) $item->taxRate->rate : 0.0,
            ])->values() : [],
            'bills' => OutletBillResource::collection($bills)->resolve(),
            'inHouseRooms' => $this->inHouseRooms($property, $windows),
            'paymentModes' => array_map(fn (PaymentMode $mode): string => $mode->value, PaymentMode::cases()),
            // The floor plan: each table with its open bill(s).
            'tables' => $outlet ? $outlet->tables()->where('status', true)->get()->map(fn (OutletTable $table): array => [
                'id' => $table->id,
                'name' => $table->name,
                'seats' => $table->seats,
                'area' => $table->area,
                'pos_x' => $table->pos_x,
                'pos_y' => $table->pos_y,
                'shape' => $table->shape,
                'open_bill_ids' => $bills->where('status', OutletBillStatus::Open)->where('outlet_table_id', $table->id)->pluck('id')->values(),
            ])->values() : [],
            // Orders from the tables' QR codes, waiting for the staff.
            'guestOrders' => $outlet ? OutletGuestOrder::query()->where('outlet_id', $outlet->id)->where('status', OutletGuestOrder::PENDING)->with('table:id,name')->oldest('id')->get()
                ->map(fn (OutletGuestOrder $order): array => [
                    'id' => $order->id,
                    'table' => $order->table?->name,
                    'guest_name' => $order->guest_name,
                    'notes' => $order->notes,
                    'items' => $order->items,
                    'total' => (float) $order->total,
                    'created_at' => $order->created_at?->toIso8601String(),
                ])->values() : [],
            'qrOrdering' => (bool) $outlet?->qr_ordering,
        ]);
    }

    /**
     * Splits an open bill: the chosen quantities move to a new bill for the same table.
     */
    public function split(Request $request, Property $property, OutletBill $outletBill): RedirectResponse
    {
        $new = $this->splitBill($request, $outletBill);

        return $this->backToOutlet($property, $new, "Bill #{$outletBill->bill_number} split: the moved items are on bill #{$new->bill_number}.");
    }

    /**
     * Moves an open bill to another table (or off the plan).
     */
    public function moveTable(Request $request, Property $property, OutletBill $outletBill, OutletBills $bills): RedirectResponse
    {
        $data = $request->validate(['outlet_table_id' => ['nullable', 'integer', Rule::exists(OutletTable::class, 'id')->where('outlet_id', $outletBill->outlet_id)]]);
        $table = isset($data['outlet_table_id']) ? OutletTable::query()->findOrFail($request->integer('outlet_table_id')) : null;
        $bill = $bills->moveToTable($outletBill, $table);

        return $this->backToOutlet($property, $bill, "Bill #{$bill->bill_number} moved to ".($table ? "table {$table->name}." : 'no table.'));
    }

    /**
     * Accepts a QR order: its items go on the table's open bill, and to the kitchen if asked.
     */
    public function acceptGuestOrder(Request $request, Property $property, OutletGuestOrder $outletGuestOrder, OutletGuestOrders $orders): RedirectResponse
    {
        $sendToKitchen = $request->boolean('send_kot');
        $result = $orders->accept($outletGuestOrder, $request->user(), $sendToKitchen);
        $printError = $result['kot'] ? $this->printOnKitchenPrinter($result['kot'])['print_error'] : null;
        $redirect = $this->backToOutlet($property, $result['bill'], "Order from table {$outletGuestOrder->table?->name} added to bill #{$result['bill']->bill_number}".($result['kot'] ? " and sent to the kitchen (KOT #{$result['kot']->kot_number})." : '.'));

        return $printError ? $redirect->with('error', "{$printError} Print the KOT from the browser.") : $redirect;
    }

    public function rejectGuestOrder(Request $request, Property $property, OutletGuestOrder $outletGuestOrder, OutletGuestOrders $orders): RedirectResponse
    {
        $reason = $request->validate(['reason' => ['required', 'string', 'max:255']])['reason'];
        $orders->reject($outletGuestOrder, $request->user(), $reason);

        return redirect()->route('admin.pos.show', [$property, 'outlet' => $outletGuestOrder->outlet_id])->with('success', 'Order rejected.');
    }

    public function store(Request $request, Property $property, Outlet $outlet): RedirectResponse
    {
        $bill = $this->openBill($request, $outlet);

        return $this->backToOutlet($property, $bill, "Bill #{$bill->bill_number} opened.");
    }

    public function update(Request $request, Property $property, OutletBill $outletBill): RedirectResponse
    {
        $bill = $this->updateBill($request, $outletBill);

        return $this->backToOutlet($property, $bill, "Bill #{$bill->bill_number} saved.");
    }

    public function postToRoom(Request $request, Property $property, OutletBill $outletBill): RedirectResponse
    {
        $bill = $this->postBillToRoom($request, $property, $outletBill);

        return $this->backToOutlet($property, $bill, "Bill #{$bill->bill_number} posted to the room's folio.");
    }

    public function settle(Request $request, Property $property, OutletBill $outletBill): RedirectResponse
    {
        $bill = $this->settleBill($request, $outletBill);
        $printError = $this->printReceiptAfterSettle($bill);
        $redirect = $this->backToOutlet($property, $bill, "Bill #{$bill->bill_number} settled.");

        return $printError ? $redirect->with('error', "{$printError} Print the receipt from the browser.") : $redirect;
    }

    /**
     * "Open drawer" without a sale, on the outlet's network printer.
     */
    public function openDrawer(Request $request, Property $property, Outlet $outlet): RedirectResponse
    {
        $this->openCashDrawer($request, $outlet);

        return redirect()->route('admin.pos.show', [$property, 'outlet' => $outlet->id])->with('success', "{$outlet->name}'s cash drawer opened.");
    }

    /**
     * "Open drawer" without a sale, as the ESC/POS drawer kick for a printer connected to this browser.
     */
    public function drawerKick(Request $request, Property $property, Outlet $outlet): HttpResponse
    {
        $request->merge(['format' => 'escpos']);

        return $this->openCashDrawer($request, $outlet);
    }

    public function void(Request $request, Property $property, OutletBill $outletBill): RedirectResponse
    {
        $bill = $this->voidBill($request, $outletBill);

        return $this->backToOutlet($property, $bill, "Bill #{$bill->bill_number} voided.");
    }

    /**
     * "KOT": sends the bill's new items to the kitchen.
     */
    public function sendKot(Request $request, Property $property, OutletBill $outletBill): RedirectResponse
    {
        $result = $this->sendBillToKitchen($request, $outletBill);
        $message = "KOT #{$result['kot']->kot_number} sent to the kitchen".($result['printed'] ? ' and printed.' : '.');
        $redirect = $this->backToOutlet($property, $outletBill, $message);

        return $result['print_error'] ? $redirect->with('error', "{$result['print_error']} Print the KOT from the browser.") : $redirect;
    }

    /**
     * One bill as JSON, for the bill modal (from a folio entry or the POS): what it prints,
     * plus where it can be e-mailed by default.
     */
    public function showBill(Property $property, OutletBill $outletBill, PosPrints $prints, BookingPrints $bookingPrints): JsonResponse
    {
        $outletBill->loadMissing('booking.guests');

        return response()->json([
            'id' => $outletBill->id,
            'status' => $outletBill->status->value,
            'status_label' => $outletBill->status->label(),
            'data' => $prints->billData($outletBill),
            'guest_email' => $bookingPrints->guest($outletBill->booking?->guests->first())['email'] ?? null,
        ]);
    }

    public function billPdf(Property $property, OutletBill $outletBill, PosPrints $prints): PdfBuilder
    {
        return $prints->billPdf($outletBill)->download();
    }

    /**
     * E-mails the bill (PDF attached) through the queue, and logs it.
     */
    public function emailBill(Request $request, Property $property, OutletBill $outletBill): RedirectResponse
    {
        $email = $request->validate(['email' => ['required', 'email:rfc', 'max:255']])['email'];

        Mail::to($email)->queue(new OutletBillMail($outletBill));
        ActivityLog::create([
            'user_id' => $request->user()->id,
            'property_id' => $property->id,
            'booking_id' => $outletBill->booking_id,
            'guest_id' => $outletBill->booking?->guest_id,
            'action' => "POS bill {$outletBill->outlet->name} #{$outletBill->bill_number} e-mailed to {$email}.",
            'date' => now(),
        ]);

        return back()->with('success', "Bill #{$outletBill->bill_number} will be e-mailed to {$email}.");
    }

    public function printBill(Request $request, Property $property, OutletBill $outletBill): HttpResponse
    {
        return $this->billPrintout($request, $outletBill);
    }

    public function sendBillToPrinter(Request $request, Property $property, OutletBill $outletBill): RedirectResponse
    {
        $this->printBillOnPrinter($request, $outletBill);

        return $this->backToOutlet($property, $outletBill, "Bill #{$outletBill->bill_number} sent to the printer.");
    }

    public function printKot(Request $request, Property $property, OutletKot $outletKot): HttpResponse
    {
        return $this->kotPrintout($request, $outletKot);
    }

    public function sendKotToPrinter(Property $property, OutletKot $outletKot): RedirectResponse
    {
        $this->printKotOnPrinter($outletKot);

        return redirect()->route('admin.pos.show', [$property, 'outlet' => $outletKot->outlet_id])->with('success', "KOT #{$outletKot->kot_number} sent to the kitchen printer.");
    }

    /**
     * POS reports: the outlets' sales over a range of business dates.
     */
    public function report(Request $request, Property $property, PosSalesReport $report, OutletBills $outletBills): Response
    {
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'outlet' => ['nullable', 'integer'],
        ]);
        $today = $outletBills->businessDate($property);
        $from = CarbonImmutable::parse($data['from'] ?? $today);
        $to = CarbonImmutable::parse($data['to'] ?? $data['from'] ?? $today);

        if ($from->diffInDays($to) >= PosSalesReport::MAX_DAYS) {
            $to = $from->addDays(PosSalesReport::MAX_DAYS - 1);
        }

        $outletId = $property->outlets()->whereKey($data['outlet'] ?? 0)->value('id');

        return Inertia::render('admin/pos/Report', [
            'property' => $property->only(['id', 'name']),
            'outlets' => $property->outlets()->get(['id', 'name']),
            'filters' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'outlet' => $outletId],
            'maxDays' => PosSalesReport::MAX_DAYS,
            'summary' => $report->summary($property, $from, $to, $outletId),
        ]);
    }

    private function backToOutlet(Property $property, OutletBill $bill, string $message): RedirectResponse
    {
        return redirect()->route('admin.pos.show', [$property, 'outlet' => $bill->outlet_id])->with('success', $message);
    }

    /**
     * Checked-in rooms a bill can be charged to, with the booking's billing windows.
     *
     * @return list<array<string, mixed>>
     */
    private function inHouseRooms(Property $property, PaymentDistributionService $windows): array
    {
        $rooms = SubBooking::query()
            ->where('status', SubBookingStatus::CheckedIn)
            ->whereHas('booking', fn ($query) => $query->where('property_id', $property->id)->where('status', BookingStatus::CheckedIn))
            ->with(['booking.guests', 'inventoryUnit:id,name', 'roomCategory:id,name'])
            ->orderBy('booking_id')
            ->orderBy('id')
            ->get();

        $windowsByBooking = $rooms->pluck('booking')->unique('id')->mapWithKeys(fn ($booking): array => [$booking->id => $windows->folioWindowsFor($booking)]);

        return array_values($rooms->map(function (SubBooking $room) use ($windowsByBooking): array {
            $guest = $room->booking->guests->first();

            return [
                'sub_booking_id' => $room->id,
                'booking_id' => $room->booking_id,
                'booking_ref_id' => $room->booking->booking_ref_id,
                'room' => $room->inventoryUnit->name ?? 'Unassigned',
                'category' => $room->roomCategory?->name,
                'guest' => $guest instanceof Guest ? trim("{$guest->first_name} {$guest->last_name}") : null,
                'windows' => array_map(fn (array $window): array => ['sub_folio_id' => $window['sub_folio_id'], 'label' => $window['label']], $windowsByBooking[$room->booking_id]),
            ];
        })->all());
    }
}
