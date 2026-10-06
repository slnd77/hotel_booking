<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Enums\InvoiceStatus;
use App\Enums\OutletBillStatus;
use App\Enums\PaymentStatus;
use App\Enums\SubBookingStatus;
use App\Models\ActivityLog;
use App\Models\Booking;
use App\Models\Currency;
use App\Models\Invoice;
use App\Models\NightAudit;
use App\Models\OutletBill;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Room;
use App\Models\SubBooking;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Closes a property's business date: arrivals that never came become no-shows (charged
 * the cancellation policy's arrival-day penalty, the rest of their nights released),
 * guests past their departure are flagged as overstays, and the day-end report is kept.
 * With the POS roll-over flag on, outlets roll over with it: the day doesn't close while
 * one of its POS bills is still open.
 */
class NightAudits
{
    /** How many missed days one scheduler pass catches up. */
    private const MAX_CATCH_UP_DAYS = 31;

    public function __construct(
        private NightAuditSettings $settings,
        private BookingEngineService $bookingEngine,
        private BookingPricing $pricing,
        private RoomStays $roomStays,
        private BookingLedgerSync $ledgerSync,
        private PosSalesReport $posSales,
    ) {}

    /**
     * The scheduler's pass for one property: on Automated Night Audit, closes every
     * business date whose rollover time has passed.
     *
     * @return list<NightAudit>
     */
    public function runDue(Property $property, ?CarbonImmutable $now = null): array
    {
        if (! $this->settings->isAutomated($property)) {
            return [];
        }

        $now ??= CarbonImmutable::now();
        $audits = [];

        for ($day = 0; $day < self::MAX_CATCH_UP_DAYS; $day++) {
            $date = $this->settings->currentRolloverDate($property);

            if ($now->lt($this->settings->rolloverAt($property, $date))) {
                break;
            }

            $audit = $this->run($property, $date);
            $audits[] = $audit;

            if ($audit->status !== NightAudit::COMPLETED) {
                break;
            }
        }

        return $audits;
    }

    /**
     * "Pre-rollover Notification": within the configured minutes before an automatic
     * rollover, what the front desk should know — so arrivals can still be checked in
     * and payments reconciled before the day closes.
     *
     * @return array{property_id: int, property_name: string, business_date: string, rollover_at: string, pending_arrivals: int, unreconciled_payments: int|null, open_pos_bills: int|null}|null
     */
    public function upcomingRollover(Property $property, ?CarbonImmutable $now = null): ?array
    {
        $settings = $this->settings->get($property);
        $minutes = (int) ($settings['pre_rollover_notification'] ?? 0);

        if ($settings['rollover_type'] !== NightAuditSettings::TYPE_AUTOMATED || $minutes <= 0) {
            return null;
        }

        $now ??= CarbonImmutable::now();
        $date = $this->settings->currentRolloverDate($property);
        $rolloverAt = $this->settings->rolloverAt($property, $date);

        if ($now->lt($rolloverAt->subMinutes($minutes)) || $now->gte($rolloverAt)) {
            return null;
        }

        return [
            'property_id' => $property->id,
            'property_name' => $property->name,
            'business_date' => $date->toDateString(),
            'rollover_at' => $rolloverAt->toIso8601ZuluString(),
            'pending_arrivals' => SubBooking::query()
                ->where('status', SubBookingStatus::Booked)
                ->whereDate('check_in_date', '<=', $date)
                ->when($settings['room_rollover_start_date'], fn (Builder $query, string $start) => $query->whereDate('check_in_date', '>=', $start))
                ->whereHas('booking', fn (Builder $query) => $query->where('property_id', $property->id)->whereIn('status', [BookingStatus::Confirmed, BookingStatus::Pending, BookingStatus::CheckedIn]))
                ->count(),
            'unreconciled_payments' => $settings['payment_reconciliation'] ? $this->paymentsOf($property, $date)->whereNull('reconciled_at')->count() : null,
            'open_pos_bills' => $settings['pos_rollover'] ? $this->openPosBills($property, $date)->count() : null,
        ];
    }

