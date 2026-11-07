<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OutletBillStatus;
use App\Http\Controllers\Concerns\HandlesOutletBills;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\OutletBillResource;
use App\Models\Outlet;
use App\Models\OutletBill;
use App\Models\OutletItem;
use App\Models\OutletKot;
use App\Models\Property;
use App\Services\OutletBills;
use App\Services\OutletPricing;
use App\Services\PosSalesReport;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * POS over the API: the outlets with their items and today's prices, and bills — open one,
 * set its items, send them to the kitchen, post it to a checked-in room's folio (with an
 * optional payment), settle it with a payment, or void it; print bills and kitchen tickets;
 * and the POS sales summary.
 */
class PosController extends Controller
{
    use HandlesOutletBills;

    public function outlets(Property $property, OutletPricing $pricing, OutletBills $outletBills): JsonResponse
    {
        $today = $outletBills->businessDate($property);

        return response()->json([
            'business_date' => $today,
            'data' => $property->outlets()->where('status', true)->with(['items' => fn ($query) => $query->where('status', true)->with(['displayCategory:id,name', 'taxRate'])])->get()
                ->map(fn (Outlet $outlet): array => [
                    'id' => $outlet->id,
                    'name' => $outlet->name,
                    'type' => $outlet->type->value,
                    'items' => $outlet->items->map(fn (OutletItem $item): array => [
                        'id' => $item->id,
                        'name' => $item->name,
                        'category' => $item->displayCategory?->name,
                        'item_type' => $item->item_type,
                        'sac_hsn_code' => $item->sac_hsn_code,
                        // Null: not on any active price plan today, so it can't be billed.
                        'price' => $pricing->priceFor($item, $today),
                        'tax_rate' => $item->taxRate && $item->taxRate->status ? (float) $item->taxRate->rate : 0.0,
                    ])->values(),
                ])->values(),
        ]);
    }

    public function index(Request $request, Property $property): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'outlet_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'string', Rule::in(array_column(OutletBillStatus::cases(), 'value'))],
            'business_date' => ['nullable', 'date_format:Y-m-d'],
            'booking_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $bills = $property->outletBills()
            ->when($filters['outlet_id'] ?? null, fn (Builder $query, int|string $outletId) => $query->where('outlet_id', $outletId))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['business_date'] ?? null, fn (Builder $query, string $date) => $query->whereDate('business_date', $date))
            ->when($filters['booking_id'] ?? null, fn (Builder $query, int|string $bookingId) => $query->where('booking_id', $bookingId))
            ->with(['outlet:id,name', 'items', 'kots.items', 'booking:id,booking_ref_id', 'payment:id,receipt_number'])
            ->latest('id')
            ->paginate($filters['per_page'] ?? 25)
            ->withQueryString();

        return OutletBillResource::collection($bills);
    }

    public function show(Property $property, OutletBill $outletBill): OutletBillResource
    {
        return $this->resource($outletBill);
    }

    public function store(Request $request, Property $property, Outlet $outlet): JsonResponse
    {
        return $this->resource($this->openBill($request, $outlet))->response()->setStatusCode(201);
    }

    public function update(Request $request, Property $property, OutletBill $outletBill): OutletBillResource
    {
        return $this->resource($this->updateBill($request, $outletBill));
    }

    public function postToRoom(Request $request, Property $property, OutletBill $outletBill): OutletBillResource
    {
        return $this->resource($this->postBillToRoom($request, $property, $outletBill));
    }

    /**
     * Settles the bill; its receipt prints on the outlet's network printer when it has one
     * (print_error says why it didn't).
     */
    public function settle(Request $request, Property $property, OutletBill $outletBill): JsonResponse
    {
        $bill = $this->settleBill($request, $outletBill);
        $printError = $this->printReceiptAfterSettle($bill);

        return $this->resource($bill)->additional(['print_error' => $printError])->response();
    }

    /**
     * Opens the outlet's cash drawer without a sale: on its network printer, or returns the
     * ESC/POS drawer kick (?format=escpos) for a printer the client drives itself.
     */
    public function openDrawer(Request $request, Property $property, Outlet $outlet): JsonResponse|Response
    {
        return $this->openCashDrawer($request, $outlet) ?? response()->json(['message' => "{$outlet->name}'s cash drawer opened."]);
    }

    public function void(Request $request, Property $property, OutletBill $outletBill): OutletBillResource
    {
        return $this->resource($this->voidBill($request, $outletBill));
    }

    /**
     * Sends the bill's new items to the kitchen on a ticket (printed on the kitchen printer
     * when the outlet has one).
     */
    public function sendKot(Request $request, Property $property, OutletBill $outletBill): JsonResponse
    {
        $result = $this->sendBillToKitchen($request, $outletBill);

        return response()->json([
            'data' => $this->resource($outletBill)->resolve($request),
            'kot_id' => $result['kot']->id,
            'printed' => $result['printed'],
            'print_error' => $result['print_error'],
        ], 201);
    }

    /**
     * The bill printed: an HTML page for its paper, or ESC/POS bytes (?format=escpos) for a
     * thermal printer (?paper=thermal_80|thermal_58).
     */
    public function printBill(Request $request, Property $property, OutletBill $outletBill): Response
    {
        return $this->billPrintout($request, $outletBill);
    }

    public function sendBillToPrinter(Request $request, Property $property, OutletBill $outletBill): JsonResponse
    {
        $this->printBillOnPrinter($request, $outletBill);

        return response()->json(['message' => "Bill #{$outletBill->bill_number} sent to the printer."]);
    }

    public function printKot(Request $request, Property $property, OutletKot $outletKot): Response
    {
        return $this->kotPrintout($request, $outletKot);
    }

    public function sendKotToPrinter(Property $property, OutletKot $outletKot): JsonResponse
    {
        $this->printKotOnPrinter($outletKot);

        return response()->json(['message' => "KOT #{$outletKot->kot_number} sent to the kitchen printer."]);
    }

    /**
     * POS sales over a range of business dates (at most PosSalesReport::MAX_DAYS).
     */
    public function summary(Request $request, Property $property, PosSalesReport $report, OutletBills $outletBills): JsonResponse
    {
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'outlet_id' => ['nullable', 'integer', Rule::exists(Outlet::class, 'id')->where('property_id', $property->id)],
        ]);
        $today = $outletBills->businessDate($property);
        $from = CarbonImmutable::parse($data['from'] ?? $today);
        $to = CarbonImmutable::parse($data['to'] ?? $data['from'] ?? $today);

        if ($from->diffInDays($to) >= PosSalesReport::MAX_DAYS) {
            throw ValidationException::withMessages(['to' => 'Pick a range of '.PosSalesReport::MAX_DAYS.' days or fewer.']);
        }

        return response()->json(['data' => $report->summary($property, $from, $to, isset($data['outlet_id']) ? (int) $data['outlet_id'] : null)]);
    }

    private function resource(OutletBill $bill): OutletBillResource
    {
        return new OutletBillResource($bill->load(['outlet:id,name', 'items', 'kots.items', 'booking:id,booking_ref_id', 'payment:id,receipt_number']));
    }
}
