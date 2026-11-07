<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PaymentMode;
use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\Booking;
use App\Models\CityLedgerAccount;
use App\Models\Property;
use App\Services\ApplicationSettings;
use App\Services\CityLedger;
use App\Services\CurrentProperty;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Databases → City Ledger: direct-bill accounts with their balances and ageing, and on each
 * account its statement, bills moved from bookings, payments received and adjustments.
 */
class CityLedgerController extends Controller
{
    public function __construct(private CityLedger $cityLedger) {}

    public function index(Request $request): Response
    {
        $properties = app(ApplicationSettings::class)->orderedProperties(['id', 'name', 'abbreviation']);
        $property = app(CurrentProperty::class)->selected($request, $properties);
        $accounts = $property ? $this->cityLedger->accountsSummary($property->id, CarbonImmutable::parse($property->today())) : collect();

        return Inertia::render('admin/databases/CityLedger', [
            'property' => $property?->only(['id', 'name']),
            'accounts' => $accounts->values(),
            'totals' => [
                'balance' => round((float) $accounts->sum('balance'), 2),
                'overdue' => round((float) $accounts->sum('overdue'), 2),
                ...collect(CityLedger::BUCKETS)->keys()->mapWithKeys(fn (string $bucket): array => [$bucket => round((float) $accounts->sum(fn (array $account): float => $account['buckets'][$bucket]), 2)])->all(),
            ],
            'buckets' => CityLedger::BUCKETS,
            'types' => CityLedgerAccount::TYPES,
            'agencies' => Agency::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request, Property $property): RedirectResponse
    {
        $account = CityLedgerAccount::create([...$this->validatedAccount($request), 'property_id' => $property->id]);

        return redirect()->route('admin.databases.cityLedger.show', $account)->with('success', "City ledger account {$account->name} created.");
    }

    public function update(Request $request, CityLedgerAccount $cityLedgerAccount): RedirectResponse
    {
        $cityLedgerAccount->update($this->validatedAccount($request));

        return back()->with('success', 'Account saved.');
    }

    public function show(Request $request, CityLedgerAccount $cityLedgerAccount): Response
    {
        $cityLedgerAccount->loadMissing('property', 'agency:id,name');
        [$from, $to] = $this->period($request, $cityLedgerAccount);
        $today = CarbonImmutable::parse($cityLedgerAccount->property->today());

        return Inertia::render('admin/databases/CityLedgerAccount', [
            'property' => $cityLedgerAccount->property->only(['id', 'name']),
            'account' => [
                ...$cityLedgerAccount->only(['id', 'name', 'code', 'account_type', 'agency_id', 'tax_number', 'contact_name', 'email', 'phone', 'address', 'payment_terms_days', 'is_active', 'notes']),
                'agency' => $cityLedgerAccount->agency?->name,
                'credit_limit' => $cityLedgerAccount->credit_limit === null ? null : (float) $cityLedgerAccount->credit_limit,
            ],
            'ageing' => $this->cityLedger->ageing($cityLedgerAccount, $today),
            'statement' => $this->cityLedger->statement($cityLedgerAccount, $from, $to),
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'buckets' => CityLedger::BUCKETS,
            'types' => CityLedgerAccount::TYPES,
            'agencies' => Agency::query()->orderBy('name')->get(['id', 'name']),
            'paymentModes' => collect(PaymentMode::cases())->map(fn (PaymentMode $mode): array => ['value' => $mode->value, 'label' => $mode->label()])->values(),
        ]);
    }

    public function transfer(Request $request, CityLedgerAccount $cityLedgerAccount): RedirectResponse
    {
        $validated = $request->validate([
            'booking_ref_id' => ['required', 'string', 'max:60'],
            'amount' => ['nullable', 'numeric', 'min:0.01'],
        ]);

        $booking = Booking::query()->where('property_id', $cityLedgerAccount->property_id)->where('booking_ref_id', trim($validated['booking_ref_id']))->first()
            ?? throw ValidationException::withMessages(['booking_ref_id' => 'No booking of this property has that reference.']);

        $entry = $this->cityLedger->transferBooking($cityLedgerAccount, $booking, isset($validated['amount']) ? (float) $validated['amount'] : null, $request->user());

        return back()->with('success', 'Billed '.number_format((float) $entry->amount, 2)." of booking {$booking->booking_ref_id} to the account.");
    }

    public function payment(Request $request, CityLedgerAccount $cityLedgerAccount): RedirectResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999'],
            'payment_mode' => ['required', Rule::enum(PaymentMode::class)],
            'reference' => ['nullable', 'string', 'max:120'],
        ]);

        $this->cityLedger->recordPayment($cityLedgerAccount, (float) $validated['amount'], PaymentMode::from($validated['payment_mode']), $validated['reference'] ?? null, $request->user());

        return back()->with('success', 'Payment recorded.');
    }

    public function adjust(Request $request, CityLedgerAccount $cityLedgerAccount): RedirectResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'not_in:0', 'between:-99999999,99999999'],
            'description' => ['required', 'string', 'max:500'],
        ]);

        $this->cityLedger->adjust($cityLedgerAccount, (float) $validated['amount'], $validated['description'], $request->user());

        return back()->with('success', 'Adjustment posted.');
    }

    /**
     * The account's statement for the period, print-ready.
     */
    public function statement(Request $request, CityLedgerAccount $cityLedgerAccount): View
    {
        $cityLedgerAccount->loadMissing('property.currency');
        [$from, $to] = $this->period($request, $cityLedgerAccount);
        $property = $cityLedgerAccount->property;

        return view('prints.city-ledger-statement', [
            'title' => 'Statement of Account',
            'watermark' => null,
            'autoPrint' => ! $request->boolean('preview'),
            'pdfUrl' => null,
            'booking' => ['ref' => $cityLedgerAccount->name],
            'currency' => $property->currency?->code ?? 'INR',
            'property' => $property,
            'account' => $cityLedgerAccount,
            'period' => ['from' => $from, 'to' => $to],
            'statement' => $this->cityLedger->statement($cityLedgerAccount, $from, $to),
            'ageing' => $this->cityLedger->ageing($cityLedgerAccount, CarbonImmutable::parse($property->today())),
            'buckets' => CityLedger::BUCKETS,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedAccount(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:40'],
            'account_type' => ['required', Rule::in(array_keys(CityLedgerAccount::TYPES))],
            'agency_id' => ['nullable', 'integer', 'exists:agencies,id'],
            'tax_number' => ['nullable', 'string', 'max:40'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:1000'],
            'credit_limit' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'payment_terms_days' => ['required', 'integer', 'min:0', 'max:365'],
            'is_active' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
    }

    /**
     * The statement period from the query, by default the last 90 days to today.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function period(Request $request, CityLedgerAccount $account): array
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $today = CarbonImmutable::parse($account->property->today());

        return [
            CarbonImmutable::parse($validated['from'] ?? $today->subDays(90)->toDateString()),
            CarbonImmutable::parse($validated['to'] ?? $today->toDateString()),
        ];
    }
}