    /**
     * The front desk closing the current business date by hand ("Night Audit Off", or
     * ahead of the automatic time). Today can be closed early; a future date can't.
     *
     * @throws ValidationException
     */
    public function runManually(Property $property, User $user): NightAudit
    {
        $date = $this->settings->currentRolloverDate($property);

        if ($date->toDateString() > $property->today()) {
            throw ValidationException::withMessages(['business_date' => "{$date->format('d/m/Y')} hasn't started yet at the property."]);
        }

        return $this->run($property, $date, $user);
    }

    /**
     * Closes business date D (idempotent: a closed date is returned as it is).
     */
    public function run(Property $property, CarbonImmutable $businessDate, ?User $by = null): NightAudit
    {
        $businessDate = $businessDate->startOfDay();
        $audit = NightAudit::query()->where('property_id', $property->id)->whereDate('business_date', $businessDate)->first()
            ?? NightAudit::query()->create(['property_id' => $property->id, 'business_date' => $businessDate->toDateString(), 'status' => NightAudit::FAILED]);

        if ($audit->status === NightAudit::COMPLETED) {
            return $audit;
        }

        $audit->update(['started_at' => now(), 'run_by' => $by?->id, 'error' => null]);

        try {
            $summary = DB::transaction(function () use ($property, $businessDate, $by): array {
                $this->ensureNoExcessPayments($property, $businessDate);
                $this->ensurePaymentsReconciled($property, $businessDate);
                $this->ensurePosBillsClosed($property, $businessDate);
                $this->ensureInvoicesValid($property, $businessDate);
                $ledgerWarnings = $this->resyncDriftedLedgers($property, $businessDate);

                $noShows = $this->markNoShows($property, $businessDate, $by);
                $overstays = $this->overstays($property, $businessDate);

                // The general ledger earns the room revenue of the night just closed.
                app(GeneralLedger::class)->recogniseRevenue($property, $businessDate);

                // Settled folios of stays that ended by the day just closed are locked.
                $foliosLocked = app(FolioSettlements::class)->lockForAudit($property, $businessDate);

                return [
                    'no_shows' => $noShows['stays'],
                    'overstays' => $overstays,
                    'folios_locked' => $foliosLocked,
                    'warnings' => [...$ledgerWarnings, ...$noShows['warnings']],
                    'report' => $this->report($property, $businessDate, $noShows['stays'], count($overstays)),
                ];
            });
        } catch (Throwable $exception) {
            $audit->update(['status' => NightAudit::FAILED, 'finished_at' => now(), 'error' => $exception instanceof ValidationException ? collect($exception->errors())->flatten()->implode(' ') : $exception->getMessage()]);

            if (! $exception instanceof ValidationException) {
                report($exception);
            }

            return $audit->fresh();
        }

        $audit->update(['status' => NightAudit::COMPLETED, 'finished_at' => now(), 'summary' => $summary]);
        $this->settings->advance($property, $businessDate);

        return $audit->fresh();
    }

    /**
     * "Validate Excess Payment": the day doesn't close while a booking staying that day
     * has been paid more than its bill (beyond the tolerance) — refund or re-tag first.
     *
     * @throws ValidationException
     */
    private function ensureNoExcessPayments(Property $property, CarbonImmutable $businessDate): void
    {
        $settings = $this->settings->get($property);

        if (! $settings['validate_excess_payment']) {
            return;
        }

        $tolerance = (float) $settings['excess_payment_tolerance'];
        $overpaid = Booking::query()
            ->where('property_id', $property->id)
            ->whereIn('status', [BookingStatus::Confirmed, BookingStatus::Pending, BookingStatus::CheckedIn, BookingStatus::CheckedOut])
            ->whereDate('check_in', '<=', $businessDate)
            ->whereDate('check_out', '>=', $businessDate)
            ->get()
            ->filter(fn (Booking $booking): bool => -$booking->outstandingBalance() > $tolerance)
            ->pluck('booking_ref_id');

        if ($overpaid->isNotEmpty()) {
            throw ValidationException::withMessages(['business_date' => 'Excess payment on booking(s) '.$overpaid->implode(', ').'. Refund or re-tag it before the night audit.']);
        }
    }

