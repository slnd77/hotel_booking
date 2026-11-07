<?php

namespace App\Http\Controllers\Admin;

use App\Enums\BookingCheckInStatus;
use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Booking;
use App\Models\City;
use App\Models\CityLedgerAccount;
use App\Models\Country;
use App\Models\CreditNote;
use App\Models\Guest;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Property;
use App\Models\State;
use App\Models\User;
use App\Services\ApplicationSettings;
use App\Services\CompanyBilling;
use App\Services\CurrentProperty;
use App\Services\PaymentDistributionService;
use App\Services\PaymentModeSettings;
use App\Services\PaymentReceipts;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class DatabaseController extends Controller
{
    public function guests(Request $request): Response
    {
        $selectedProperty = $this->selectedProperty($request);
        $filters = $this->validatedGuestFilters($request);

        $guests = $this->filteredGuestsQuery($selectedProperty, $filters)
            ->orderBy('first_name')
            ->paginate(15)
            ->withQueryString();

        $this->preloadLocationNames($guests->getCollection());
        $guests->through(fn (Guest $guest) => $this->guestRow($guest));

        return Inertia::render('admin/databases/Guests', [
            'property' => $selectedProperty?->only(['id', 'name', 'country_id']),
            'countries' => Country::query()->orderBy('name')->get(['id', 'name', 'iso2', 'phonecode']),
            'guests' => $guests,
            'filters' => $filters,
        ]);
    }

    public function guestsDownload(Request $request): HttpResponse
    {
        $selectedProperty = $this->selectedProperty($request);
        $filters = $this->validatedGuestFilters($request);

        abort_if(! $selectedProperty, 404);

        $guests = $this->filteredGuestsQuery($selectedProperty, $filters)->orderBy('first_name')->get();

        $this->preloadLocationNames($guests);

        $columns = [
            'Guest ID', 'Title', 'First Name', 'Last Name', 'Mobile', 'E-mail', 'Address', 'City', 'State',
            'Country', 'Postal Code', 'Preferences', 'Nationality', 'Date of Birth', 'Anniversary',
            'Frequent Flyer', 'Vehicle Number', 'Passport No.', 'Place of issue of passport',
            'Date of issue of passport', 'Passport valid till', 'Visa no.', 'Date of issue of visa',
            'Visa valid till', 'Type of Visa', 'Place of issue of visa', 'Gender', 'Place of birth',
            'Present/Business address', 'Subscribe to Newsletter/Marketing emails', 'Purpose of Visit',
            'Guest Creation Date',
        ];

        $output = fopen('php://temp', 'w+');
        fputcsv($output, $columns);

        foreach ($guests as $guest) {
            $row = $this->guestRow($guest);
            $row['created_at'] = $this->csvLocalTime($selectedProperty, $row['created_at']);
            fputcsv($output, array_values($row));
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        ActivityLog::create([
            'user_id' => $request->user()->id,
            'property_id' => $selectedProperty->id,
            'guest_id' => null,
            'action' => 'guest_list_downloaded',
            'date' => now(),
        ]);

        $filename = 'guests-'.$selectedProperty->abbreviation.'-'.now()->format('Y-m-d-His').'.csv';

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /**
     * @var array<int, string>
     */
    private const UPLOAD_COLUMNS = [
        'Title', 'First Name', 'Last Name', 'Phone Country Code', 'Mobile', 'E-mail', 'Gender',
        'Date of Birth', 'Nationality', 'Purpose of Visit', 'Preferences', 'Anniversary',
        'Frequent Flyer', 'Vehicle Number', 'Address', 'City', 'State', 'Country', 'Postal Code',
    ];

    public function guestUploadTemplate(): HttpResponse
    {
        $output = fopen('php://temp', 'w+');
        fputcsv($output, self::UPLOAD_COLUMNS);
        fputcsv($output, [
            'Mr', 'John', 'Doe', '+91', '9876543210', 'john@example.com', 'Male',
            '1990-01-31', 'Indian', 'Leisure', 'Non-smoking room', '',
            '', '', '221B Baker Street', '', '', 'India', '560001',
        ]);

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="guest-upload-template.csv"',
        ]);
    }

    public function guestsUpload(Request $request): RedirectResponse
    {
        $selectedProperty = $this->selectedProperty($request);

        abort_if(! $selectedProperty, 404);

        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:2048'],
        ]);

        $handle = fopen($data['file']->getRealPath(), 'r');
        $header = array_map(fn (string $column) => strtolower(trim($column)), fgetcsv($handle) ?: []);

        $imported = 0;

        while (($row = fgetcsv($handle)) !== false) {
            $row = array_map(fn ($value) => is_string($value) ? trim($value) : $value, $row);
            $rowData = array_combine($header, $row);

            if (! $rowData || blank($rowData['first name'] ?? null) || blank($rowData['mobile'] ?? null)) {
                continue;
            }

            $country = filled($rowData['country'] ?? null)
                ? Country::query()->where('name', $rowData['country'])->first()
                : null;
            $state = $country && filled($rowData['state'] ?? null)
                ? State::query()->where('country_id', $country->id)->where('name', $rowData['state'])->first()
                : null;
            $city = $state && filled($rowData['city'] ?? null)
                ? City::query()->where('state_id', $state->id)->where('name', $rowData['city'])->first()
                : null;

            Guest::create([
                'property_id' => $selectedProperty->id,
                'title' => $rowData['title'] ?: null,
                'first_name' => $rowData['first name'],
                'last_name' => $rowData['last name'] ?: null,
                'phone_country_code' => $rowData['phone country code'] ?: null,
                'phone' => $rowData['mobile'],
                'emails' => filled($rowData['e-mail'] ?? null) ? [$rowData['e-mail']] : [],
                'gender' => $rowData['gender'] ?: null,
                'dob' => $rowData['date of birth'] ?: null,
                'nationality' => $rowData['nationality'] ?: null,
                'purpose_of_visit' => $rowData['purpose of visit'] ?: null,
                'guest_preferences' => $rowData['preferences'] ?: null,
                'anniversary' => $rowData['anniversary'] ?: null,
                'frequent_flyer' => $rowData['frequent flyer'] ?: null,
                'vehicle_number' => $rowData['vehicle number'] ?: null,
                'address' => [
                    'address' => $rowData['address'] ?: null,
                    'city_id' => $city?->id,
                    'state_id' => $state?->id,
                    'country_id' => $country?->id,
                    'postal_code' => $rowData['postal code'] ?: null,
                ],
            ]);

            $imported++;
        }

        fclose($handle);

        ActivityLog::create([
            'user_id' => $request->user()->id,
            'property_id' => $selectedProperty->id,
            'guest_id' => null,
            'action' => 'guest_list_uploaded',
            'date' => now(),
        ]);

        return back()->with('success', "{$imported} guest(s) imported.");
    }

    /**
     * The ?property= property, else the one the header picker shows (last used, or the first).
     */
    private function selectedProperty(Request $request): ?Property
    {
        $properties = app(ApplicationSettings::class)->orderedProperties(['id', 'name', 'abbreviation', 'country_id']);

        return app(CurrentProperty::class)->selected($request, $properties);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedGuestFilters(Request $request): array
    {
        return $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'reservation_ref' => ['nullable', 'string', 'max:255'],
            'guest_name' => ['nullable', 'string', 'max:255'],
            'guest_email' => ['nullable', 'string', 'max:255'],
            'guest_mobile' => ['nullable', 'string', 'max:255'],
            'booking_source' => ['nullable', 'string', 'max:255'],
            'booking_type' => ['nullable', 'string', 'max:255'],
            'reservation_status' => ['nullable', 'string', 'max:255'],
            'arrival_from' => ['nullable', 'date'],
            'arrival_to' => ['nullable', 'date'],
            'departure_from' => ['nullable', 'date'],
            'departure_to' => ['nullable', 'date'],
            'created_from' => ['nullable', 'date'],
            'created_to' => ['nullable', 'date'],
            'stay_from' => ['nullable', 'date'],
            'stay_to' => ['nullable', 'date'],
            'rooms_operator' => ['nullable', 'string', 'in:>=,<=,='],
            'rooms_count' => ['nullable', 'integer', 'min:0'],
            'special_instructions_has' => ['nullable', 'string', 'max:255'],
            'internal_notes_has' => ['nullable', 'string', 'max:255'],
            'updated_from' => ['nullable', 'date'],
            'updated_to' => ['nullable', 'date'],
            // Rendered for visual parity with the reference form; no backing data exists yet.
            'agency_name' => ['nullable', 'string', 'max:255'],
            'agency_contact_person' => ['nullable', 'string', 'max:255'],
            'agency_email' => ['nullable', 'string', 'max:255'],
            'agency_mobile' => ['nullable', 'string', 'max:255'],
            'sub_folio_number' => ['nullable', 'string', 'max:255'],
            'has_attachment' => ['nullable', 'string', 'max:255'],
            'document' => ['nullable', 'string', 'max:255'],
            'sales_executive' => ['nullable', 'string', 'max:255'],
            'check_in_status' => ['nullable', 'string', 'max:255'],
            'check_out_status' => ['nullable', 'string', 'max:255'],
            'created_by' => ['nullable', 'string', 'max:255'],
            'updated_by' => ['nullable', 'string', 'max:255'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Guest>
     */
    private function filteredGuestsQuery(?Property $selectedProperty, array $filters): Builder
    {
        $query = Guest::query();

        if ($selectedProperty) {
            $query->where('property_id', $selectedProperty->id);
        } else {
            $query->whereRaw('1 = 0');
        }

        if (filled($filters['q'] ?? null)) {
            $term = $filters['q'];
            $query->where(function ($q) use ($term): void {
                $q->whereLike('first_name', "%{$term}%")
                    ->orWhereLike('last_name', "%{$term}%")
                    ->orWhereLike('phone', "%{$term}%")
                    ->orWhereLikeText('emails', "%{$term}%");

                if (ctype_digit($term)) {
                    $q->orWhere('id', (int) $term);
                }
            });
        }

        if (filled($filters['guest_name'] ?? null)) {
            $term = $filters['guest_name'];
            $query->where(function ($q) use ($term): void {
                $q->whereLike('first_name', "%{$term}%")->orWhereLike('last_name', "%{$term}%");
            });
        }

        if (filled($filters['guest_email'] ?? null)) {
            $query->whereLikeText('emails', '%'.$filters['guest_email'].'%');
        }

        if (filled($filters['guest_mobile'] ?? null)) {
            $query->whereLike('phone', '%'.$filters['guest_mobile'].'%');
        }

        $bookingFilterKeys = [
            'reservation_ref', 'booking_source', 'booking_type', 'reservation_status',
            'arrival_from', 'arrival_to', 'departure_from', 'departure_to',
            'created_from', 'created_to', 'stay_from', 'stay_to',
            'rooms_operator', 'rooms_count', 'special_instructions_has', 'internal_notes_has',
            'updated_from', 'updated_to',
        ];

        if (collect($bookingFilterKeys)->contains(fn (string $key) => filled($filters[$key] ?? null))) {
            $query->whereHas('bookings', function ($q) use ($filters): void {
                if (filled($filters['reservation_ref'] ?? null)) {
                    $q->whereLike('booking_ref_id', '%'.$filters['reservation_ref'].'%');
                }

                if (filled($filters['booking_source'] ?? null)) {
                    $q->where('source', $filters['booking_source']);
                }

                if (filled($filters['booking_type'] ?? null)) {
                    $q->where('booking_type', $filters['booking_type']);
                }

                if (filled($filters['reservation_status'] ?? null)) {
                    $q->where('status', $filters['reservation_status']);
                }

                if (filled($filters['arrival_from'] ?? null)) {
                    $q->whereDate('check_in', '>=', $filters['arrival_from']);
                }

                if (filled($filters['arrival_to'] ?? null)) {
                    $q->whereDate('check_in', '<=', $filters['arrival_to']);
                }

                if (filled($filters['departure_from'] ?? null)) {
                    $q->whereDate('check_out', '>=', $filters['departure_from']);
                }

                if (filled($filters['departure_to'] ?? null)) {
                    $q->whereDate('check_out', '<=', $filters['departure_to']);
                }

                if (filled($filters['created_from'] ?? null)) {
                    $q->whereDate('created_at', '>=', $filters['created_from']);
                }

                if (filled($filters['created_to'] ?? null)) {
                    $q->whereDate('created_at', '<=', $filters['created_to']);
                }

                if (filled($filters['stay_from'] ?? null)) {
                    $q->whereDate('check_out', '>=', $filters['stay_from']);
                }

                if (filled($filters['stay_to'] ?? null)) {
                    $q->whereDate('check_in', '<=', $filters['stay_to']);
                }

                if (filled($filters['special_instructions_has'] ?? null)) {
                    $q->whereLike('special_instructions', '%'.$filters['special_instructions_has'].'%');
                }

                if (filled($filters['internal_notes_has'] ?? null)) {
                    $q->whereLike('internal_notes', '%'.$filters['internal_notes_has'].'%');
                }

                if (filled($filters['updated_from'] ?? null)) {
                    $q->whereDate('updated_at', '>=', $filters['updated_from']);
                }

                if (filled($filters['updated_to'] ?? null)) {
                    $q->whereDate('updated_at', '<=', $filters['updated_to']);
                }

                if (filled($filters['rooms_count'] ?? null)) {
                    $q->has('rooms', $filters['rooms_operator'] ?? '=', (int) $filters['rooms_count']);
                }
            });
        }

        return $query;
    }

    /**
     * @return array<string, mixed>
     */
    private function guestRow(Guest $guest): array
    {
        $address = $guest->address ?? [];
        $international = $guest->international_details ?? [];

        return [
            'id' => $guest->id,
            'title' => $guest->title,
            'first_name' => $guest->first_name,
            'last_name' => $guest->last_name,
            'mobile' => trim(($guest->phone_country_code ?? '').' '.($guest->phone ?? '')),
            'email' => $guest->emails[0] ?? null,
            'address' => $address['address'] ?? null,
            'city' => $this->locationName(City::class, $address['city_id'] ?? null),
            'state' => $this->locationName(State::class, $address['state_id'] ?? null),
            'country' => $this->locationName(Country::class, $address['country_id'] ?? null),
            'postal_code' => $address['postal_code'] ?? null,
            'guest_preferences' => $guest->guest_preferences,
            'nationality' => $guest->nationality,
            'dob' => $guest->dob?->toDateString(),
            'anniversary' => $guest->anniversary?->toDateString(),
            'frequent_flyer' => $guest->frequent_flyer,
            'vehicle_number' => $guest->vehicle_number,
            'passport_number' => $international['passport_number'] ?? null,
            'passport_issue_place' => $international['passport_issue_place'] ?? null,
            'passport_issue_date' => $international['passport_issue_date'] ?? null,
            'passport_validity' => $international['passport_validity'] ?? null,
            'visa_number' => $international['visa_number'] ?? null,
            'visa_issue_date' => $international['visa_issue_date'] ?? null,
            'visa_valid_till' => $international['visa_valid_till'] ?? null,
            'visa_type' => $international['visa_type'] ?? null,
            'visa_issue_place' => $international['visa_issue_place'] ?? null,
            'gender' => $guest->gender,
            'place_of_birth' => $guest->place_of_birth,
            'present_business_address' => $guest->present_business_address,
            'subscribe_newsletter' => $guest->subscribe_newsletter ? 'Yes' : 'No',
            'purpose_of_visit' => $guest->purpose_of_visit,
            'created_at' => $guest->created_at?->toIso8601ZuluString(),
        ];
    }

    /**
     * @var array<string, Collection<int, string>>
     */
    private array $locationNameCache = [];

    /**
     * @param  Collection<int, Guest>  $guests
     */
    private function preloadLocationNames(Collection $guests): void
    {
        $models = ['city_id' => City::class, 'state_id' => State::class, 'country_id' => Country::class];

        foreach ($models as $key => $model) {
            $ids = $guests->pluck('address.'.$key)->filter()->unique()->values();

            $this->locationNameCache[$model] = $model::query()->whereIn('id', $ids)->pluck('name', 'id');
        }
    }

    /**
     * @param  class-string<City|State|Country>  $model
     */
    private function locationName(string $model, ?int $id): ?string
    {
        if (! $id) {
            return null;
        }

        return $this->locationNameCache[$model][$id] ?? null;
    }

    public function bookings(Request $request): Response
    {
        $selectedProperty = $this->selectedProperty($request);
        $filters = $this->validatedBookingFilters($request);

        $bookings = $this->filteredBookingsQuery($selectedProperty, $filters)
            ->latest()
            ->paginate(15)
            ->withQueryString();

        $this->preloadLocationNames($this->primaryGuests($bookings->getCollection()));
        $bookings->through(fn (Booking $booking) => $this->bookingRow($booking));

        return Inertia::render('admin/databases/Bookings', [
            'property' => $selectedProperty?->only(['id', 'name', 'country_id']),
            'bookings' => $bookings,
            'filters' => $filters,
            'downloadEligible' => $this->hasEligibleDateRangeForDownload($filters),
        ]);
    }

    public function bookingsDownload(Request $request): HttpResponse
    {
        $selectedProperty = $this->selectedProperty($request);
        $filters = $this->validatedBookingFilters($request);

        abort_if(! $selectedProperty, 404);

        if (! $this->hasEligibleDateRangeForDownload($filters)) {
            abort(422, 'Add a date filter of 30 days or fewer to download the booking list.');
        }

        $bookings = $this->filteredBookingsQuery($selectedProperty, $filters)->latest()->get();

        $this->preloadLocationNames($this->primaryGuests($bookings));

        $columns = [
            'Property Name', 'Reservation No.', 'Guest Name', 'Guest ID', 'Guest E-mail', 'Guest Contact No.',
            'Guest Address', 'Guest City', 'Guest State', 'Guest Country', 'Guest Postal Code', 'Guest Nationality',
            'Guest Company Name', 'Guest Company Email', 'Booking Source', 'Booking Type', 'Check-in', 'Check-out',
            'Nights', 'Rooms', 'Status', 'Room Total', 'Discount', 'Taxes', 'Total', 'Outstanding',
            'Special Instructions', 'Internal Notes', 'Booking Creation Date',
        ];

        $output = fopen('php://temp', 'w+');
        fputcsv($output, $columns);

        foreach ($bookings as $booking) {
            $row = $this->bookingRow($booking);
            $row['created_at'] = $this->csvLocalTime($selectedProperty, $row['created_at']);
            $row['release_at'] = $this->csvLocalTime($selectedProperty, $row['release_at']);
            fputcsv($output, array_values($row));
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        ActivityLog::create([
            'user_id' => $request->user()->id,
            'property_id' => $selectedProperty->id,
            'guest_id' => null,
            'action' => 'booking_list_downloaded',
            'date' => now(),
        ]);

        $filename = 'bookings-'.$selectedProperty->abbreviation.'-'.now()->format('Y-m-d-His').'.csv';

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedBookingFilters(Request $request): array
    {
        return $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'reservation_ref' => ['nullable', 'string', 'max:255'],
            'guest_name' => ['nullable', 'string', 'max:255'],
            'guest_email' => ['nullable', 'string', 'max:255'],
            'guest_mobile' => ['nullable', 'string', 'max:255'],
            'booking_source' => ['nullable', 'string', 'max:255'],
            'booking_type' => ['nullable', 'string', 'max:255'],
            'reservation_status' => ['nullable', 'string', 'max:255'],
            'arrival_from' => ['nullable', 'date'],
            'arrival_to' => ['nullable', 'date'],
            'departure_from' => ['nullable', 'date'],
            'departure_to' => ['nullable', 'date'],
            'created_from' => ['nullable', 'date'],
            'created_to' => ['nullable', 'date'],
            'stay_from' => ['nullable', 'date'],
            'stay_to' => ['nullable', 'date'],
            'updated_from' => ['nullable', 'date'],
            'updated_to' => ['nullable', 'date'],
            'rooms_operator' => ['nullable', 'string', 'in:>=,<=,='],
            'rooms_count' => ['nullable', 'integer', 'min:0'],
            'special_instructions_has' => ['nullable', 'string', 'max:255'],
            'internal_notes_has' => ['nullable', 'string', 'max:255'],
            'check_in_status' => ['nullable', 'string', 'max:255'],
            'reservation_check_in_status' => ['nullable', 'string', 'max:255'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Booking>
     */
    private function filteredBookingsQuery(?Property $selectedProperty, array $filters, bool $enquiries = false): Builder
    {
        $query = Booking::query()->with(['guests', 'rooms', 'property:id,name']);

        // Enquiries (and their expired quotes) are listed on the Enquiries page only.
        if ($enquiries) {
            $query->whereIn('status', BookingStatus::enquiryStatuses());
        } else {
            $query->whereNotIn('status', BookingStatus::enquiryStatuses());
        }

        if ($selectedProperty) {
            $query->where('property_id', $selectedProperty->id);
        } else {
            $query->whereRaw('1 = 0');
        }

        if (filled($filters['q'] ?? null)) {
            $term = $filters['q'];
            $query->where(function ($q) use ($term): void {
                $q->whereLike('booking_ref_id', "%{$term}%")
                    ->orWhereHas('guests', function ($gq) use ($term): void {
                        $gq->whereLike('first_name', "%{$term}%")
                            ->orWhereLike('last_name', "%{$term}%")
                            ->orWhereLike('phone', "%{$term}%")
                            ->orWhereLikeText('emails', "%{$term}%");
                    });

                if (ctype_digit($term)) {
                    $q->orWhere('id', (int) $term);
                }
            });
        }

        if (filled($filters['reservation_ref'] ?? null)) {
            $query->whereLike('booking_ref_id', '%'.$filters['reservation_ref'].'%');
        }

        if (filled($filters['booking_source'] ?? null)) {
            $query->where('source', $filters['booking_source']);
        }

        if (filled($filters['booking_type'] ?? null)) {
            $query->where('booking_type', $filters['booking_type']);
        }

        if (filled($filters['reservation_status'] ?? null)) {
            $query->where('status', $filters['reservation_status']);
        }

        $checkInStatusFilter = $filters['check_in_status'] ?? $filters['reservation_check_in_status'] ?? null;
        if (filled($checkInStatusFilter) && $checkInStatusFilter !== 'both' && $checkInStatusFilter !== 'Both') {
            $enum = BookingCheckInStatus::tryFromNormalized($checkInStatusFilter);
            if ($enum) {
                $query->where('check_in_status', $enum->value);
            } else {
                $query->where('check_in_status', $checkInStatusFilter);
            }
        }

        if (filled($filters['arrival_from'] ?? null)) {
            $query->whereDate('check_in', '>=', $filters['arrival_from']);
        }

        if (filled($filters['arrival_to'] ?? null)) {
            $query->whereDate('check_in', '<=', $filters['arrival_to']);
        }

        if (filled($filters['departure_from'] ?? null)) {
            $query->whereDate('check_out', '>=', $filters['departure_from']);
        }

        if (filled($filters['departure_to'] ?? null)) {
            $query->whereDate('check_out', '<=', $filters['departure_to']);
        }

        if (filled($filters['created_from'] ?? null)) {
            $query->whereDate('created_at', '>=', $filters['created_from']);
        }

        if (filled($filters['created_to'] ?? null)) {
            $query->whereDate('created_at', '<=', $filters['created_to']);
        }

        if (filled($filters['stay_from'] ?? null)) {
            $query->whereDate('check_out', '>=', $filters['stay_from']);
        }

        if (filled($filters['stay_to'] ?? null)) {
            $query->whereDate('check_in', '<=', $filters['stay_to']);
        }

        if (filled($filters['updated_from'] ?? null)) {
            $query->whereDate('updated_at', '>=', $filters['updated_from']);
        }

        if (filled($filters['updated_to'] ?? null)) {
            $query->whereDate('updated_at', '<=', $filters['updated_to']);
        }

        if (filled($filters['special_instructions_has'] ?? null)) {
            $query->whereLike('special_instructions', '%'.$filters['special_instructions_has'].'%');
        }

        if (filled($filters['internal_notes_has'] ?? null)) {
            $query->whereLike('internal_notes', '%'.$filters['internal_notes_has'].'%');
        }

        if (filled($filters['rooms_count'] ?? null)) {
            $query->has('rooms', $filters['rooms_operator'] ?? '=', (int) $filters['rooms_count']);
        }

        if (filled($filters['guest_name'] ?? null) || filled($filters['guest_email'] ?? null) || filled($filters['guest_mobile'] ?? null)) {
            $query->whereHas('guests', function ($q) use ($filters): void {
                if (filled($filters['guest_name'] ?? null)) {
                    $term = $filters['guest_name'];
                    $q->where(function ($qq) use ($term): void {
                        $qq->whereLike('first_name', "%{$term}%")->orWhereLike('last_name', "%{$term}%");
                    });
                }

                if (filled($filters['guest_email'] ?? null)) {
                    $q->whereLikeText('emails', '%'.$filters['guest_email'].'%');
                }

                if (filled($filters['guest_mobile'] ?? null)) {
                    $q->whereLike('phone', '%'.$filters['guest_mobile'].'%');
                }
            });
        }

        return $query;
    }

    /**
     * Download is gated behind a date-range filter no wider than 30 days, since an
     * unfiltered export of the full booking history could be very large.
     *
     * @param  array<string, mixed>  $filters
     */
    private function hasEligibleDateRangeForDownload(array $filters): bool
    {
        $pairs = [
            ['arrival_from', 'arrival_to'],
            ['departure_from', 'departure_to'],
            ['created_from', 'created_to'],
            ['stay_from', 'stay_to'],
            ['updated_from', 'updated_to'],
        ];

        foreach ($pairs as [$fromKey, $toKey]) {
            $from = $filters[$fromKey] ?? null;
            $to = $filters[$toKey] ?? null;

            if (filled($from) && filled($to) && CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) <= 30) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function bookingRow(Booking $booking): array
    {
        $guest = $booking->guests->first();
        $address = $guest?->address ?? [];
        $company = $guest?->company ?? [];
        $nights = $booking->check_in && $booking->check_out ? $booking->check_in->diffInDays($booking->check_out) : 0;

        return [
            'id' => $booking->id,
            'property_name' => $booking->property?->name,
            'booking_ref_id' => $booking->booking_ref_id,
            'guest_name' => $guest ? trim(collect([$guest->title, $guest->first_name, $guest->last_name])->filter()->implode(' ')) : null,
            'guest_id' => $guest?->id,
            'guest_email' => $guest?->emails[0] ?? null,
            'guest_mobile' => $guest ? trim(($guest->phone_country_code ?? '').' '.($guest->phone ?? '')) : null,
            'guest_address' => $address['address'] ?? null,
            'guest_city' => $this->locationName(City::class, $address['city_id'] ?? null),
            'guest_state' => $this->locationName(State::class, $address['state_id'] ?? null),
            'guest_country' => $this->locationName(Country::class, $address['country_id'] ?? null),
            'guest_postal_code' => $address['postal_code'] ?? null,
            'guest_nationality' => $guest?->nationality,
            'guest_company_name' => $company['name'] ?? null,
            'guest_company_email' => $company['email'] ?? null,
            'source' => $booking->source,
            'booking_type' => $booking->booking_type,
            'check_in' => $booking->check_in?->toDateString(),
            'check_out' => $booking->check_out?->toDateString(),
            'nights' => $nights,
            'rooms_count' => $booking->rooms->count(),
            'check_in_status' => $booking->check_in_status?->value ?? (string) ($booking->check_in_status ?? ''),
            'status' => $booking->status?->value ?? (string) $booking->status,
            'room_total' => (float) $booking->room_total,
            'discount' => (float) $booking->discount,
            'taxes' => (float) $booking->taxes,
            'total' => (float) $booking->total,
            'outstanding' => (float) $booking->outstanding,
            'special_instructions' => $booking->special_instructions,
            'internal_notes' => $booking->internal_notes,
            'created_at' => $booking->created_at?->toIso8601ZuluString(),
            'release_at' => $booking->hold_release_at?->toIso8601ZuluString(),
        ];
    }

    /**
     * @param  Collection<int, Booking>  $bookings
     * @return Collection<int, Guest>
     */
    private function primaryGuests(Collection $bookings): Collection
    {
        return $bookings->map(fn (Booking $booking) => $booking->guests->first())->filter()->values();
    }

    public function payments(Request $request): Response
    {
        $selectedProperty = $this->selectedProperty($request);
        $filters = $this->validatedPaymentFilters($request);

        $payments = $this->filteredPaymentsQuery($selectedProperty, $filters)
            ->latest()
            ->paginate(15)
            ->withQueryString();

        $payments->through(fn (Payment $payment) => $this->paymentRow($payment));

        $reservations = $selectedProperty ? Booking::query()
            ->where('property_id', $selectedProperty->id)
            ->with('guests:id,first_name,last_name,phone,emails')
            ->latest()
            ->limit(100)
            ->get()
            ->map(fn (Booking $b) => [
                'id' => $b->id,
                'booking_ref_id' => $b->booking_ref_id,
                'guest_name' => $b->guests->first() ? trim($b->guests->first()->first_name.' '.$b->guests->first()->last_name) : '—',
                'guest_id' => $b->guests->first()?->id,
                'total' => round((float) $b->total + (float) $b->addons_total, 2),
                'outstanding' => (float) $b->outstanding,
                'taxes' => (float) $b->taxes,
                'status' => $b->status?->value ?? (string) $b->status,
                'folio_ids' => $b->folioIds(),
                // Add Payment → a tagged amount can be split over these (auto-filled in this order).
                'windows' => app(PaymentDistributionService::class)->folioWindowsFor($b),
            ]) : [];

        return Inertia::render('admin/databases/Payments', [
            'property' => $selectedProperty?->only(['id', 'name', 'abbreviation', 'country_id']),
            'properties' => Property::query()->orderBy('name')->get(['id', 'name', 'abbreviation']),
            'staff' => User::query()->orderBy('name')->get(['id', 'name']),
            'currentUser' => $request->user()?->only(['id', 'name']),
            'reservations' => $reservations,
            'countries' => Country::query()->orderBy('name')->get(['id', 'name', 'iso2', 'phonecode']),
            // Settings → Booking → Payment Settings of the selected property (every mode with none selected).
            'paymentModes' => $selectedProperty ? app(PaymentModeSettings::class)->paymentModes($selectedProperty) : null,
            'payments' => $payments,
            'filters' => $filters,
            'activeTab' => $filters['tab'] ?? 'receipts',
            'downloadEligible' => $this->hasEligiblePaymentDateRangeForDownload($filters),
        ]);
    }

    public function paymentsDownload(Request $request): HttpResponse
    {
        $selectedProperty = $this->selectedProperty($request);
        $filters = $this->validatedPaymentFilters($request);

        abort_if(! $selectedProperty, 404);

        if (! $this->hasEligiblePaymentDateRangeForDownload($filters)) {
            abort(422, 'Add a date filter of 30 days or fewer to download the payments list.');
        }

        $payments = $this->filteredPaymentsQuery($selectedProperty, $filters)->latest()->get();

        $columns = [
            'Payment Creation Date',
            'Payment Transaction Date',
            'Voucher/Order No.',
            'Receipt Number',
            'Payment Received Property',
            'Status',
            'Payment Ref ID',
            'PG Txn Id',
            'OTA Booking Ref ID',
            'NEFT Ref ID',
            'IMPS Ref ID',
            'RTGS Ref ID',
            'Guest Name',
            'Payment Mode',
            'Card number',
            'Total Txn Amount',
            'Collection Source',
            'Card Name',
            'Bank',
            'Booking Source',
            'Delete Status',
            'Cancellation Reason',
            'Collected By Staff',
            'Notes',
            'PG Pay Fields Edited',
            'Coupon',
            'Payee Name',
            'Document',
        ];

        $output = fopen('php://temp', 'w+');
        fputcsv($output, $columns);

        foreach ($payments as $payment) {
            $row = $this->paymentRow($payment);
            fputcsv($output, [
                $this->csvLocalTime($selectedProperty, $row['payment_creation_date']),
                $this->csvLocalTime($selectedProperty, $row['payment_transaction_date']),
                $row['voucher_number'],
                $row['receipt_number'],
                $row['property_name'],
                $row['status'],
                $row['payment_ref_id'],
                $row['pg_txn_id'],
                $row['ota_booking_ref_id'],
                $row['neft_ref_id'],
                $row['imps_ref_id'],
                $row['rtgs_ref_id'],
                $row['guest_name'],
                $row['payment_mode'],
                $row['card_number'],
                $row['total_txn_amount'],
                $row['collection_source'],
                $row['card_name'],
                $row['bank'],
                $row['booking_source'],
                $row['delete_status'],
                $row['cancellation_reason'] ?? '—',
                $row['collected_by_staff'],
                $row['notes'],
                $row['pg_pay_fields_edited'],
                $row['coupon'],
                $row['payee_name'],
                $row['document'],
            ]);
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        ActivityLog::create([
            'user_id' => $request->user()->id,
            'property_id' => $selectedProperty->id,
            'guest_id' => null,
            'action' => 'payment_list_downloaded',
            'date' => now(),
        ]);

        $tab = $filters['tab'] ?? 'receipts';
        $filename = "payments-{$tab}-{$selectedProperty->abbreviation}-".now()->format('Y-m-d-His').'.csv';

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    public function paymentsStore(Request $request): RedirectResponse
    {
        $selectedProperty = $this->selectedProperty($request);
        abort_if(! $selectedProperty, 404);

        $validated = $request->validate([
            'property_id' => ['nullable', 'integer', 'exists:properties,id'],
            'payment_received_at' => ['nullable', 'integer', 'exists:properties,id'],
            'booking_id' => ['nullable', 'integer', 'exists:bookings,id'],
            'voucher_number' => ['nullable', 'string', 'max:255'],
            'guest_name' => ['required', 'string', 'max:255'],
            'payment_mode' => ['required', 'string', 'max:100'],
            'paid_by' => ['nullable', 'string', 'max:100'],
            'total_txn_amount' => ['required_without:currency_code', 'nullable', 'numeric', 'min:0.01'],
            // Paid in another currency: the amount in it; the property-currency amount is converted on the server.
            'currency_code' => ['nullable', 'string', 'size:3'],
            'currency_amount' => ['required_with:currency_code', 'nullable', 'numeric', 'min:0.01'],
            'tagged_amount' => ['nullable', 'numeric', 'min:0'],
            'untagged_amount' => ['nullable', 'numeric', 'min:0'],
            'allocations' => ['nullable', 'array'],
            'allocations.*.booking_id' => ['required', 'integer', 'exists:bookings,id'],
            'allocations.*.tagged_amount' => ['required', 'numeric', 'min:0'],
            // How a booking's share is spread over its folio and sub-folios (optional: otherwise the ledger's default order).
            'allocations.*.windows' => ['nullable', 'array', 'max:50'],
            'allocations.*.windows.*.sub_folio_id' => ['required', 'integer'],
            'allocations.*.windows.*.amount' => ['required', 'numeric', 'min:0'],
            'payment_transaction_date' => ['nullable', 'string'],
            'receipt_number' => ['nullable', 'string', 'max:255'],
            'payment_ref_id' => ['nullable', 'string', 'max:255'],
            'pg_txn_id' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'collected_by_staff' => ['nullable', 'string', 'max:255'],
            'block_charge' => ['nullable', 'boolean'],
            'booking_source' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', 'string', 'in:receipt,refund'],
            'first_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'tin_no' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
            'country' => ['nullable', 'string', 'max:255'],
            'state' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:255'],
        ]);

        $targetPropertyId = $validated['payment_received_at'] ?? ($validated['property_id'] ?? $selectedProperty->id);

        $payment = app(PaymentReceipts::class)->record((int) $targetPropertyId, $validated, $request->user()?->name);

        return back()->with('success', "Payment receipt {$payment->receipt_number} added successfully.");
    }

    public function paymentsCancel(Request $request, Payment $payment): RedirectResponse
    {
        $validated = $request->validate([
            'cancellation_reason' => ['nullable', 'string', 'max:500'],
        ]);

        // The ledger reverses the receipt's credit, so what it paid is owed again.
        $payment->update([
            'status' => PaymentStatus::Cancelled,
            'cancellation_reason' => $validated['cancellation_reason'] ?? 'Cancelled by user',
        ]);

        return back()->with('success', 'Payment status updated to Cancelled.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedPaymentFilters(Request $request): array
    {
        return $request->validate([
            'tab' => ['nullable', 'string', 'in:receipts,refunds'],
            'q' => ['nullable', 'string', 'max:255'],
            'voucher_number' => ['nullable', 'string', 'max:255'],
            'receipt_number' => ['nullable', 'string', 'max:255'],
            'payment_ref_id' => ['nullable', 'string', 'max:255'],
            'pg_txn_id' => ['nullable', 'string', 'max:255'],
            'guest_name' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'max:255'],
            'payment_mode' => ['nullable', 'string', 'max:255'],
            'booking_source' => ['nullable', 'string', 'max:255'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'created_from' => ['nullable', 'date'],
            'created_to' => ['nullable', 'date'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Payment>
     */
    private function filteredPaymentsQuery(?Property $selectedProperty, array $filters): Builder
    {
        $query = Payment::query()->with(['property:id,name,abbreviation', 'booking:id,booking_ref_id']);

        if ($selectedProperty) {
            $query->where('property_id', $selectedProperty->id);
        } else {
            $query->whereRaw('1 = 0');
        }

        $type = ($filters['tab'] ?? 'receipts') === 'refunds' ? 'refund' : 'receipt';
        $query->where('type', $type);

        if (filled($filters['q'] ?? null)) {
            $term = $filters['q'];
            $query->where(function ($q) use ($term): void {
                $q->whereLike('voucher_number', "%{$term}%")
                    ->orWhereLike('receipt_number', "%{$term}%")
                    ->orWhereLike('guest_name', "%{$term}%")
                    ->orWhereLike('payment_ref_id', "%{$term}%")
                    ->orWhereLike('pg_txn_id', "%{$term}%")
                    ->orWhereLike('ota_booking_ref_id', "%{$term}%")
                    ->orWhereLike('neft_ref_id', "%{$term}%")
                    ->orWhereLike('imps_ref_id', "%{$term}%")
                    ->orWhereLike('rtgs_ref_id', "%{$term}%");

                if (ctype_digit($term)) {
                    $q->orWhere('id', (int) $term);
                }
            });
        }

        if (filled($filters['voucher_number'] ?? null)) {
            $query->whereLike('voucher_number', '%'.$filters['voucher_number'].'%');
        }

        if (filled($filters['receipt_number'] ?? null)) {
            $query->whereLike('receipt_number', '%'.$filters['receipt_number'].'%');
        }

        if (filled($filters['payment_ref_id'] ?? null)) {
            $query->whereLike('payment_ref_id', '%'.$filters['payment_ref_id'].'%');
        }

        if (filled($filters['pg_txn_id'] ?? null)) {
            $query->whereLike('pg_txn_id', '%'.$filters['pg_txn_id'].'%');
        }

        if (filled($filters['guest_name'] ?? null)) {
            $query->whereLike('guest_name', '%'.$filters['guest_name'].'%');
        }

        if (filled($filters['status'] ?? null)) {
            $query->where('status', $filters['status']);
        }

        if (filled($filters['payment_mode'] ?? null)) {
            $query->where('payment_mode', $filters['payment_mode']);
        }

        if (filled($filters['booking_source'] ?? null)) {
            $query->where('booking_source', $filters['booking_source']);
        }

        if (filled($filters['date_from'] ?? null)) {
            $query->whereDate('payment_transaction_date', '>=', $filters['date_from']);
        }

        if (filled($filters['date_to'] ?? null)) {
            $query->whereDate('payment_transaction_date', '<=', $filters['date_to']);
        }

        if (filled($filters['created_from'] ?? null)) {
            $query->whereDate('created_at', '>=', $filters['created_from']);
        }

        if (filled($filters['created_to'] ?? null)) {
            $query->whereDate('created_at', '<=', $filters['created_to']);
        }

        return $query;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function hasEligiblePaymentDateRangeForDownload(array $filters): bool
    {
        $pairs = [
            ['date_from', 'date_to'],
            ['created_from', 'created_to'],
        ];

        foreach ($pairs as [$fromKey, $toKey]) {
            $from = $filters[$fromKey] ?? null;
            $to = $filters[$toKey] ?? null;

            if (filled($from) && filled($to) && CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) <= 30) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function paymentRow(Payment $payment): array
    {
        return [
            'id' => $payment->id,
            'property_id' => $payment->property_id,
            'property_name' => $payment->property?->name,
            'property_abbreviation' => $payment->property?->abbreviation,
            'type' => $payment->type,
            'payment_creation_date' => $payment->payment_creation_date?->toIso8601ZuluString() ?? $payment->created_at?->toIso8601ZuluString(),
            'payment_transaction_date' => $payment->payment_transaction_date?->toIso8601ZuluString() ?? $payment->created_at?->toIso8601ZuluString(),
            'voucher_number' => $payment->voucher_number ?? ($payment->booking?->booking_ref_id ?? '—'),
            'booking_id' => $payment->booking_id,
            'receipt_number' => $payment->receipt_number ?? '—',
            'status' => $payment->status?->value ?? (string) ($payment->status ?? 'Received'),
            'payment_ref_id' => $payment->payment_ref_id ?? '—',
            'pg_txn_id' => $payment->pg_txn_id ?? '—',
            'ota_booking_ref_id' => $payment->ota_booking_ref_id ?? '—',
            'neft_ref_id' => $payment->neft_ref_id ?? '—',
            'imps_ref_id' => $payment->imps_ref_id ?? '—',
            'rtgs_ref_id' => $payment->rtgs_ref_id ?? '—',
            'guest_name' => $payment->guest_name ?? '—',
            'payment_mode' => $payment->payment_mode?->value ?? (string) ($payment->payment_mode ?? '—'),
            'card_number' => $payment->card_number ?? '—',
            'total_txn_amount' => (float) $payment->total_txn_amount,
            'tagged_amount' => (float) $payment->tagged_amount,
            'untagged_amount' => (float) $payment->untagged_amount,
            'pg_commission_amount' => $payment->pg_commission_amount === null ? null : (float) $payment->pg_commission_amount,
            'currency_code' => $payment->currency_code,
            'currency_amount' => $payment->currency_amount === null ? null : (float) $payment->currency_amount,
            'collection_source' => $payment->collection_source ?? '—',
            'card_name' => $payment->card_name ?? '—',
            'bank' => $payment->bank ?? '—',
            'booking_source' => $payment->booking_source ?? 'Direct',
            'delete_status' => $payment->delete_status ?? '—',
            // Left null when there's none: the receipt modal reads any reason as "cancelled".
            'cancellation_reason' => $payment->cancellation_reason,
            'collected_by_staff' => $payment->collected_by_staff ?? '—',
            'notes' => $payment->notes ?? '—',
            'pg_pay_fields_edited' => $payment->pg_pay_fields_edited ?? '—',
            'coupon' => $payment->coupon ?? '—',
            'payee_name' => $payment->payee_name ?? '—',
            'document' => $payment->document ?? '—',
        ];
    }

    public function invoices(Request $request): Response
    {
        $selectedProperty = $this->selectedProperty($request);
        $filters = $this->validatedInvoiceFilters($request);

        $invoices = $this->filteredInvoicesQuery($selectedProperty, $filters)
            ->withSum('creditNotes as credited_total', 'total')
            ->latest('invoice_generation_date')
            ->paginate(15)
            ->withQueryString();

        // Bill To Guest Company: what each company-billed booking still owes its account.
        $companyOpen = app(CompanyBilling::class)->openForBookings(collect($invoices->items())->whereNotNull('city_ledger_account_id'));
        $invoices->through(fn (Invoice $invoice) => $this->invoiceRow($invoice, $companyOpen));

        // The Credit Note tab lists the notes issued, newest first.
        $creditNotes = ($filters['tab'] ?? 'invoice') === 'credit_note'
            ? CreditNote::query()
                ->when($selectedProperty, fn ($query) => $query->where('property_id', $selectedProperty->id))
                ->when(filled($filters['search'] ?? null), fn ($query) => $query->where(fn ($query) => $query
                    ->whereLike('credit_note_number', '%'.trim($filters['search']).'%')
                    ->orWhereHas('invoice', fn ($query) => $query->whereLike('invoice_number', '%'.trim($filters['search']).'%')->orWhereLike('guest_name', '%'.trim($filters['search']).'%'))))
                ->with(['invoice:id,invoice_number,guest_name,party_name,folio_number,booking_id,property_id', 'creator:id,name'])
                ->latest('id')
                ->paginate(15, pageName: 'notes_page')
                ->withQueryString()
                ->through(fn (CreditNote $note): array => [
                    'id' => $note->id,
                    'credit_note_number' => $note->credit_note_number,
                    'issue_date' => $note->issue_date->toDateString(),
                    'invoice_number' => $note->invoice?->invoice_number,
                    'party_name' => $note->invoice?->party_name ?: $note->invoice?->guest_name,
                    'folio_number' => $note->invoice?->folio_number,
                    'booking_id' => $note->invoice?->booking_id,
                    'property_id' => $note->property_id,
                    'reason' => $note->reason,
                    'taxable_amount' => (float) $note->taxable_amount,
                    'tax_amount' => (float) $note->tax_amount,
                    'total' => (float) $note->total,
                    'issued_by' => $note->creator?->name,
                ])
            : null;

        return Inertia::render('admin/databases/Invoices', [
            'property' => $selectedProperty?->only(['id', 'name', 'abbreviation', 'country_id']),
            'properties' => Property::query()->orderBy('name')->get(['id', 'name', 'abbreviation']),
            'staff' => User::query()->orderBy('name')->get(['id', 'name']),
            'invoices' => $invoices,
            'filters' => $filters,
            'activeTab' => $filters['tab'] ?? 'invoice',
            'creditNotes' => $creditNotes,
            'downloadEligible' => $this->hasEligibleInvoiceDateRangeForDownload($filters),
        ]);
    }

    public function invoicesDownload(Request $request): HttpResponse
    {
        $selectedProperty = $this->selectedProperty($request);
        $filters = $this->validatedInvoiceFilters($request);

        abort_if(! $selectedProperty, 404);

        if (! $this->hasEligibleInvoiceDateRangeForDownload($filters)) {
            abort(422, 'Add an invoice generation date filter of 30 days or fewer to download the invoice list.');
        }

        $invoices = $this->filteredInvoicesQuery($selectedProperty, $filters)->latest('invoice_generation_date')->get();

        $columns = [
            'Invoice Generation Date', 'Reservation Date', 'Folio Number', 'Order Number', 'Property Name',
            'Outlet Name', 'Unit Type', 'Invoice Number', 'Status', 'Paid By', 'Guest Name', 'Booking Source',
            'Agent / OTA Name', 'Corporate Account', 'Corporate ID', 'Last Receipt Date', 'Invoice Amount',
            'Taxable Amount', 'Total Tax', 'Amount Paid', 'Tax Exempted', 'Tax Exemption Type', 'Invoice Outstanding amount',
            'Generated By Staff', 'Booking Reference', 'Days in Outstanding', 'Sales Person', 'Promo Name',
            'Document Attached', 'Party Name', 'Party GSTIN / Tax Reg No.', 'CheckIn Date', 'CheckOut Date',
            'Total Pax', 'Total Children', 'Rooms', 'Discount', 'Room Nights', 'Meal Plans', 'Item Types',
        ];

        $output = fopen('php://temp', 'w+');
        fputcsv($output, $columns);

        foreach ($invoices as $invoice) {
            $row = $this->invoiceRow($invoice);
            fputcsv($output, [
                $this->csvLocalTime($selectedProperty, $row['invoice_generation_date']),
                $this->csvLocalTime($selectedProperty, $row['reservation_date']),
                $row['folio_number'],
                $row['order_number'],
                $row['property_name'],
                $row['outlet_name'],
                $row['unit_type'],
                $row['invoice_number'],
                $row['status'],
                $row['paid_by'],
                $row['guest_name'],
                $row['booking_source'],
                $row['agent_ota_name'],
                $row['corporate_account'],
                $row['corporate_id'],
                $row['last_receipt_date'],
                number_format($row['invoice_amount'], 2, '.', ''),
                number_format($row['taxable_amount'], 2, '.', ''),
                number_format($row['total_tax'], 2, '.', ''),
                number_format($row['amount_paid'], 2, '.', ''),
                number_format($row['tax_exempted'], 2, '.', ''),
                $row['tax_exemption_type'],
                number_format($row['invoice_outstanding_amount'], 2, '.', ''),
                $row['generated_by_staff'],
                $row['booking_reference'],
                $row['days_in_outstanding'],
                $row['sales_person'],
                $row['promo_name'],
                $row['document_attached'],
                $row['party_name'],
                $row['party_gstin'],
                $row['checkin_date'],
                $row['checkout_date'],
                $row['total_pax'],
                $row['total_children'],
                $row['rooms_count'],
                number_format($row['discount'], 2, '.', ''),
                $row['room_nights'],
                $row['meal_plans'],
                $row['item_types'],
            ]);
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        ActivityLog::create([
            'user_id' => $request->user()->id,
            'property_id' => $selectedProperty->id,
            'guest_id' => null,
            'action' => 'invoice_list_downloaded',
            'date' => now(),
        ]);

        $filename = 'invoices-'.($selectedProperty->abbreviation ?: 'prop').'-'.now()->format('Y-m-d-His').'.csv';

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedInvoiceFilters(Request $request): array
    {
        return $request->validate([
            'tab' => ['nullable', 'string', 'in:invoice,credit_note'],
            'search' => ['nullable', 'string', 'max:255'],
            'invoice_generation_date_from' => ['nullable', 'date'],
            'invoice_generation_date_to' => ['nullable', 'date'],
            'invoice_number' => ['nullable', 'string', 'max:255'],
            'created_by' => ['nullable', 'string', 'max:255'],
            'booking_source' => ['nullable', 'string', 'max:255'],
            'booking_status' => ['nullable', 'string', 'max:255'],
            'has_attachment' => ['nullable', 'string', 'max:255'],
            'document' => ['nullable', 'string', 'max:255'],
            'send_bill' => ['nullable', 'string', 'max:255'],
            'soa_generated' => ['nullable', 'string', 'max:255'],
            'unit_type' => ['nullable', 'string', 'max:255'],
            'billed_to' => ['nullable', 'string', 'in:guest,company,company_unpaid'],
        ]);
    }

    private function filteredInvoicesQuery(?Property $property, array $filters): Builder
    {
        $query = Invoice::query()->with(['property', 'booking']);

        if ($property) {
            $query->where('property_id', $property->id);
        }

        if (filled($filters['search'] ?? null)) {
            $term = trim($filters['search']);
            $query->where(function ($q) use ($term) {
                $q->whereLike('invoice_number', "%{$term}%")
                    ->orWhereLike('folio_number', "%{$term}%")
                    ->orWhereLike('guest_name', "%{$term}%")
                    ->orWhereLike('party_name', "%{$term}%")
                    ->orWhereLike('booking_reference', "%{$term}%");
            });
        }

        if (filled($filters['invoice_generation_date_from'] ?? null)) {
            $query->whereDate('invoice_generation_date', '>=', $filters['invoice_generation_date_from']);
        }

        if (filled($filters['invoice_generation_date_to'] ?? null)) {
            $query->whereDate('invoice_generation_date', '<=', $filters['invoice_generation_date_to']);
        }

        if (filled($filters['invoice_number'] ?? null)) {
            $query->whereLike('invoice_number', '%'.trim($filters['invoice_number']).'%');
        }

        if (filled($filters['created_by'] ?? null)) {
            $query->where('generated_by_staff', $filters['created_by']);
        }

        if (filled($filters['booking_source'] ?? null)) {
            $query->where('booking_source', $filters['booking_source']);
        }

        if (filled($filters['booking_status'] ?? null)) {
            $statusTerm = strtolower(trim($filters['booking_status']));
            $statusMap = [
                'confirm' => 'confirmed',
                'confirmed' => 'confirmed',
                'cancel' => 'cancelled',
                'cancelled' => 'cancelled',
            ];
            $target = $statusMap[$statusTerm] ?? $statusTerm;
            $query->whereHas('booking', function ($bq) use ($target) {
                $bq->whereLike('status', "%{$target}%");
            });
        }

        if (filled($filters['has_attachment'] ?? null) && ! in_array(strtolower($filters['has_attachment']), ['please select', 'please_select', ''], true)) {
            $query->where('has_attachment', in_array(strtolower($filters['has_attachment']), ['yes', '1', 'true'], true));
        }

        if (filled($filters['document'] ?? null)) {
            $query->whereLike('document', '%'.trim($filters['document']).'%');
        }

        if (filled($filters['send_bill'] ?? null) && ! in_array(strtolower($filters['send_bill']), ['please select', 'please_select', ''], true)) {
            $query->where('send_bill', in_array(strtolower($filters['send_bill']), ['yes', '1', 'true'], true));
        }

        if (filled($filters['soa_generated'] ?? null) && ! in_array(strtolower($filters['soa_generated']), ['please select', 'please_select', ''], true)) {
            $query->where('soa_generated', in_array(strtolower($filters['soa_generated']), ['yes', '1', 'true'], true));
        }

        if (filled($filters['unit_type'] ?? null)) {
            $query->where('unit_type', $filters['unit_type']);
        }

        // Billed to: the guest, a company's City Ledger account, or a company that hasn't paid yet.
        match ($filters['billed_to'] ?? null) {
            'guest' => $query->whereNull('city_ledger_account_id'),
            'company' => $query->whereNotNull('city_ledger_account_id'),
            'company_unpaid' => $query->whereNotNull('city_ledger_account_id')->whereIn('booking_id', array_keys($this->companyOpenAmounts($property))),
            default => null,
        };

        return $query;
    }

    /**
     * What the property's company accounts are still owed, by booking.
     *
     * @return array<int, float>
     */
    private function companyOpenAmounts(?Property $property): array
    {
        $billing = app(CompanyBilling::class);
        $open = [];

        CityLedgerAccount::query()
            ->when($property, fn ($query) => $query->where('property_id', $property->id))
            ->each(function (CityLedgerAccount $account) use ($billing, &$open): void {
                $open += $billing->openByBooking($account);
            });

        return $open;
    }

    /**
     * @param  array<int, float>  $companyOpen  booking id => what its company still owes
     * @return array<string, mixed>
     */
    private function invoiceRow(Invoice $invoice, array $companyOpen = []): array
    {
        return [
            'id' => $invoice->id,
            'property_id' => $invoice->property_id,
            'booking_id' => $invoice->booking_id,
            'invoice_generation_date' => $invoice->invoice_generation_date?->toIso8601ZuluString(),
            'reservation_date' => $invoice->reservation_date?->toIso8601ZuluString(),
            'folio_number' => $invoice->folio_number ?? '—',
            'order_number' => $invoice->order_number ?? '—',
            'property_name' => $invoice->property?->name ?? '—',
            'outlet_name' => $invoice->outlet_name ?? 'Front Office',
            'unit_type' => $invoice->unit_type ?? 'Room',
            'invoice_number' => $invoice->invoice_number,
            'status' => $invoice->status?->value ?? (string) ($invoice->status ?? 'Bill Generated'),
            'paid_by' => $invoice->paid_by ?? 'Guest',
            'booking_source' => $invoice->booking_source ?? 'Direct',
            'guest_name' => $invoice->guest_name ?? '—',
            'agent_ota_name' => $invoice->agent_ota_name ?? '—',
            'corporate_account' => $invoice->corporate_account ?? '—',
            'corporate_id' => $invoice->corporate_id ?? '—',
            'city_ledger_account_id' => $invoice->city_ledger_account_id,
            'due_date' => $invoice->due_date?->toDateString(),
            'company_unpaid' => $invoice->city_ledger_account_id !== null ? ($companyOpen[$invoice->booking_id] ?? 0.0) : null,
            'last_receipt_date' => $invoice->last_receipt_date?->toDateString(),
            'invoice_amount' => (float) $invoice->invoice_amount,
            'taxable_amount' => (float) $invoice->taxable_amount,
            'total_tax' => (float) $invoice->total_tax,
            'amount_paid' => (float) $invoice->amount_paid,
            'tax_exempted' => (float) $invoice->tax_exempted,
            'tax_exemption_type' => $invoice->tax_exemption_type ?? '—',
            'invoice_outstanding_amount' => (float) $invoice->invoice_outstanding_amount,
            'generated_by_staff' => $invoice->generated_by_staff ?? '—',
            'booking_reference' => $invoice->booking_reference ?? '—',
            'days_in_outstanding' => $invoice->days_in_outstanding ?? 'No Outstanding',
            'sales_person' => $invoice->sales_person ?? '—',
            'promo_name' => $invoice->promo_name ?? '—',
            'document_attached' => $invoice->document_attached ?? '—',
            'party_name' => $invoice->party_name ?? $invoice->guest_name ?? '—',
            'party_gstin' => $invoice->party_gstin ?? '—',
            'show_tax_breakup' => (bool) $invoice->show_tax_breakup,
            'credited_total' => round((float) ($invoice->credited_total ?? 0), 2),
            'checkin_date' => $invoice->checkin_date?->format('Y-m-d') ?? '—',
            'checkout_date' => $invoice->checkout_date?->format('Y-m-d') ?? '—',
            'total_pax' => $invoice->total_pax ?? 1,
            'total_children' => $invoice->total_children ?? 0,
            'rooms_count' => $invoice->rooms_count ?? 1,
            'discount' => (float) $invoice->discount,
            'room_nights' => $invoice->room_nights ?? 1,
            'meal_plans' => $invoice->meal_plans ?? 'EP',
            'item_types' => $invoice->item_types ?? 'Room',
            'tally_status' => $invoice->tally_status ?? '—',
            'tally_response' => $invoice->tally_response ?? '—',
            'has_attachment' => (bool) $invoice->has_attachment,
            'document' => $invoice->document ?? '—',
            'send_bill' => (bool) $invoice->send_bill,
            'soa_generated' => (bool) $invoice->soa_generated,
        ];
    }

    private function hasEligibleInvoiceDateRangeForDownload(array $filters): bool
    {
        $from = $filters['invoice_generation_date_from'] ?? null;
        $to = $filters['invoice_generation_date_to'] ?? null;

        if (filled($from) && filled($to) && CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) <= 30) {
            return true;
        }

        return false;
    }

    public function enquiries(Request $request): Response
    {
        Booking::expireOverdueEnquiries();

        $selectedProperty = $this->selectedProperty($request);
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'reservation_status' => ['nullable', 'string', Rule::in(array_map(fn (BookingStatus $status): string => $status->value, BookingStatus::enquiryStatuses()))],
        ]);

        $enquiries = $this->filteredBookingsQuery($selectedProperty, $filters, enquiries: true)
            ->latest()
            ->paginate(15)
            ->withQueryString();

        $enquiries->through(fn (Booking $booking) => $this->bookingRow($booking));

        return Inertia::render('admin/databases/Enquiries', [
            'property' => $selectedProperty?->only(['id', 'name']),
            'enquiries' => $enquiries,
            'filters' => $filters,
        ]);
    }

    /**
     * A UTC instant from a row, as the property's local time for a CSV export (read as-is).
     */
    private function csvLocalTime(Property $property, ?string $instant): string
    {
        return filled($instant) ? $property->localTime(CarbonImmutable::parse($instant))->format('Y-m-d H:i:s') : '';
    }
}
