<?php

namespace App\Http\Controllers\Admin;

use App\Enums\FolioStatus;
use App\Enums\TransactionType;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\CompanyProfile;
use App\Models\Folio;
use App\Models\Guest;
use App\Models\Property;
use App\Models\SubBooking;
use App\Services\ApplicationSettings;
use App\Services\CurrentProperty;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Databases → Folios: every folio of the property (room, master/group and company folios)
 * with its owner, booking and ledger totals.
 */
class FolioDatabaseController extends Controller
{
    /** @var array<string, string> folio type (owner morph alias) => label */
    public const TYPES = [
        'sub_booking' => 'Room Folio',
        'booking' => 'Master Folio',
        'company_profile' => 'Company Folio',
    ];

    public function index(Request $request): Response
    {
        $property = $this->selectedProperty($request);
        $filters = $this->validatedFilters($request);

        $folios = $this->filteredQuery($property, $filters)
            ->latest('folios.id')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (Folio $folio): array => $this->row($folio));

        return Inertia::render('admin/databases/Folios', [
            'property' => $property?->only(['id', 'name']),
            'folios' => $folios,
            'filters' => $filters,
            'typeOptions' => collect(self::TYPES)->map(fn (string $label, string $value): array => ['value' => $value, 'label' => $label])->values(),
            'statusOptions' => collect(FolioStatus::cases())->map(fn (FolioStatus $status): array => ['value' => $status->value, 'label' => ucfirst($status->value)]),
        ]);
    }

    public function download(Request $request): HttpResponse
    {
        $property = $this->selectedProperty($request);
        abort_if(! $property, 404);

        $folios = $this->filteredQuery($property, $this->validatedFilters($request))->latest('folios.id')->get();

        $output = fopen('php://temp', 'w+');
        fputcsv($output, ['Folio No.', 'Type', 'Status', 'Currency', 'Owner', 'Reservation No.', 'Room', 'Check-in', 'Check-out', 'Windows', 'Charges', 'Payments', 'Balance', 'Created', 'Last Updated']);

        foreach ($folios as $folio) {
            $row = $this->row($folio);
            fputcsv($output, [
                $row['id'], $row['type_label'], $row['status'], $row['currency'], $row['owner_name'], $row['booking_ref_id'], $row['room'],
                $row['check_in'], $row['check_out'], $row['windows'], $row['charges'], $row['payments'], $row['balance'],
                $property->localTime($folio->created_at)->format('d/m/Y H:i'), $property->localTime($folio->updated_at)->format('d/m/Y H:i'),
            ]);
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="folios-'.$property->abbreviation.'-'.now()->format('Y-m-d-His').'.csv"',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedFilters(Request $request): array
    {
        return $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'type' => ['nullable', Rule::in(array_keys(self::TYPES))],
            'status' => ['nullable', Rule::enum(FolioStatus::class)],
            'balance' => ['nullable', 'in:due,credit,zero'],
        ]);
    }

    /**
     * The property's folios: rooms and master folios through their booking, company folios
     * through the company profile (profiles without a property are shared by all).
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<Folio>
     */
    private function filteredQuery(?Property $property, array $filters): Builder
    {
        $debit = TransactionType::Debit->value;
        $credit = TransactionType::Credit->value;

        $query = Folio::query()
            ->withCount('subFolios')
            ->withSum(['transactions as charges' => fn (Builder $q) => $q->where('transaction_type', $debit)], 'amount')
            ->withSum(['transactions as payments' => fn (Builder $q) => $q->where('transaction_type', $credit)], 'amount')
            ->with(['owner' => fn (MorphTo $morph) => $morph->morphWith([
                SubBooking::class => ['booking.primaryGuest', 'booking.guests', 'room'],
                Booking::class => ['primaryGuest', 'guests', 'rooms'],
            ])]);

        if (! $property) {
            return $query->whereRaw('1 = 0');
        }

        $query->where(fn (Builder $q) => $q
            ->whereHasMorph('owner', [SubBooking::class], fn (Builder $stay) => $stay->whereHas('booking', fn (Builder $booking) => $booking->where('property_id', $property->id)))
            ->orWhereHasMorph('owner', [Booking::class], fn (Builder $booking) => $booking->where('property_id', $property->id))
            ->orWhereHasMorph('owner', [CompanyProfile::class], fn (Builder $company) => $company->where('property_id', $property->id)->orWhereNull('property_id')));

        if (filled($filters['type'] ?? null)) {
            $query->where('owner_type', $filters['type']);
        }

        if (filled($filters['status'] ?? null)) {
            $query->where('status', $filters['status']);
        }

        if (filled($filters['balance'] ?? null)) {
            $balance = "(select coalesce(sum(case when t.transaction_type = '{$debit}' then t.amount else -t.amount end), 0) from transactions t join sub_folios sf on sf.id = t.sub_folio_id where sf.folio_id = folios.id)";
            $query->whereRaw(match ($filters['balance']) {
                'due' => "{$balance} > 0.004",
                'credit' => "{$balance} < -0.004",
                default => "abs({$balance}) <= 0.004",
            });
        }

        if (filled($filters['q'] ?? null)) {
            $term = '%'.$filters['q'].'%';
            $guestMatches = fn (Builder $guest) => $guest->whereLike('first_name', $term)->orWhereLike('last_name', $term)->orWhereLike('phone', $term);
            $bookingMatches = fn (Builder $booking) => $booking->whereLike('booking_ref_id', $term)->orWhereHas('guests', $guestMatches);

            $query->where(function (Builder $q) use ($term, $bookingMatches): void {
                if (ctype_digit(trim($term, '%'))) {
                    $q->orWhere('folios.id', (int) trim($term, '%'));
                }

                $q->orWhereHasMorph('owner', [Booking::class], $bookingMatches)
                    ->orWhereHasMorph('owner', [SubBooking::class], fn (Builder $stay) => $stay->whereHas('booking', $bookingMatches))
                    ->orWhereHasMorph('owner', [CompanyProfile::class], fn (Builder $company) => $company->whereLike('name', $term)->orWhereLike('tax_number', $term));
            });
        }

        return $query;
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Folio $folio): array
    {
        $owner = $folio->owner;
        $booking = match (true) {
            $owner instanceof SubBooking => $owner->booking,
            $owner instanceof Booking => $owner,
            default => null,
        };
        $guest = $booking?->primaryGuest ?? $booking?->guests->first();
        $charges = round((float) $folio->charges, 2);
        $payments = round((float) $folio->payments, 2);

        return [
            'id' => $folio->id,
            'type' => $folio->owner_type,
            'type_label' => self::TYPES[$folio->owner_type] ?? ucfirst(str_replace('_', ' ', $folio->owner_type)),
            'status' => $folio->status->value,
            'currency' => $folio->currency,
            'owner_name' => $owner instanceof CompanyProfile ? $owner->name : $this->guestName($guest),
            'owner_detail' => $owner instanceof CompanyProfile ? $owner->tax_number : ($guest?->phone ?: $guest?->emails[0] ?? null),
            'booking_id' => $booking?->id,
            'booking_ref_id' => $booking?->booking_ref_id,
            'booking_status' => $booking?->status?->value,
            'room' => $owner instanceof SubBooking ? $owner->room?->name : ($owner instanceof Booking && $owner->rooms->isNotEmpty() ? $owner->rooms->count().' room(s)' : null),
            'check_in' => ($owner instanceof SubBooking ? $owner->check_in_date : $booking?->check_in)?->toDateString(),
            'check_out' => ($owner instanceof SubBooking ? $owner->check_out_date : $booking?->check_out)?->toDateString(),
            'windows' => (int) $folio->sub_folios_count,
            'charges' => $charges,
            'payments' => $payments,
            'balance' => round($charges - $payments, 2),
            'created_at' => $folio->created_at?->toIso8601ZuluString(),
            'updated_at' => $folio->updated_at?->toIso8601ZuluString(),
        ];
    }

    private function guestName(?Guest $guest): ?string
    {
        return $guest ? trim(collect([$guest->title, $guest->first_name, $guest->last_name])->filter()->implode(' ')) : null;
    }

    /**
     * The ?property= property, else the one the header picker shows (last used, or the first).
     */
    private function selectedProperty(Request $request): ?Property
    {
        $properties = app(ApplicationSettings::class)->orderedProperties(['id', 'name', 'abbreviation', 'timezone']);

        return app(CurrentProperty::class)->selected($request, $properties);
    }
}
