<?php

namespace App\Http\Controllers\Admin;

use App\Enums\BookingStatus;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Booking;
use App\Models\CityLedgerAccount;
use App\Models\CompanyProfile;
use App\Models\Folio;
use App\Models\Guest;
use App\Models\Property;
use App\Models\SubFolio;
use App\Services\Ledger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Booking page → Folios → "+ Folio" / "+ Window": more bills on one booking, so a stay
 * can be paid flexibly (e.g. a company settles the room, the guest the extras). Amounts
 * reach the new window through Transfer, Split Payment or Add Payment's folio split.
 * Each window can name who pays it: one of the booking's guests or a company.
 */
class BookingFolioController extends Controller
{
    /**
     * Opens another folio on the booking, besides its master folio.
     */
    public function store(Request $request, Property $property, Booking $booking, Ledger $ledger): RedirectResponse
    {
        abort_unless($booking->property_id === $property->id, 404);

        $data = $request->validate($this->rules());

        if (in_array($booking->status, BookingStatus::enquiryStatuses(), true)) {
            return back()->with('error', 'An enquiry has no folios; confirm the booking first.');
        }

        $payer = $this->payer($data, $property, $booking);
        // Make sure the master folio exists first, so the new one is never taken as the default.
        $master = $ledger->defaultWindowFor($booking, $property->currency?->code);
        $window = $ledger->openFolio($booking, $master->folio->currency, $data['window_name'] ?? null, $payer);

        $this->log($request, $property, $booking, "Folio #{$window->folio_id} added with {$window->window_name}".$this->paidBy($payer).'.');

        return back()->with('success', "Folio #{$window->folio_id} added.");
    }

    /**
     * Adds a billing window (sub-folio) to one of the booking's folios.
     */
    public function storeWindow(Request $request, Property $property, Booking $booking, Folio $folio, Ledger $ledger): RedirectResponse
    {
        abort_unless($booking->property_id === $property->id, 404);
        abort_unless($booking->allFoliosQuery()->whereKey($folio->id)->exists(), 404);

        $data = $request->validate($this->rules());
        $payer = $this->payer($data, $property, $booking);

        try {
            $window = $ledger->addWindow($folio, $data['window_name'] ?? null, $payer);
        } catch (InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $this->log($request, $property, $booking, "{$window->window_name} added to folio #{$folio->id}".$this->paidBy($payer).'.');

        return back()->with('success', "{$window->window_name} added to folio #{$folio->id}.");
    }

    /**
     * Renames a window or changes who pays it.
     */
    public function updateWindow(Request $request, Property $property, Booking $booking, SubFolio $subFolio): RedirectResponse
    {
        abort_unless($booking->property_id === $property->id, 404);
        abort_unless($booking->allFoliosQuery()->whereKey($subFolio->folio_id)->exists(), 404);

        $data = $request->validate($this->rules());
        $payer = $this->payer($data, $property, $booking);

        $subFolio->update([
            'window_name' => filled($data['window_name'] ?? null) ? $data['window_name'] : $subFolio->window_name,
            'payer_type' => $payer?->getMorphClass(),
            'payer_id' => $payer?->getKey(),
        ]);

        $this->log($request, $property, $booking, "Folio #{$subFolio->folio_id} {$subFolio->window_name} is now paid by ".($payer ? $this->payerName($payer) : 'the folio owner').'.');

        return back()->with('success', "{$subFolio->window_name} saved.");
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(): array
    {
        return [
            'window_name' => ['nullable', 'string', 'max:50'],
            'payer_type' => ['nullable', Rule::in(['guest', 'company_profile'])],
            'payer_id' => ['nullable', 'integer'],
            'company_name' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * The window's payer: one of the booking's guests, or a company of this property (or a
     * shared one) — a new company is created from the name typed in. Null = the folio owner.
     *
     * @param  array{payer_type?: string|null, payer_id?: int|null, company_name?: string|null}  $data
     *
     * @throws ValidationException
     */
    private function payer(array $data, Property $property, Booking $booking): ?Model
    {
        return match ($data['payer_type'] ?? null) {
            'guest' => $booking->guests()->whereKey($data['payer_id'] ?? 0)->first()
                ?? throw ValidationException::withMessages(['payer_id' => 'Choose one of this booking\'s guests.']),
            'company_profile' => $this->company($data, $property),
            default => null,
        };
    }

    /**
     * @param  array{payer_id?: int|null, company_name?: string|null}  $data
     *
     * @throws ValidationException
     */
    private function company(array $data, Property $property): CompanyProfile
    {
        if (! empty($data['payer_id'])) {
            return CompanyProfile::query()
                ->whereKey($data['payer_id'])
                ->where(fn ($query) => $query->where('property_id', $property->id)->orWhereNull('property_id'))
                ->first()
                ?? throw ValidationException::withMessages(['payer_id' => 'Choose a company of this property.']);
        }

        if (blank($data['company_name'] ?? null)) {
            throw ValidationException::withMessages(['company_name' => 'Choose a company or type a new company name.']);
        }

        $company = CompanyProfile::query()->firstOrCreate(['property_id' => $property->id, 'name' => trim($data['company_name'])]);

        // One company record: a new folio company is the City Ledger account of the same name.
        if ($company->city_ledger_account_id === null) {
            $accountId = CityLedgerAccount::query()->where('property_id', $property->id)->whereRaw('LOWER(name) = ?', [mb_strtolower($company->name)])->value('id');

            if ($accountId !== null) {
                $company->update(['city_ledger_account_id' => $accountId]);
            }
        }

        return $company;
    }

    private function payerName(Model $payer): string
    {
        return $payer instanceof Guest ? trim("{$payer->first_name} {$payer->last_name}") : (string) $payer->getAttribute('name');
    }

    private function paidBy(?Model $payer): string
    {
        return $payer ? ', paid by '.$this->payerName($payer) : '';
    }

    private function log(Request $request, Property $property, Booking $booking, string $action): void
    {
        ActivityLog::create([
            'user_id' => $request->user()->id,
            'property_id' => $property->id,
            'booking_id' => $booking->id,
            'guest_id' => $booking->guests()->first()?->id,
            'action' => $action,
            'date' => now(),
        ]);
    }
}