    /**
     * "Payment Reconciliation Flag": the day doesn't close until every payment received
     * on it has been checked and marked reconciled.
     *
     * @throws ValidationException
     */
    private function ensurePaymentsReconciled(Property $property, CarbonImmutable $businessDate): void
    {
        if (! $this->settings->get($property)['payment_reconciliation']) {
            return;
        }

        $unreconciled = $this->paymentsOf($property, $businessDate)->whereNull('reconciled_at')->count();

        if ($unreconciled > 0) {
            throw ValidationException::withMessages(['business_date' => "{$unreconciled} payment(s) of {$businessDate->format('d/m/Y')} are not reconciled yet."]);
        }
    }

    /**
     * "Pos Roll Over Flag": the day doesn't close while a POS bill of it (from the POS Rollover
     * Start Date on) is still open. Post it to a room, settle it or void it first.
     *
     * @throws ValidationException
     */
    private function ensurePosBillsClosed(Property $property, CarbonImmutable $businessDate): void
    {
        if (! $this->settings->get($property)['pos_rollover']) {
            return;
        }

        $open = $this->openPosBills($property, $businessDate)->with('outlet:id,name')->get();

        if ($open->isNotEmpty()) {
            $bills = $open->map(fn (OutletBill $bill): string => "{$bill->outlet->name} #{$bill->bill_number}")->implode(', ');

            throw ValidationException::withMessages(['business_date' => "POS bill(s) {$bills} are still open. Post, settle or void them before the night audit."]);
        }
    }

    /**
     * "Validate Invoice": the day doesn't close while a booking checked out on it (or since
     * the Invoice Start Date) has no invoice, or one whose amount no longer matches its bill.
     *
     * @throws ValidationException
     */
    private function ensureInvoicesValid(Property $property, CarbonImmutable $businessDate): void
    {
        if (! $this->settings->get($property)['validate_invoice']) {
            return;
        }

        $problems = $this->invoiceProblems($property, $businessDate);

        if ($problems !== []) {
            $list = collect($problems)->map(fn (array $problem): string => "{$problem['booking_ref_id']} ({$problem['message']})")->implode(', ');

            throw ValidationException::withMessages(['business_date' => "Invoice check failed for booking(s) {$list}. Generate or update their invoices on the Night audit page."]);
        }
    }

    /**
     * Bookings checked out on business date D — from the Invoice Start Date to D when one is
     * set (nothing before it is checked) — whose invoice is missing, cancelled or void, or
     * whose amount differs from the booking's total (an invoice with a credit note is left
     * alone: the note is the correction).
     *
     * @return list<array{booking_id: int, booking_ref_id: string, guest: string|null, invoice_number: string|null, booking_total: float, invoice_amount: float|null, problem: 'missing'|'outdated', message: string}>
     */
    public function invoiceProblems(Property $property, CarbonImmutable $businessDate): array
    {
        $startDate = $this->settings->get($property)['invoice_start_date'];
        $from = $startDate !== null ? CarbonImmutable::parse($startDate)->startOfDay() : $businessDate;

        if ($from->gt($businessDate)) {
            return [];
        }

        [$windowStart] = $this->dayWindow($property, $from);
        [, $windowEnd] = $this->dayWindow($property, $businessDate);

        $bookings = Booking::query()
            ->where('property_id', $property->id)
            ->where('status', BookingStatus::CheckedOut)
            ->whereHas('rooms', fn (Builder $query) => $query->whereBetween('checked_out_at', [$windowStart, $windowEnd]))
            ->with('guests')
            ->orderBy('id')
            ->get();
        $invoices = Invoice::query()->whereIn('booking_id', $bookings->pluck('id'))->withCount('creditNotes')->get()->groupBy('booking_id');

        $problems = [];

        foreach ($bookings as $booking) {
            $invoice = $invoices->get($booking->id, collect())->first(fn (Invoice $invoice): bool => ! in_array($invoice->status, [InvoiceStatus::Cancelled, InvoiceStatus::Void], true));
            $total = round((float) $booking->total, 2);
            $guest = $booking->guests->first();

            $problem = match (true) {
                $invoice === null => ['missing', 'no invoice'],
                $invoice->credit_notes_count === 0 && abs((float) $invoice->invoice_amount - $total) > 0.01 => ['outdated', "invoice {$invoice->invoice_number} is for {$invoice->invoice_amount}, the bill is {$total}"],
                default => null,
            };

            if ($problem !== null) {
                $problems[] = [
                    'booking_id' => $booking->id,
                    'booking_ref_id' => (string) $booking->booking_ref_id,
                    'guest' => $guest ? trim("{$guest->first_name} {$guest->last_name}") : null,
                    'invoice_number' => $invoice?->invoice_number,
                    'booking_total' => $total,
                    'invoice_amount' => $invoice !== null ? (float) $invoice->invoice_amount : null,
                    'problem' => $problem[0],
                    'message' => $problem[1],
                ];
            }
        }

        return $problems;
    }

