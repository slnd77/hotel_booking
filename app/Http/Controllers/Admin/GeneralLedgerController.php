<?php

namespace App\Http\Controllers\Admin;

use App\Enums\GlAccountType;
use App\Enums\PaymentMode;
use App\Http\Controllers\Controller;
use App\Models\ChargeCode;
use App\Models\GlAccount;
use App\Models\GlMapping;
use App\Models\Property;
use App\Services\GeneralLedger;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Databases → General Ledger: the trial balance, an account's statement, the chart of
 * accounts, and where charge codes and payment modes post.
 */
class GeneralLedgerController extends Controller
{
    public function __construct(private GeneralLedger $generalLedger) {}

    /**
     * Opens on the property picked in the header.
     */
    public function index(): Response
    {
        return Inertia::render('admin/general-ledger/Index');
    }

    public function show(Request $request, Property $property): Response
    {
        $validated = $request->validate([
            'as_of' => ['nullable', 'date_format:Y-m-d'],
            'account' => ['nullable', 'integer'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $today = CarbonImmutable::parse($property->today());
        $asOf = CarbonImmutable::parse($validated['as_of'] ?? $today->toDateString());
        $accounts = $this->generalLedger->accounts($property->id);
        $account = isset($validated['account']) ? $accounts->firstWhere('id', $validated['account']) : null;
        $from = CarbonImmutable::parse($validated['from'] ?? $today->startOfMonth()->toDateString());
        $to = CarbonImmutable::parse($validated['to'] ?? $today->toDateString());
        $mappings = GlMapping::query()->where('property_id', $property->id)->get();

        return Inertia::render('admin/general-ledger/Show', [
            'property' => $property->only(['id', 'name']),
            'asOf' => $asOf->toDateString(),
            'trialBalance' => $this->generalLedger->trialBalance($property->id, $asOf),
            'control' => $this->generalLedger->receivableControl($property->id),
            'accounts' => $accounts->map(fn (GlAccount $glAccount): array => [
                'id' => $glAccount->id,
                'code' => $glAccount->code,
                'name' => $glAccount->name,
                'type' => $glAccount->type->value,
                'system_key' => $glAccount->system_key,
                'is_active' => $glAccount->is_active,
            ])->values(),
            'types' => collect(GlAccountType::cases())->mapWithKeys(fn (GlAccountType $type): array => [$type->value => $type->label()]),
            'statement' => $account ? [
                'account_id' => $account->id,
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                ...$this->generalLedger->statement($account, $from, $to),
            ] : null,
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'chargeCodes' => ChargeCode::query()->where(fn ($query) => $query->whereNull('property_id')->orWhere('property_id', $property->id))
                ->where('category', '!=', 'transfer')
                ->whereNotIn('code', [ChargeCode::PAYMENT, ChargeCode::REFUND])
                ->orderBy('code')
                ->get(['code', 'name'])
                ->map(fn (ChargeCode $code): array => [
                    'key' => $code->code,
                    'label' => "{$code->code} · {$code->name}",
                    'gl_account_id' => $mappings->first(fn (GlMapping $mapping): bool => $mapping->mapping_type === GlMapping::CHARGE_CODE && $mapping->key === $code->code)?->gl_account_id,
                ]),
            'paymentModes' => collect(PaymentMode::cases())->map(fn (PaymentMode $mode): array => [
                'key' => $mode->value,
                'label' => $mode->label(),
                'default' => GeneralLedger::defaultPaymentModeKey($mode),
                'gl_account_id' => $mappings->first(fn (GlMapping $mapping): bool => $mapping->mapping_type === GlMapping::PAYMENT_MODE && $mapping->key === $mode->value)?->gl_account_id,
            ])->values(),
        ]);
    }

    public function storeAccount(Request $request, Property $property): RedirectResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:20', Rule::unique('gl_accounts')->where('property_id', $property->id)],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::enum(GlAccountType::class)],
        ]);

        GlAccount::query()->create([...$validated, 'property_id' => $property->id]);

        return back()->with('success', "Account {$validated['code']} added.");
    }

    public function updateAccount(Request $request, Property $property, GlAccount $glAccount): RedirectResponse
    {
        abort_unless($glAccount->property_id === $property->id, 404);

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:20', Rule::unique('gl_accounts')->where('property_id', $property->id)->ignore($glAccount->id)],
            'name' => ['required', 'string', 'max:255'],
            'is_active' => ['boolean'],
        ]);

        // System accounts are where postings default to: they can be renamed, not switched off.
        if ($glAccount->system_key !== null && ! ($validated['is_active'] ?? true)) {
            throw ValidationException::withMessages(['is_active' => 'A system account can\'t be switched off.']);
        }

        $glAccount->update($validated);

        return back()->with('success', 'Account saved.');
    }

    /**
     * Saves where each charge code and payment mode posts; an empty choice means its default.
     */
    public function updateMappings(Request $request, Property $property): RedirectResponse
    {
        $validated = $request->validate([
            'mappings' => ['present', 'array', 'max:200'],
            'mappings.*.type' => ['required', Rule::in([GlMapping::CHARGE_CODE, GlMapping::PAYMENT_MODE])],
            'mappings.*.key' => ['required', 'string', 'max:60'],
            'mappings.*.gl_account_id' => ['nullable', 'integer', Rule::exists('gl_accounts', 'id')->where('property_id', $property->id)],
        ]);

        foreach ($validated['mappings'] as $mapping) {
            $match = ['property_id' => $property->id, 'mapping_type' => $mapping['type'], 'key' => $mapping['key']];

            if (empty($mapping['gl_account_id'])) {
                GlMapping::query()->where($match)->delete();
            } else {
                GlMapping::query()->updateOrCreate($match, ['gl_account_id' => $mapping['gl_account_id']]);
            }
        }

        $this->generalLedger->forget();

        return back()->with('success', 'Posting rules saved. They apply to entries from now on.');
    }
}
