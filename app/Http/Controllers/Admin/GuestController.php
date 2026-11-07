<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CityLedgerAccount;
use App\Models\CompanyProfile;
use App\Models\Guest;
use App\Models\Property;
use App\Services\ApplicationSettings;
use App\Services\PermissionGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class GuestController extends Controller
{
    public function search(Request $request, Property $property): JsonResponse
    {
        $data = $request->validate([
            'q' => ['required', 'string', 'min:1', 'max:255'],
        ]);

        $term = $data['q'];

        $guests = $property->guests()
            ->where(function ($query) use ($term): void {
                $query->whereLike('first_name', "%{$term}%")
                    ->orWhereLike('last_name', "%{$term}%")
                    ->orWhereLike('phone', "%{$term}%")
                    ->orWhereLikeText('emails', "%{$term}%");

                if (ctype_digit($term)) {
                    $query->orWhere('id', (int) $term);
                }
            })
            ->orderBy('first_name')
            ->limit(10)
            ->get();

        return response()->json(['guests' => $guests]);
    }

    /**
     * Add/Update Company: the property's company City Ledger accounts matching a name or GSTIN.
     */
    public function companies(Request $request, Property $property): JsonResponse
    {
        $term = trim((string) $request->validate(['q' => ['nullable', 'string', 'max:255']])['q']);

        $accounts = CityLedgerAccount::query()
            ->where('property_id', $property->id)
            ->where('is_active', true)
            ->when($term !== '', fn ($query) => $query->where(fn ($match) => $match->whereLike('name', "%{$term}%")->orWhereLike('tax_number', "%{$term}%")->orWhereLike('code', "%{$term}%")))
            ->orderByRaw("account_type = 'company' desc")
            ->orderBy('name')
            ->limit(10)
            ->get();

        return response()->json(['companies' => $accounts->map(fn (CityLedgerAccount $account): array => $this->companyOption($account))->values()]);
    }

    public function store(Request $request, Property $property): JsonResponse
    {
        $data = $this->validated($request, $property);
        $guest = DB::transaction(fn (): Guest => $property->guests()->create($this->withCompanyAccount($request, $property, $data)));

        return response()->json(['guest' => $this->guestRecord($guest)]);
    }

    public function update(Request $request, Property $property, Guest $guest): JsonResponse
    {
        abort_unless($guest->property_id === $property->id, 404);

        $data = $this->validated($request, $property);
        DB::transaction(fn () => $guest->update($this->withCompanyAccount($request, $property, $data)));

        return response()->json(['guest' => $this->guestRecord($guest->fresh() ?? $guest)]);
    }

    /**
     * The guest with its company account's name and credit, for the forms.
     *
     * @return array<string, mixed>
     */
    private function guestRecord(Guest $guest): array
    {
        return [...$guest->toArray(), 'city_ledger_account' => $guest->cityLedgerAccount ? $this->companyOption($guest->cityLedgerAccount) : null];
    }

    /**
     * @return array<string, mixed>
     */
    private function companyOption(CityLedgerAccount $account): array
    {
        $balance = $account->balance();

        return [
            'id' => $account->id,
            'name' => $account->name,
            'code' => $account->code,
            'account_type' => $account->account_type,
            'tax_number' => $account->tax_number,
            'email' => $account->email,
            'address' => $account->address,
            'payment_terms_days' => $account->payment_terms_days,
            'credit_limit' => $account->credit_limit === null ? null : (float) $account->credit_limit,
            'available_credit' => $account->credit_limit === null ? null : round((float) $account->credit_limit - $balance, 2),
        ];
    }

    /**
     * The guest's company as a City Ledger account: the one picked, or a new one made from the
     * company details when "create" is ticked (staff allowed to manage the city ledger only).
     * Sending no account unlinks the company. A new account is also the folio company of that name.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function withCompanyAccount(Request $request, Property $property, array $data): array
    {
        $create = (bool) ($data['create_city_ledger_account'] ?? false);
        unset($data['create_city_ledger_account']);
        $company = $data['company'] ?? null;

        if ($create && blank($data['city_ledger_account_id'] ?? null)) {
            if (! app(PermissionGate::class)->allows($request->user(), 'database.city_ledger.manage')) {
                throw ValidationException::withMessages(['create_city_ledger_account' => 'You may not open City Ledger accounts.']);
            }

            if (blank($company['name'] ?? null)) {
                throw ValidationException::withMessages(['company.name' => 'Enter the company name to open its City Ledger account.']);
            }

            $account = CityLedgerAccount::query()->create([
                'property_id' => $property->id,
                'name' => trim((string) $company['name']),
                'account_type' => 'company',
                'tax_number' => filled($company['gstin'] ?? null) ? strtoupper(trim((string) $company['gstin'])) : null,
                'email' => $company['email'] ?? null,
                'address' => $company['address'] ?? null,
                'payment_terms_days' => 30,
                'is_active' => true,
            ]);

            CompanyProfile::query()->firstOrCreate(
                ['property_id' => $property->id, 'name' => $account->name],
                ['city_ledger_account_id' => $account->id, 'tax_number' => $account->tax_number, 'email' => $account->email],
            );

            $data['city_ledger_account_id'] = $account->id;
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, Property $property): array
    {
        $mandatory = app(ApplicationSettings::class)->get(ApplicationSettings::GUEST)['mandatory'];

        $validator = Validator::make($request->all(), [
            'title' => ['nullable', 'string', 'max:20'],
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => [$mandatory['last_name'] ? 'required' : 'nullable', 'string', 'max:255'],
            'phone_country_code' => ['nullable', 'string', 'max:10'],
            'phone' => [$mandatory['phone'] ? 'required' : 'nullable', 'string', 'max:20'],
            'emails' => ['nullable', 'array'],
            'emails.*' => ['nullable', 'email', 'max:255'],
            'gender' => ['nullable', 'string', 'max:20'],
            'dob' => ['nullable', 'date'],
            'nationality' => ['nullable', 'string', 'max:100'],
            'purpose_of_visit' => ['nullable', 'string', 'in:Leisure,Business'],
            'guest_preferences' => ['nullable', 'string'],
            'subscribe_newsletter' => ['boolean'],
            'anniversary' => ['nullable', 'date'],
            'frequent_flyer' => ['nullable', 'string', 'max:255'],
            'vehicle_number' => ['nullable', 'string', 'max:50'],
            'place_of_birth' => ['nullable', 'string', 'max:255'],
            'present_business_address' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'array'],
            'address.address' => ['nullable', 'string', 'max:255'],
            'address.country_id' => ['nullable', 'integer', 'exists:countries,id'],
            'address.state_id' => ['nullable', 'integer', 'exists:states,id'],
            'address.city_id' => ['nullable', 'integer', 'exists:cities,id'],
            'address.postal_code' => ['nullable', 'string', 'max:20'],
            'company' => ['nullable', 'array'],
            'company.gstin' => ['nullable', 'string', 'max:20'],
            'company.name' => ['nullable', 'string', 'max:255'],
            'company.email' => ['nullable', 'email', 'max:255'],
            'company.integration_id' => ['nullable', 'string', 'max:100'],
            'company.address' => ['nullable', 'string', 'max:255'],
            'city_ledger_account_id' => ['nullable', 'integer', Rule::exists('city_ledger_accounts', 'id')->where('property_id', $property->id)],
            'create_city_ledger_account' => ['boolean'],
            'international_details' => ['nullable', 'array'],
            'international_details.passport_number' => ['nullable', 'string', 'max:50'],
            'international_details.passport_issue_date' => ['nullable', 'date'],
            'international_details.passport_issue_place' => ['nullable', 'string', 'max:255'],
            'international_details.passport_validity' => ['nullable', 'date'],
            'international_details.visa_issue_place' => ['nullable', 'string', 'max:255'],
            'international_details.visa_number' => ['nullable', 'string', 'max:50'],
            'international_details.visa_type' => ['nullable', 'string', 'in:Tourist visa,Student visa,Business visa,Immigration visa'],
            'international_details.visa_issue_date' => ['nullable', 'date'],
            'international_details.visa_valid_till' => ['nullable', 'date'],
            'international_details.employed_in_india' => ['nullable', 'string', 'in:Yes,No'],
            'international_details.next_destination' => ['nullable', 'string', 'max:255'],
            'international_details.phone_in_india_country_code' => ['nullable', 'string', 'max:10'],
            'international_details.phone_in_india' => ['nullable', 'string', 'max:20'],
            'international_details.arrival_from' => ['nullable', 'string', 'max:255'],
            'international_details.arrival_date' => ['nullable', 'date'],
            'international_details.reference_address' => ['nullable', 'array'],
            'international_details.reference_address.address' => ['nullable', 'string', 'max:255'],
            'international_details.reference_address.country_id' => ['nullable', 'integer', 'exists:countries,id'],
            'international_details.reference_address.state_id' => ['nullable', 'integer', 'exists:states,id'],
            'international_details.reference_address.city_id' => ['nullable', 'integer', 'exists:cities,id'],
            'international_details.reference_address.postal_code' => ['nullable', 'string', 'max:20'],
        ]);

        // Rules from Application Settings → Guest that span fields; reported alongside the others.
        $validator->after(function ($validator) use ($request, $mandatory): void {
            $hasEmail = collect((array) $request->input('emails', []))->filter(fn ($email): bool => filled($email))->isNotEmpty();

            if ($mandatory['email'] && ! $hasEmail) {
                $validator->errors()->add('emails', 'An e-mail address is required.');
            }

            if ($mandatory['phone_or_email'] && blank($request->input('phone')) && ! $hasEmail) {
                $validator->errors()->add('phone', 'Enter a phone number or an e-mail address.');
            }
        });

        return $validator->validate();
    }
}