    /**
     * Night audit page → "Generate / update invoices": issues the missing invoices and brings
     * the outdated ones in line with their bills (they keep their numbers).
     *
     * @return int invoices generated or updated
     */
    public function fixInvoices(Property $property, CarbonImmutable $businessDate, User $by): int
    {
        $problems = $this->invoiceProblems($property, $businessDate);

        DB::transaction(function () use ($problems, $by): void {
            foreach (Booking::query()->whereIn('id', array_column($problems, 'booking_id'))->get() as $booking) {
                Invoice::generateForBooking($booking, $by->name);
            }
        });

        return count($problems);
    }

    /**
     * POS bills still open on business date D or before (not before the POS Rollover Start Date).
     *
     * @return Builder<OutletBill>
     */
    private function openPosBills(Property $property, CarbonImmutable $businessDate): Builder
    {
        $startDate = $this->settings->get($property)['pos_rollover_start_date'];

        return OutletBill::query()
            ->where('property_id', $property->id)
            ->where('status', OutletBillStatus::Open)
            ->whereDate('business_date', '<=', $businessDate)
            ->when($startDate, fn (Builder $query, string $start) => $query->whereDate('business_date', '>=', $start));
    }

    /**
     * Ledger drift check: a booking staying on D, or paid on D, whose folio ledger has
     * fallen behind it (a sync failed and was only reported) is re-synced. One still
     * behind afterwards keeps the day open, since the day-end figures would be wrong.
     * Runs before no-shows, whose own changes only sync once the audit commits.
     *
     * @return list<string> warnings naming the bookings that were repaired
     *
     * @throws ValidationException
     */
    private function resyncDriftedLedgers(Property $property, CarbonImmutable $businessDate): array
    {
        $paidBookingIds = $this->paymentsOf($property, $businessDate)
            ->with('bookingPaymentMappings:id,payment_id,booking_id')
            ->get(['id', 'booking_id'])
            ->flatMap(fn (Payment $payment) => $payment->bookingPaymentMappings->pluck('booking_id')->push($payment->booking_id))
            ->filter()
            ->unique();

        $bookings = Booking::query()
            ->where('property_id', $property->id)
            ->whereNotIn('status', BookingStatus::enquiryStatuses())
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $staying) => $staying->whereDate('check_in', '<=', $businessDate)->whereDate('check_out', '>=', $businessDate))
                ->orWhereIn('id', $paidBookingIds))
            ->get();

        $repaired = [];
        $stillBehind = [];

        foreach ($bookings as $booking) {
            if (! $this->ledgerSync->isOutOfStep($booking)) {
                continue;
            }

            try {
                $this->ledgerSync->sync($booking);
            } catch (Throwable $exception) {
                report($exception);
            }

            if ($this->ledgerSync->isOutOfStep($booking->fresh())) {
                $stillBehind[] = $booking->booking_ref_id;
            } else {
                $repaired[] = $booking->booking_ref_id;
            }
        }

        if ($stillBehind !== []) {
            throw ValidationException::withMessages(['business_date' => 'The folio ledger of booking(s) '.implode(', ', $stillBehind).' does not match the booking and could not be re-synced. Check them before the night audit.']);
        }

        return $repaired === [] ? [] : ['Folio ledger was out of step and has been re-synced for booking(s) '.implode(', ', $repaired).'.'];
    }

    /**
     * Payments received (or refunded) on business date D, in the property's local day.
     *
     * @return Builder<Payment>
     */
    public function paymentsOf(Property $property, CarbonImmutable $businessDate): Builder
    {
        return Payment::query()
            ->where('property_id', $property->id)
            ->whereIn('status', [PaymentStatus::Received, PaymentStatus::Refunded])
            ->whereBetween('payment_transaction_date', $this->dayWindow($property, $businessDate));
    }

    /**
     * Rooms still "booked" whose arrival day has passed (not before the Room Rollover
     * Start Date) become no-shows.
     *
     * @return array{stays: list<array<string, mixed>>, warnings: list<string>}
     */
    private function markNoShows(Property $property, CarbonImmutable $businessDate, ?User $by): array
    {
        $startDate = $this->settings->get($property)['room_rollover_start_date'];

        $stays = SubBooking::query()
            ->where('status', SubBookingStatus::Booked)
            ->whereDate('check_in_date', '<=', $businessDate)
            ->when($startDate, fn (Builder $query) => $query->whereDate('check_in_date', '>=', $startDate))
            ->whereHas('booking', fn (Builder $query) => $query
                ->where('property_id', $property->id)
                ->whereIn('status', [BookingStatus::Confirmed, BookingStatus::Pending, BookingStatus::CheckedIn]))
            ->with(['booking.primaryGuest', 'room'])
            ->get();

        $rows = [];
        $warnings = [];

        foreach ($stays->groupBy('booking_id') as $bookingStays) {
            [$bookingRows, $bookingWarnings] = $this->markBookingNoShows($property, $bookingStays, $by);
            array_push($rows, ...$bookingRows);
            array_push($warnings, ...$bookingWarnings);
        }

        return ['stays' => $rows, 'warnings' => array_values(array_unique($warnings))];
    }

    /**
     * One booking's no-show rooms: each is charged the arrival-day penalty (as one
     * night at the fee) and keeps only its arrival night; the booking is repriced so
     * the ledger reposts, and becomes a no-show when none of its rooms came.
     *
     * @param  Collection<int, SubBooking>  $noShows
     * @return array{0: list<array<string, mixed>>, 1: list<string>}
     */
    private function markBookingNoShows(Property $property, Collection $noShows, ?User $by): array
    {
        $booking = Booking::query()->lockForUpdate()->findOrFail($noShows->first()->booking_id);
        $rooms = $booking->rooms()->get();
        $noShowIds = $noShows->pluck('id')->all();
        $remaining = $rooms->reject(fn (SubBooking $room): bool => in_array($room->id, $noShowIds, true));
        $warnings = [];

        $nightsOf = fn (SubBooking $room): int => BookingPricing::nights($room->check_in_date->toDateString(), $room->check_out_date->toDateString());
        $amountOf = fn (SubBooking $room): float => round((float) $room->rate_per_night * $nightsOf($room), 2);
        $priced = fn (Collection $rooms, float $discount): array => $this->pricing->totals($property, array_values($rooms->map(fn (SubBooking $room): array => ['rate_per_night' => (float) $room->rate_per_night, 'nights' => $nightsOf($room)])->all()), $discount);

        $penaltyPercent = $this->arrivalDayPenalty($property, CarbonImmutable::parse($booking->check_in));

        if ($penaltyPercent === null) {
            $warnings[] = 'No cancellation policy applies, so no-shows were not charged.';
        }

        $roomTotalBefore = $rooms->sum($amountOf);
        $noShowAmount = $noShows->sum($amountOf);
        $discountAfter = $roomTotalBefore > 0 ? round((float) $booking->discount * ($roomTotalBefore - $noShowAmount) / $roomTotalBefore, 2) : 0.0;
        $taxDelta = round($priced($remaining, $discountAfter)['taxes'] - $priced($rooms, (float) $booking->discount)['taxes'], 2);

        if ($remaining->isNotEmpty() && $rooms->count() > 1) {
            $this->roomStays->split($booking);
        }

        $rows = [];
        $fees = 0.0;

        foreach ($noShows as $stay) {
            $amount = $amountOf($stay);
            $fee = round($amount * ($penaltyPercent ?? 0) / 100, 2);
            $fees += $fee;

            $rows[] = [
                'booking_id' => $booking->id,
                'booking_ref_id' => $booking->booking_ref_id,
                'sub_booking_id' => $stay->id,
                'room' => $stay->room?->name,
                'guest' => $stay->booking->primaryGuest ? trim("{$stay->booking->primaryGuest->first_name} {$stay->booking->primaryGuest->last_name}") : null,
                'check_in_date' => $stay->check_in_date->toDateString(),
                'check_out_date' => $stay->check_out_date->toDateString(),
                'stay_amount' => $amount,
                'penalty_percent' => $penaltyPercent ?? 0,
                'fee' => $fee,
            ];

            $room = SubBooking::query()->findOrFail($stay->id);
            $room->update([
                'status' => SubBookingStatus::NoShow,
                'check_out_date' => $room->check_in_date->addDay()->toDateString(),
                'rate_per_night' => $fee,
            ]);
        }

        $roomTotal = round((float) $booking->room_total - $noShowAmount + $fees, 2);
        $taxes = max(0.0, round((float) $booking->taxes + $taxDelta, 2));
        $rooms = $booking->rooms()->get();

        $booking->forceFill([
            'status' => $this->roomStays->derivedBookingStatus($booking) ?? BookingStatus::NoShow,
            'check_in' => $rooms->min(fn (SubBooking $room): string => $room->check_in_date->toDateString()),
            'check_out' => $rooms->max(fn (SubBooking $room): string => $room->check_out_date->toDateString()),
            'room_total' => $roomTotal,
            'discount' => $discountAfter,
            'taxes' => $taxes,
            'total' => round($roomTotal - $discountAfter + $taxes, 2),
        ])->save();

        if ($by !== null) {
            ActivityLog::create([
                'user_id' => $by->id,
                'property_id' => $property->id,
                'booking_id' => $booking->id,
                'guest_id' => $booking->guest_id,
                'action' => "Booking {$booking->booking_ref_id}: ".count($rows).' room(s) marked no-show by night audit (fee '.number_format($fees, 2).')',
                'date' => now(),
            ]);
        }

        return [$rows, $warnings];
    }

    /**
     * The penalty % of the cancellation-policy tier for cancelling on the arrival day
     * (0 days before check-in): the tier with the smallest "days from check-in".
     */
    private function arrivalDayPenalty(Property $property, ?CarbonImmutable $checkIn): ?float
    {
        $rule = $this->bookingEngine->resolveCancellationRule($property, $checkIn);
        $tier = collect($rule->rules ?? [])->sortBy(fn (array $tier): int => (int) $tier['day_from_checkin'])->first();

        return $tier === null ? null : (float) $tier['penalty_percent'];
    }

    /**
     * Guests still in house on a room whose departure date has come.
     *
     * @return list<array<string, mixed>>
     */
    private function overstays(Property $property, CarbonImmutable $businessDate): array
    {
        return array_values(SubBooking::query()
            ->where('status', SubBookingStatus::CheckedIn)
            ->whereDate('check_out_date', '<=', $businessDate)
            ->whereHas('booking', fn (Builder $query) => $query->where('property_id', $property->id))
            ->with(['booking.primaryGuest', 'room'])
            ->orderBy('check_out_date')
            ->get()
            ->map(fn (SubBooking $stay): array => [
                'booking_id' => $stay->booking_id,
                'booking_ref_id' => $stay->booking->booking_ref_id,
                'sub_booking_id' => $stay->id,
                'room' => $stay->room?->name,
                'guest' => $stay->booking->primaryGuest ? trim("{$stay->booking->primaryGuest->first_name} {$stay->booking->primaryGuest->last_name}") : null,
                'check_out_date' => $stay->check_out_date->toDateString(),
            ])
            ->all());
    }

    /**
     * The day-end report for D, as it stands at close.
     *
     * @param  list<array<string, mixed>>  $noShows
     * @return array<string, mixed>
     */
    private function report(Property $property, CarbonImmutable $businessDate, array $noShows, int $overstays): array
    {
        $date = $businessDate->toDateString();
        [$dayStart, $dayEnd] = $this->dayWindow($property, $businessDate);
        $ofProperty = fn (Builder $query) => $query->where('property_id', $property->id);

        $roomsAvailable = Room::query()->whereHas('roomCategory', $ofProperty)->count();

        $sold = SubBooking::query()
            ->whereIn('status', [SubBookingStatus::Booked, SubBookingStatus::CheckedIn, SubBookingStatus::CheckedOut])
            ->whereDate('check_in_date', '<=', $date)
            ->whereDate('check_out_date', '>', $date)
            ->whereHas('booking', $ofProperty)
            ->with('booking:id,room_total,taxes')
            ->get();

        $roomRevenue = round((float) $sold->sum('rate_per_night'), 2);
        // Each night's share of its booking's taxes.
        $roomTaxes = round($sold->sum(fn (SubBooking $stay): float => (float) $stay->booking->room_total > 0
            ? (float) $stay->booking->taxes * (float) $stay->rate_per_night / (float) $stay->booking->room_total
            : 0.0), 2);
        $noShowRevenue = round(collect($noShows)->sum('fee'), 2);

        $payments = Payment::query()
            ->where('property_id', $property->id)
            ->where('status', PaymentStatus::Received)
            ->whereBetween('payment_transaction_date', [$dayStart, $dayEnd])
            ->get(['type', 'payment_mode', 'total_txn_amount']);

        $receipts = $payments->where('type', 'receipt');
        $stayEvents = fn (string $column): int => SubBooking::query()->whereHas('booking', $ofProperty)->whereBetween($column, [$dayStart, $dayEnd])->count();
        $soldCount = $sold->count();
        $pos = $this->posSales->summary($property, $businessDate, $businessDate)['totals'];

        return [
            'currency' => Currency::forProperty($property)['code'],
            'rooms_available' => $roomsAvailable,
            'rooms_sold' => $soldCount,
            'occupancy_percent' => $roomsAvailable > 0 ? round($soldCount / $roomsAvailable * 100, 2) : 0.0,
            'adr' => $soldCount > 0 ? round($roomRevenue / $soldCount, 2) : 0.0,
            'revpar' => $roomsAvailable > 0 ? round($roomRevenue / $roomsAvailable, 2) : 0.0,
            'room_revenue' => $roomRevenue,
            'room_taxes' => $roomTaxes,
            'no_show_revenue' => $noShowRevenue,
            'total_revenue' => round($roomRevenue + $roomTaxes + $noShowRevenue, 2),
            'receipts_total' => round((float) $receipts->sum('total_txn_amount'), 2),
            'receipts_by_mode' => $receipts
                ->groupBy(fn (Payment $payment): string => $payment->payment_mode->value ?? 'Others')
                ->map(fn (Collection $group, string $mode): array => ['mode' => $mode, 'count' => $group->count(), 'amount' => round((float) $group->sum('total_txn_amount'), 2)])
                ->sortKeys()
                ->values()
                ->all(),
            'refunds_total' => round((float) $payments->where('type', '!=', 'receipt')->sum('total_txn_amount'), 2),
            'arrivals' => $stayEvents('checked_in_at'),
            'departures' => $stayEvents('checked_out_at'),
            'in_house' => SubBooking::query()->where('status', SubBookingStatus::CheckedIn)->whereHas('booking', $ofProperty)->count(),
            'no_shows' => count($noShows),
            'cancellations' => Booking::query()->where('property_id', $property->id)->where('status', BookingStatus::Cancelled)->whereBetween('updated_at', [$dayStart, $dayEnd])->count(),
            'overstays' => $overstays,
            'pos' => [
                'bills' => $pos['bills'],
                'net_sales' => $pos['net_sales'],
                'tax_total' => $pos['tax_total'],
                'total' => $pos['total'],
                'charged_to_rooms' => $pos['charged_to_rooms'],
                'collected' => $pos['collected'],
                'void_bills' => $pos['void_bills'],
            ],
        ];
    }

    /**
     * The UTC instants that begin and end the property's local day.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function dayWindow(Property $property, CarbonImmutable $businessDate): array
    {
        $start = CarbonImmutable::create($businessDate->year, $businessDate->month, $businessDate->day, 0, 0, 0, $property->timezoneName());

        return [$start->utc(), $start->endOfDay()->utc()];
    }
}
