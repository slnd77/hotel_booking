<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\NightAudit;
use App\Models\Payment;
use App\Models\Property;
use App\Services\NightAudits;
use App\Services\NightAuditSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Night audit history, each closed day's report, and running it by hand.
 */
class NightAuditController extends Controller
{
    public function __construct(private NightAuditSettings $settings) {}

    public function index(): Response
    {
        return Inertia::render('admin/night-audits/Index');
    }

    public function edit(Property $property, NightAudits $nightAudits): Response
    {
        $currentDate = $this->settings->currentRolloverDate($property);
        $automated = $this->settings->isAutomated($property);

        return Inertia::render('admin/night-audits/Edit', [
            'property' => $property->only(['id', 'name']),
            'currentRolloverDate' => $currentDate->toDateString(),
            'automated' => $automated,
            'rolloverTime' => $this->settings->get($property)['default_rollover_time'],
            'nextAutomaticRunAt' => $automated ? $this->settings->rolloverAt($property, $currentDate)->toIso8601ZuluString() : null,
            'canRunNow' => $currentDate->toDateString() <= $property->today(),
            'paymentReconciliation' => (bool) $this->settings->get($property)['payment_reconciliation'],
            // Validate Invoice: check-outs whose invoice is missing or out of date keep the day open.
            'invoiceValidation' => (bool) $this->settings->get($property)['validate_invoice'],
            'invoiceStartDate' => $this->settings->get($property)['invoice_start_date'],
            'invoiceProblems' => $this->settings->get($property)['validate_invoice'] ? $nightAudits->invoiceProblems($property, $currentDate) : [],
            // The current business date's payments, to check against the till / bank before the day closes.
            'paymentsToReconcile' => $nightAudits->paymentsOf($property, $currentDate)
                ->with('reconciledBy:id,name')
                ->orderBy('payment_transaction_date')
                ->get()
                ->map(fn (Payment $payment): array => [
                    'id' => $payment->id,
                    'type' => $payment->type,
                    'receipt_number' => $payment->receipt_number,
                    'guest_name' => $payment->guest_name,
                    'payment_mode' => $payment->payment_mode?->value,
                    'amount' => (float) $payment->total_txn_amount,
                    'received_at' => $payment->payment_transaction_date?->toIso8601ZuluString(),
                    'reconciled_at' => $payment->reconciled_at?->toIso8601ZuluString(),
                    'reconciled_by' => $payment->reconciledBy?->name,
                ]),
            'audits' => $property->nightAudits()
                ->with('runBy:id,name')
                ->latest('business_date')
                ->limit(60)
                ->get()
                ->map(fn (NightAudit $audit): array => [
                    'id' => $audit->id,
                    'business_date' => $audit->business_date->toDateString(),
                    'status' => $audit->status,
                    'run_by' => $audit->runBy?->name,
                    'finished_at' => $audit->finished_at?->toIso8601ZuluString(),
                    'error' => $audit->error,
                    'occupancy_percent' => $audit->summary['report']['occupancy_percent'] ?? null,
                    'total_revenue' => $audit->summary['report']['total_revenue'] ?? null,
                    'no_shows' => count($audit->summary['no_shows'] ?? []),
                    'overstays' => count($audit->summary['overstays'] ?? []),
                ]),
        ]);
    }

    public function show(Property $property, NightAudit $nightAudit): Response
    {
        abort_unless($nightAudit->property_id === $property->id, 404);

        return Inertia::render('admin/night-audits/Show', [
            'property' => $property->only(['id', 'name']),
            'audit' => [
                'id' => $nightAudit->id,
                'business_date' => $nightAudit->business_date->toDateString(),
                'status' => $nightAudit->status,
                'run_by' => $nightAudit->runBy?->name,
                'started_at' => $nightAudit->started_at?->toIso8601ZuluString(),
                'finished_at' => $nightAudit->finished_at?->toIso8601ZuluString(),
                'error' => $nightAudit->error,
                'report' => $nightAudit->summary['report'] ?? null,
                'no_shows' => $nightAudit->summary['no_shows'] ?? [],
                'overstays' => $nightAudit->summary['overstays'] ?? [],
                'warnings' => $nightAudit->summary['warnings'] ?? [],
            ],
        ]);
    }

    /**
     * Night audit → Payment Reconciliation: marks payments as checked (or undoes it).
     */
    public function reconcile(Request $request, Property $property): RedirectResponse
    {
        $data = $request->validate([
            'payment_ids' => ['required', 'array', 'min:1'],
            'payment_ids.*' => ['integer', Rule::exists('payments', 'id')->where('property_id', $property->id)],
            'reconciled' => ['required', 'boolean'],
        ]);

        $count = Payment::query()->where('property_id', $property->id)->whereIn('id', $data['payment_ids'])->update($data['reconciled']
            ? ['reconciled_at' => now(), 'reconciled_by' => $request->user()->id]
            : ['reconciled_at' => null, 'reconciled_by' => null]);

        return back()->with('success', $data['reconciled'] ? "{$count} payment(s) reconciled." : "{$count} payment(s) marked not reconciled.");
    }

    /**
     * Night audit → Invoice validation: issues the missing invoices and updates the outdated ones.
     */
    public function fixInvoices(Request $request, Property $property, NightAudits $nightAudits): RedirectResponse
    {
        $count = $nightAudits->fixInvoices($property, $this->settings->currentRolloverDate($property), $request->user());

        return back()->with('success', $count > 0 ? "{$count} invoice(s) generated or updated." : 'Every checked-out booking already has an up-to-date invoice.');
    }

    /**
     * "Run night audit": closes the current business date now.
     */
    public function store(Request $request, Property $property, NightAudits $nightAudits): RedirectResponse
    {
        $audit = $nightAudits->runManually($property, $request->user());

        if ($audit->status !== NightAudit::COMPLETED) {
            return back()->with('error', "Night audit for {$audit->business_date->format('d/m/Y')} failed: {$audit->error}");
        }

        return redirect()->route('admin.nightAudits.show', [$property, $audit])
            ->with('success', "Night audit for {$audit->business_date->format('d/m/Y')} completed.");
    }
}
