<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PaymentMode;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\PaymentResource;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Property;
use App\Services\PaymentReceipts;
use App\Services\PermissionGate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Receipts and refunds of a property. Recording one works like Databases → Payments → Add
 * Payment: tagged to bookings (optionally split over their folio windows), and the ledger
 * credits the folios. Cancelling reverses the credit.
 */
class PaymentController extends Controller
{
    public function index(Request $request, Property $property): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'type' => ['nullable', 'string', 'in:receipt,refund'],
            'status' => ['nullable', 'string', Rule::in(array_column(PaymentStatus::cases(), 'value'))],
            'booking_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'updated_since' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $payments = Payment::query()
            ->where('property_id', $property->id)
            ->when($filters['type'] ?? null, fn (Builder $query, string $type) => $query->where('type', $type))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['booking_id'] ?? null, fn (Builder $query, int|string $bookingId) => $query->where(fn (Builder $query) => $query
                ->where('booking_id', $bookingId)
                ->orWhereHas('bookings', fn (Builder $query) => $query->whereKey($bookingId))))
            // Payment dates are instants; from/to are the property's calendar days.
            ->when($filters['from'] ?? null, fn (Builder $query, string $from) => $query->where('payment_transaction_date', '>=', CarbonImmutable::parse($from, $property->timezoneName())->startOfDay()->utc()))
            ->when($filters['to'] ?? null, fn (Builder $query, string $to) => $query->where('payment_transaction_date', '<=', CarbonImmutable::parse($to, $property->timezoneName())->endOfDay()->utc()))
            ->when($filters['updated_since'] ?? null, fn (Builder $query, string $since) => $query->where('updated_at', '>=', CarbonImmutable::parse($since)->utc()))
            ->with('bookings:id,booking_ref_id')
            ->latest('id')
            ->paginate($filters['per_page'] ?? 25)
            ->withQueryString();

        return PaymentResource::collection($payments);
    }

    public function show(Property $property, Payment $payment): PaymentResource
    {
        abort_unless($payment->property_id === $property->id, 404);

        return new PaymentResource($payment->load('bookings:id,booking_ref_id'));
    }

    /**
     * Records a receipt or refund. Tag it to one booking (booking_id, tagged_amount) or several
     * (allocations), optionally naming the folio windows each booking's share settles.
     */
    public function store(Request $request, Property $property, PaymentReceipts $receipts): JsonResponse
    {
        $bookingRule = Rule::exists(Booking::class, 'id')->where('property_id', $property->id);

        $data = $request->validate([
            'booking_id' => ['nullable', 'integer', $bookingRule],
            'type' => ['nullable', 'string', 'in:receipt,refund'],
            'guest_name' => ['required', 'string', 'max:255'],
            'payment_mode' => ['required', 'string', Rule::in(array_column(PaymentMode::cases(), 'value'))],
            'paid_by' => ['nullable', 'string', 'max:100'],
            'total_txn_amount' => ['required_without:currency_code', 'nullable', 'numeric', 'min:0.01'],
            // Paid in another currency: the amount in it; the server converts it at that day's rate.
            'currency_code' => ['nullable', 'string', 'size:3'],
            'currency_amount' => ['required_with:currency_code', 'nullable', 'numeric', 'min:0.01'],
            'tagged_amount' => ['nullable', 'numeric', 'min:0', ...($request->filled('currency_code') ? [] : ['lte:total_txn_amount'])],
            'allocations' => ['nullable', 'array', 'max:50'],
            'allocations.*.booking_id' => ['required', 'integer', $bookingRule],
            'allocations.*.tagged_amount' => ['required', 'numeric', 'min:0'],
            'allocations.*.windows' => ['nullable', 'array', 'max:50'],
            'allocations.*.windows.*.sub_folio_id' => ['required', 'integer'],
            'allocations.*.windows.*.amount' => ['required', 'numeric', 'min:0'],
            'payment_transaction_date' => ['nullable', 'date'],
            'voucher_number' => ['nullable', 'string', 'max:255'],
            'payment_ref_id' => ['nullable', 'string', 'max:255'],
            'pg_txn_id' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'booking_source' => ['nullable', 'string', 'max:100'],
        ]);

        $tagged = round((float) collect($data['allocations'] ?? [])->sum('tagged_amount'), 2);
        abort_if(! filled($data['currency_code'] ?? null) && $tagged > round((float) $data['total_txn_amount'], 2), 422, 'The allocations add up to more than the payment.');

        $payment = $receipts->record($property->id, [...$data, 'collection_source' => 'API'], $request->user()?->name);

        return (new PaymentResource($payment->load('bookings:id,booking_ref_id')))->response()->setStatusCode(201);
    }

    public function cancel(Request $request, Property $property, Payment $payment): PaymentResource
    {
        abort_unless($payment->property_id === $property->id, 404);

        $user = $request->user();
        $gate = app(PermissionGate::class);
        abort_unless($gate->allows($user, 'database.payments.cancel') || $gate->allows($user, 'reservation.cancel_payment'), 403, "You don't have permission to cancel a payment.");
        abort_if($payment->status === PaymentStatus::Cancelled, 422, "Payment {$payment->receipt_number} is already cancelled.");

        $data = $request->validate(['cancellation_reason' => ['required', 'string', 'max:500']]);

        // The ledger reverses the receipt's credit, so what it paid is owed again.
        $payment->update(['status' => PaymentStatus::Cancelled, 'cancellation_reason' => $data['cancellation_reason']]);

        return new PaymentResource($payment->load('bookings:id,booking_ref_id'));
    }
}
