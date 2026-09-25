<?php

namespace App\Http\Controllers\Admin;

use App\Enums\BookingCheckInStatus;
use App\Enums\BookingStatus;
use App\Enums\PaymentLinkStatus;
use App\Enums\PaymentMode;
use App\Enums\SubBookingStatus;
use App\Enums\TransactionType;
use App\Http\Controllers\Concerns\CalculatesRoomTaxes;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Addon;
use App\Models\Agency;
use App\Models\Agent;
use App\Models\Booking;
use App\Models\BookingAddon;
use App\Models\BookingAttachment;
use App\Models\BookingChannel;
use App\Models\CityLedgerAccount;
use App\Models\CityLedgerEntry;
use App\Models\CompanyProfile;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Folio;
use App\Models\Guest;
use App\Models\GuestLedgerAccount;
use App\Models\InventoryUnit;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentLink;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\RoomUpgrade;
use App\Models\SubBooking;
use App\Models\SubFolio;
use App\Models\SupportedMetasearchPlatform;
use App\Models\Transaction;
use App\Models\User;
use App\Services\ApplicationSettings;
use App\Services\BookingAddons;
use App\Services\BookingChangeSettlement;
use App\Services\BookingDocumentTypes;
use App\Services\BookingEngineService;
use App\Services\BookingPricing;
use App\Services\CompanyBilling;
use App\Services\DormBeds;
use App\Services\ExchangeRates;
use App\Services\GuestLedger;
use App\Services\Housekeeping;
use App\Services\InvoiceEmails;
use App\Services\PaymentDistributionService;
use App\Services\PaymentModeSettings;
use App\Services\PermissionGate;
use App\Services\RatePackages;
use App\Services\RateRounding;
use App\Services\RoomInventory;
use App\Services\RoomStays;
use App\Services\StayRequirements;
use App\Services\StayRestrictions;
use App\Services\StayTimeCharges;
use App\Services\Waitlists;
use App\Services\WebCheckins;
use App\Services\WeekendHolidays;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BookingController extends Controller
{
    use CalculatesRoomTaxes;

    public function create(Request $request, Property $property, BookingDocumentTypes $documentTypes): Response
    {
        $props = $this->bookingFormProps($property, $documentTypes);

        return Inertia::render('admin/bookings/Create', [
            ...$props,
            'prefill' => $this->prefillFromQuery($request, $props['roomCategories']) ?? $this->prefillFromWaitlist($request, $property),
            'isHold' => $request->boolean('hold'),
            'isEnquiry' => ! $request->boolean('hold') && $request->boolean('enquiry'),
            'booking' => null,
        ]);
    }

    /**
     * Defaults for the Reminder section, from Booking Settings → Auto Release.
     *
     * @return array{duration_days: int, reminder_days: list<int>}
     */
    public function holdDefaults(Property $property): array
    {
        $settings = $this->metaFieldData($property, 'booking_settings_auto_release') ?? [];

        return [
            'duration_days' => max(0, (int) ($settings['duration_days'] ?? 0)),
            'reminder_days' => array_values(collect(Arr::wrap($settings['reminders'] ?? null))->pluck('days')->map(fn ($days): int => max(0, (int) $days))->all()),
        ];
    }

    /**
     * Validation rules for the Upload Documents rows, enforcing each type's
     * "Require Expiry Date" / "Require Document Number" setting.
     *
     * @return array<string, mixed>
     */
    private function attachmentRules(Request $request, Property $property, BookingDocumentTypes $documentTypes): array
    {
        $types = collect($documentTypes->forProperty($property))->keyBy('name');
        $rules = [
            'attachments' => ['array', 'max:20'],
            'attachments.*.document_type' => ['required', 'string', Rule::in($types->keys()->all())],
            'attachments.*.document_number' => ['nullable', 'string', 'max:100'],
            'attachments.*.expiry_date' => ['nullable', 'date_format:Y-m-d'],
            'attachments.*.file' => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,webp,heic,doc,docx'],
        ];

        foreach ((array) $request->input('attachments', []) as $index => $attachment) {
            $type = $types->get($attachment['document_type'] ?? null);

            if ($type['require_document_number'] ?? false) {
                $rules["attachments.{$index}.document_number"] = ['required', 'string', 'max:100'];
            }

            if ($type['require_expiry_date'] ?? false) {
                $rules["attachments.{$index}.expiry_date"] = ['required', 'date_format:Y-m-d'];
            }
        }

        return $rules;
    }

    /**
     * Dates and rooms pre-selected on the smart booking chart, passed as
     * `?check_in=Y-m-d&check_out=Y-m-d&room_ids=1,2,3`.
     *
     * @param  Collection<int, RoomCategory>  $roomCategories
     * @return array{check_in: string, check_out: string, rooms: list<array{room_category_id: int, room_id: int}>}|null
     */
    private function prefillFromQuery(Request $request, Collection $roomCategories): ?array
    {
        $checkIn = $request->query('check_in');
        $checkOut = $request->query('check_out');

        $isValidDate = fn (string $value): bool => CarbonImmutable::hasFormat($value, 'Y-m-d');

        if (! is_string($checkIn) || ! is_string($checkOut) || ! $isValidDate($checkIn) || ! $isValidDate($checkOut) || $checkOut <= $checkIn) {
            return null;
        }

        $requestedRoomIds = array_map('intval', array_filter(explode(',', (string) $request->query('room_ids', ''))));

        $rooms = array_values($roomCategories
            ->flatMap(fn (RoomCategory $category) => $category->rooms)
            ->filter(fn (Room $room): bool => in_array($room->id, $requestedRoomIds, true))
            ->map(fn (Room $room): array => ['room_category_id' => $room->room_category_id, 'room_id' => $room->id])
            ->all());

        return ['check_in' => $checkIn, 'check_out' => $checkOut, 'rooms' => $rooms];
    }

    /**
     * Converting a waitlist request (Databases → Waitlist → Convert, `?waitlist={id}`): its
     * dates, rooms and guest; saving the booking marks the request converted.
     *
     * @return array<string, mixed>|null
     */
    private function prefillFromWaitlist(Request $request, Property $property): ?array
    {
        $waitlistRequest = $request->filled('waitlist')
            ? $property->waitlistRequests()->open()->find($request->integer('waitlist'))
            : null;

        return $waitlistRequest === null ? null : app(Waitlists::class)->prefill($waitlistRequest);
    }

    /**
     * The booking was made for a waitlist request: close the request, and give the booking the
     * request's guest when none was picked.
     */
    private function convertWaitlistRequest(Request $request, Property $property, Booking $booking): void
    {
        if (! $request->filled('waitlist_request_id')) {
            return;
        }

        $waitlistRequest = $property->waitlistRequests()->open()->find($request->integer('waitlist_request_id'));

        if ($waitlistRequest === null) {
            return;
        }

        if ($waitlistRequest->guest_id !== null && $booking->guests()->doesntExist()) {
            $booking->guests()->attach($waitlistRequest->guest_id);
        }

        app(Waitlists::class)->markConverted($waitlistRequest, $booking);
    }

    public function roomAvailability(Request $request, Property $property, RoomInventory $roomInventory): JsonResponse
    {
        $data = $request->validate([
            'check_in' => ['required', 'date_format:Y-m-d'],
            'check_out' => ['required', 'date_format:Y-m-d', 'after:check_in'],
            'exclude_booking_id' => ['nullable', 'integer'],
        ]);
        $excludeBookingId = isset($data['exclude_booking_id']) ? (int) $data['exclude_booking_id'] : null;

        return response()->json([
            'availability' => $roomInventory->availabilityByCategory($property->id, $this->roomsCountByCategory($property), $data['check_in'], $data['check_out'], $excludeBookingId),
            'unavailable_room_ids' => $roomInventory->unavailableRoomIds($property->id, $data['check_in'], $data['check_out'], $excludeBookingId),
            // Dorm rooms: beds still free on every night of the stay.
            'free_beds' => $roomInventory->freeBedsByRoom($property->id, $data['check_in'], $data['check_out'], $excludeBookingId),
        ]);
    }

    /**
     * @return SupportCollection<int, int>
     */
    private function roomsCountByCategory(Property $property): SupportCollection
    {
        // Rooms, or beds for a dormitory.
        return app(RoomInventory::class)->capacityByCategory($property->id);
    }

    /**
     * Rejects booked/blocked or duplicate room numbers and categories without
     * enough free rooms, then fills "Auto" rooms with a free room of the category.
     *
     * @param  list<array<string, mixed>>  $rooms
     * @return list<array<string, mixed>>
     *
     * @throws ValidationException
     */
    private function assignAvailableRooms(Property $property, array $rooms, string $checkIn, string $checkOut, RoomInventory $roomInventory, ?int $excludeBookingId = null, bool $reservesRooms = true): array
    {
        $unavailableRoomIds = $roomInventory->unavailableRoomIds($property->id, $checkIn, $checkOut, $excludeBookingId);
        $requestedRoomIds = collect($rooms)->pluck('room_id')->filter()->map(fn (mixed $roomId): int => (int) $roomId);

        $roomCategoryById = Room::query()->whereIn('id', $requestedRoomIds)->pluck('room_category_id', 'id');
        $mismatchedRoom = collect($rooms)->first(fn (array $room): bool => ! empty($room['room_id'])
            && (int) ($roomCategoryById[$room['room_id']] ?? 0) !== (int) $room['room_category_id']);

        if ($mismatchedRoom !== null) {
            throw ValidationException::withMessages(['rooms' => 'A selected room number does not belong to its room category.']);
        }

        // Enquiries don't take rooms, so they are neither checked against availability nor given a room.
        if (! $reservesRooms) {
            return $rooms;
        }

        $takenRoomNames = Room::query()
            ->whereIn('id', $requestedRoomIds->intersect($unavailableRoomIds))
            ->orderBy('name')
            ->pluck('name');

        if ($takenRoomNames->isNotEmpty()) {
            throw ValidationException::withMessages([
                'rooms' => 'These rooms are booked or blocked for the selected dates: '.$takenRoomNames->implode(', ').'. Unblock them or pick other rooms.',
            ]);
        }

        // A dorm room can be picked once per free bed; any other room only once.
        $freeBeds = $roomInventory->freeBedsByRoom($property->id, $checkIn, $checkOut, $excludeBookingId);
        $requestedCounts = collect(array_count_values($requestedRoomIds->all()));

        if ($requestedCounts->contains(fn (int $times, int $roomId): bool => ! $freeBeds->has($roomId) && $times > 1)) {
            throw ValidationException::withMessages(['rooms' => 'The same room number is selected more than once.']);
        }

        $overbookedDorms = $requestedCounts->filter(fn (int $times, int $roomId): bool => $freeBeds->has($roomId) && $times > $freeBeds[$roomId]);

        if ($overbookedDorms->isNotEmpty()) {
            throw ValidationException::withMessages(['rooms' => 'Not enough free beds for the selected dates in: '.Room::query()->whereIn('id', $overbookedDorms->keys())->orderBy('name')->pluck('name')->implode(', ').'.']);
        }

        foreach ($requestedCounts as $roomId => $times) {
            if ($freeBeds->has($roomId)) {
                $freeBeds->put($roomId, $freeBeds[$roomId] - $times);
            }
        }

        $availability = $roomInventory->availabilityByCategory($property->id, $this->roomsCountByCategory($property), $checkIn, $checkOut, $excludeBookingId);
        $shortCategories = collect($rooms)->countBy('room_category_id')
            ->filter(fn (int $requested, int $categoryId): bool => $requested > (int) ($availability[$categoryId] ?? 0));

        if ($shortCategories->isNotEmpty()) {
            $names = RoomCategory::query()->whereIn('id', $shortCategories->keys())->pluck('name')->implode(', ');

            throw ValidationException::withMessages(['rooms' => "Not enough free rooms for the selected dates in: {$names}."]);
        }

        $takenRoomIds = $unavailableRoomIds->merge($requestedRoomIds);

        return array_map(function (array $room) use (&$takenRoomIds, $roomInventory, $freeBeds): array {
            if (empty($room['room_id'])) {
                $room['room_id'] = $roomInventory->firstFreeRoomId((int) $room['room_category_id'], $takenRoomIds, $freeBeds);
                $takenRoomIds = $takenRoomIds->push($room['room_id']);
            }

            return $room;
        }, $rooms);
    }

    /**
     * Room checks for a split booking, room by room on each room's own dates. Rooms
     * earlier in the list whose nights overlap count as taken, so two rooms of the
     * booking are never given the same room for the same night.
     *
     * @param  array<int, array<string, mixed>>  $rooms  the validated rooms (keys as the form sent them)
     * @return list<array<string, mixed>>
     *
     * @throws ValidationException
     */
    private function assignRoomsForOwnDates(Property $property, array $rooms, RoomInventory $roomInventory, int $bookingId): array
    {
        $roomsCount = $this->roomsCountByCategory($property);
        $placed = [];

        $dormRoomIds = $roomInventory->activeBedsByRoom(null, $property->id)->keys();

        if (collect($rooms)->pluck('room_id')->filter()->reject(fn ($roomId): bool => $dormRoomIds->contains((int) $roomId))->duplicates()->isNotEmpty()) {
            throw ValidationException::withMessages(['rooms' => 'The same room number is selected more than once.']);
        }

        foreach (array_values($rooms) as $index => $room) {
            $checkIn = $room['check_in_date'];
            $checkOut = $room['check_out_date'];
            $label = 'Room '.($index + 1);

            if ($checkOut <= $checkIn) {
                throw ValidationException::withMessages(["rooms.{$index}.check_out_date" => "{$label}: check-out must be after check-in."]);
            }

            if (! empty($room['room_id']) && (int) Room::query()->whereKey($room['room_id'])->value('room_category_id') !== (int) $room['room_category_id']) {
                throw ValidationException::withMessages(['rooms' => "{$label}: the room number does not belong to its room category."]);
            }

            $overlapping = collect($placed)->filter(fn (array $other): bool => $other['check_in_date'] < $checkOut && $other['check_out_date'] > $checkIn);
            // Beds this booking's earlier rooms already use on overlapping nights.
            $freeBeds = $roomInventory->freeBedsByRoom($property->id, $checkIn, $checkOut, $bookingId)
                ->map(fn (int $beds, int $roomId): int => $beds - $overlapping->where('room_id', $roomId)->count());
            $taken = $roomInventory->unavailableRoomIds($property->id, $checkIn, $checkOut, $bookingId)
                ->merge($overlapping->pluck('room_id')->filter()->reject(fn ($roomId): bool => $dormRoomIds->contains((int) $roomId)))
                ->merge($freeBeds->filter(fn (int $beds): bool => $beds < 1)->keys());
            $free = (int) ($roomInventory->availabilityByCategory($property->id, $roomsCount, $checkIn, $checkOut, $bookingId)[$room['room_category_id']] ?? 0)
                - $overlapping->where('room_category_id', $room['room_category_id'])->count();

            if ($free < 1) {
                throw ValidationException::withMessages(['rooms' => "{$label}: no room of its type is free for {$checkIn} → {$checkOut}."]);
            }

            if (! empty($room['room_id']) && $taken->contains((int) $room['room_id'])) {
                throw ValidationException::withMessages(['rooms' => "{$label}: ".Room::query()->whereKey($room['room_id'])->value('name').' is booked or blocked for its dates.']);
            }

            $room['room_id'] = ! empty($room['room_id']) ? (int) $room['room_id'] : $roomInventory->firstFreeRoomId((int) $room['room_category_id'], $taken, $freeBeds);
            $placed[] = $room;
        }

        return $placed;
    }

    public function rateSuggestions(Request $request, Property $property): JsonResponse
    {
        $data = $request->validate([
            'check_in' => ['required', 'date'],
            'check_out' => ['required', 'date', 'after:check_in'],
        ]);

        $ratePlanData = $this->metaFieldData($property, 'rate_settings_rate_plan');
        $plannerData = $this->metaFieldData($property, 'rate_settings_rate_planner');

        $plans = collect(Arr::wrap($ratePlanData['plans'] ?? null))->keyBy('id');
        $defaultPlan = $plans->firstWhere('mapping_status', 'default') ?? $plans->firstWhere('active', true);
        $assignments = $plannerData['assignments'] ?? [];

        $dayKeyByIsoWeekday = [0 => 'sun', 1 => 'mon', 2 => 'tue', 3 => 'wed', 4 => 'thu', 5 => 'fri', 6 => 'sat'];

        $checkIn = CarbonImmutable::parse($data['check_in']);
        $checkOut = CarbonImmutable::parse($data['check_out']);

        $nights = [];

        for ($date = $checkIn; $date->lt($checkOut); $date = $date->addDay()) {
            $planId = $assignments[$date->format('Y-m-d')] ?? $defaultPlan['id'] ?? null;

            $nights[] = [
                'date' => $date->format('Y-m-d'),
                'day_key' => $dayKeyByIsoWeekday[(int) $date->format('w')],
                'plan_id' => $planId,
            ];
        }

        $usedPlanIds = collect($nights)->pluck('plan_id')->filter()->unique()->values();

        // Suggested as the property sells them (Rate Settings → Rounding); staff can still type their own.
        $rounding = app(RateRounding::class);
        $rates = $usedPlanIds->mapWithKeys(fn (int $planId) => [$planId => collect(Arr::wrap($plans->get($planId)['rates']['direct'] ?? null))
            ->map(fn (array $days): array => array_map(fn ($rate) => is_numeric($rate) ? $rounding->apply($property, (float) $rate) : $rate, $days))
            ->all()]);

        return response()->json([
            'nights' => $nights,
            'rates' => $rates,
            'taxComponents' => $this->activeRoomTaxComponents($property),
        ]);
    }

    /**
     * The packages a room can be sold under for the dates (Rate Settings → Package), with their
     * rate per night, and whether Rate Settings → Restrictions allow the stay. For the booking form.
     */
    public function packageOffers(Request $request, Property $property): JsonResponse
    {
        $data = $request->validate([
            'check_in' => ['required', 'date_format:Y-m-d'],
            'check_out' => ['required', 'date_format:Y-m-d', 'after:check_in'],
            'room_category_id' => ['required', 'integer', Rule::exists('room_categories', 'id')->where('property_id', $property->id)],
            'meal_plan' => ['nullable', 'string', 'max:50'],
            'adults' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);

        $category = RoomCategory::query()->findOrFail($request->integer('room_category_id'));
        $mealPlans = ! empty($category->meal_plans) ? $category->meal_plans : ['EP'];
        $mealPlan = $data['meal_plan'] ?? $mealPlans[0];

        return response()->json([
            'packages' => app(RatePackages::class)->offers($property, $category, $mealPlan, (int) ($data['adults'] ?? 1), $data['check_in'], $data['check_out']),
            'restriction' => app(StayRestrictions::class)->violation($property->id, $category->id, $data['check_in'], $data['check_out']),
        ]);
    }

    public function store(Request $request, Property $property, RoomInventory $roomInventory, BookingDocumentTypes $documentTypes): RedirectResponse|JsonResponse
    {
        $data = $this->validateBookingForm($request, $property, $documentTypes, ['confirmed', 'on_hold', 'enquiry']);
        app(PermissionGate::class)->reservation($request->user(), $property, 'create', $data['status'], $data['check_in']);
        $data = $this->applyStayRules($request, $property, $data);
        $status = BookingStatus::from($data['status']);
        $data['rooms'] = $this->assignAvailableRooms($property, $data['rooms'], $data['check_in'], $data['check_out'], $roomInventory, reservesRooms: $status !== BookingStatus::Enquiry);
        $totals = $this->calculateTotals($property, $data);
        $isHold = in_array($status, [BookingStatus::OnHold, BookingStatus::Enquiry], true);
        $companyAccountId = $this->companyAccountId($property, $data, $totals, $status);

        $booking = DB::transaction(function () use ($data, $property, $totals, $isHold, $request, $companyAccountId): Booking {
            $booking = $property->bookings()->create([
                ...$this->bookingAttributes($data, $totals, $isHold),
                'city_ledger_account_id' => $companyAccountId,
                'booking_ref_id' => ($data['booking_ref_id'] ?? null) ?: Str::upper(Str::random(8)),
                'status' => $data['status'],
                'outstanding' => $totals['total'],
            ]);

            foreach ($data['rooms'] as $room) {
                $booking->rooms()->create($room);
            }

            if (! empty($data['addons'])) {
                app(BookingAddons::class)->sync($booking, $data['addons'], $request->expectsJson() ? 'api' : 'front_desk', $request->user());
            }

            $this->storeAttachments($booking, $data['attachments'] ?? [], $request);

            if (! empty($data['guest_ids'])) {
                $booking->guests()->attach($data['guest_ids']);
                Payment::logForBooking($booking);
            }

            $this->convertWaitlistRequest($request, $property, $booking);

            ActivityLog::create([
                'user_id' => $request->user()->id,
                'property_id' => $property->id,
                'booking_id' => $booking->id,
                'guest_id' => $booking->guests()->first()?->id,
                'action' => 'Reservation created via '.($request->expectsJson() ? 'API' : 'Web'),
                'date' => now(),
            ]);

            return $booking;
        });

        // The API (mobile app): the new booking and the same message, as JSON.
        if ($request->expectsJson()) {
            return response()->json([
                'message' => match ($booking->status) {
                    BookingStatus::Enquiry => "Enquiry {$booking->booking_ref_id} logged.",
                    BookingStatus::OnHold => "Booking {$booking->booking_ref_id} put on hold until {$property->localTime($booking->hold_release_at)->format('d M Y, h:i A')}.",
                    default => "Booking {$booking->booking_ref_id} created.",
                },
                'booking' => ['id' => $booking->id, 'booking_ref_id' => $booking->booking_ref_id, 'status' => $booking->status->value],
            ], 201);
        }

        if ($booking->status === BookingStatus::Enquiry) {
            return redirect()->route('admin.databases.enquiries.index', ['property' => $property->id])
                ->with('success', "Enquiry {$booking->booking_ref_id} logged. The quote expires on {$property->localTime($booking->hold_release_at)->format('d M Y, h:i A')}.");
        }

        $message = $booking->status === BookingStatus::OnHold
            ? "Booking {$booking->booking_ref_id} put on hold until {$property->localTime($booking->hold_release_at)->format('d M Y, h:i A')}."
            : "Booking {$booking->booking_ref_id} created.";

        // "Save & Add Payment" keeps working on the booking: the same form, now editing it.
        if ($request->input('after') === 'edit') {
            return redirect()->route('admin.bookings.edit', [$property, $booking])->with('success', $message);
        }

        return redirect()->route('admin.dashboard', ['property' => $property->id])->with('success', $message);
    }

    public function edit(Request $request, Property $property, Booking $booking, BookingDocumentTypes $documentTypes): Response|RedirectResponse
    {
        abort_unless($booking->property_id === $property->id, 404);

        if (! $this->isEditable($booking)) {
            return redirect()->route('admin.bookings.show', [$property, $booking])
                ->with('error', "A {$booking->status->label()} booking can't be edited.");
        }

        $booking->load(['rooms.inventoryUnit', 'guests', 'attachments.user:id,name']);

        return Inertia::render('admin/bookings/Create', [
            ...$this->bookingFormProps($property, $documentTypes),
            'prefill' => null,
            'isHold' => $booking->status === BookingStatus::OnHold,
            'isEnquiry' => $booking->status === BookingStatus::Enquiry,
            'booking' => [
                'id' => $booking->id,
                'booking_ref_id' => $booking->booking_ref_id,
                'source' => $booking->source,
                'agent' => $booking->agent?->toOption(),
                'booking_channel_id' => $booking->booking_channel_id,
                'booking_channel' => $booking->bookingChannel?->name,
                'metasearch_platform_id' => $booking->metasearch_platform_id,
                'metasearch_platform' => $booking->metasearchPlatform?->name,
                'type' => $booking->type,
                'booking_type' => $booking->booking_type,
                'check_in' => $booking->check_in->toDateString(),
                'check_out' => $booking->check_out->toDateString(),
                'check_in_time' => $booking->check_in_time,
                'check_out_time' => $booking->check_out_time,
                'rooms_split' => (bool) $booking->rooms_split,
                'same_plan_for_all_rooms' => (bool) $booking->same_plan_for_all_rooms,
                'pay_at_hotel' => (bool) $booking->pay_at_hotel,
                'rate_plan' => $booking->rate_plan,
                'discount' => (float) $booking->discount,
                'status' => $booking->status->value,
                'room_bill_to' => $booking->room_bill_to,
                'extras_bill_to' => $booking->extras_bill_to,
                'bill_room_to_guest_company' => (bool) $booking->bill_room_to_guest_company,
                'bill_extras_to_guest_company' => (bool) $booking->bill_extras_to_guest_company,
                'company_billing' => $this->companyBilling($booking),
                'no_post' => (bool) $booking->no_post,
                'special_instructions' => $booking->special_instructions,
                'internal_notes' => $booking->internal_notes,
                'copy_to' => $booking->copy_to ?? [],
                'inclusions' => (object) ($booking->inclusions ?? []),
                'hold_release_at' => $booking->hold_release_at?->toIso8601ZuluString(),
                'hold_auto_release' => (bool) $booking->hold_auto_release,
                'hold_reminders' => $booking->hold_reminders ?? [],
                'rooms' => $booking->rooms->map(fn ($room): array => [
                    'room_category_id' => $room->room_category_id,
                    'room_id' => $room->room_id,
                    // A dorm stay's bed (its lane on the Smart Chart); null for a whole room.
                    'bed' => $room->inventoryUnit?->unit_type === InventoryUnit::TYPE_BED ? app(DormBeds::class)->bedNumber($room->inventoryUnit) : null,
                    'meal_plan' => $room->meal_plan,
                    'package_id' => $room->package_id,
                    'adults' => $room->adults,
                    'children' => $room->children,
                    'infants' => $room->infants,
                    'rate_per_night' => (float) $room->rate_per_night,
                    'check_in_date' => $room->check_in_date->toDateString(),
                    'check_out_date' => $room->check_out_date->toDateString(),
                ])->values()->all(),
                'guests' => $booking->guests->values()->all(),
                'addons' => $booking->addons()->get()->map(fn (BookingAddon $line): array => $line->toLine())->values()->all(),
                'attachments' => $booking->attachments->sortByDesc('id')->map(fn (BookingAttachment $attachment): array => $this->attachmentPayload($property, $booking, $attachment))->values()->all(),
            ],
            'billing' => $this->billingProps($request, $property, $booking),
            // For the change dialog on Save: the bill as saved, what is paid beyond it, and the
            // cancellation policy for a change made today (the server recomputes on save).
            'changeSettlement' => (function () use ($property, $booking): array {
                $settlement = app(BookingChangeSettlement::class);

                return [
                    'saved_bill' => $settlement->billTotal($booking),
                    'balance' => round($booking->outstandingBalance(), 2),
                    ...$settlement->policy($property, CarbonImmutable::parse($booking->check_in->toDateString())),
                    'transfer_targets' => $settlement->transferTargets($booking),
                    // The guest's ledger at the property; null when the booking has no guest.
                    'guest_ledger' => $booking->primaryGuest ? [
                        'guest_name' => trim("{$booking->primaryGuest->first_name} {$booking->primaryGuest->last_name}"),
                        'balance' => app(GuestLedger::class)->balance($property->id, $booking->primaryGuest),
                    ] : null,
                    // Settings → Booking → Payment Settings: the modes offered for refunds.
                    'refund_modes' => collect(app(PaymentModeSettings::class)->refundModes($property))->map(fn (string $mode): array => ['value' => $mode, 'label' => $mode])->values()->all(),
                ];
            })(),
        ]);
    }

    public function update(Request $request, Property $property, Booking $booking, RoomInventory $roomInventory, BookingDocumentTypes $documentTypes): RedirectResponse|JsonResponse
    {
        abort_unless($booking->property_id === $property->id, 404);
        abort_unless($this->isEditable($booking), 403, "A {$booking->status->label()} booking can't be edited.");
        app(PermissionGate::class)->reservation($request->user(), $property, 'edit', $booking->status, $booking->check_in->toDateString());

        $data = $this->validateBookingForm($request, $property, $documentTypes, [$booking->status->value], [
            'removed_attachment_ids' => ['array'],
            'removed_attachment_ids.*' => ['integer'],
            // A split booking's rooms each carry their own dates.
            ...($booking->rooms_split ? [
                'rooms.*.check_in_date' => ['required', 'date_format:Y-m-d'],
                'rooms.*.check_out_date' => ['required', 'date_format:Y-m-d'],
            ] : []),
        ]);
        $isEnquiry = $booking->status === BookingStatus::Enquiry;
        // What to do when the change lowers the bill: the policy's charge, and any credit left.
        $settlementChoice = $request->validate([
            'apply_cancellation_charge' => ['nullable', 'boolean'],
            'credit_action' => ['nullable', Rule::in(BookingChangeSettlement::ACTIONS)],
            // Only a mode switched on for refunds (Settings → Booking → Payment Settings).
            'refund_mode' => ['nullable', 'required_if:credit_action,refund', Rule::in(app(PaymentModeSettings::class)->refundModes($property))],
            'refund_reference' => ['nullable', 'string', 'max:255'],
            'refund_notes' => ['nullable', 'string', 'max:1000'],
            'transfer_booking_id' => ['nullable', 'required_if:credit_action,transfer', 'integer', Rule::exists('bookings', 'id')->where('property_id', $property->id)->whereNot('id', $booking->id)],
        ], [
            'refund_mode.required_if' => 'Choose how the refund is paid.',
            'transfer_booking_id.required_if' => 'Choose the booking to move the credit to.',
        ]);
        $settlement = app(BookingChangeSettlement::class);
        $billBefore = $settlement->billTotal($booking);
        $checkInBefore = CarbonImmutable::parse($booking->check_in->toDateString());

        if ($booking->rooms_split) {
            $data['rooms'] = $this->assignRoomsForOwnDates($property, $data['rooms'], $roomInventory, $booking->id);
            // The booking spans its rooms.
            $data['check_in'] = collect($data['rooms'])->min('check_in_date');
            $data['check_out'] = collect($data['rooms'])->max('check_out_date');
        } else {
            $data['rooms'] = $this->assignAvailableRooms($property, $data['rooms'], $data['check_in'], $data['check_out'], $roomInventory, $booking->id, reservesRooms: ! $isEnquiry);
        }
        $data = $this->applyStayRules($request, $property, $data, $booking);
        $totals = $this->calculateTotals($property, $data);
        $isHold = $isEnquiry || $booking->status === BookingStatus::OnHold;
        $companyAccountId = $this->companyAccountId($property, $data, $totals, $booking->status, $booking);

        DB::transaction(function () use ($booking, $data, $totals, $isHold, $isEnquiry, $request, $property, $companyAccountId): void {
            $booking->update([
                ...$this->bookingAttributes($data, $totals, $isHold),
                'city_ledger_account_id' => $companyAccountId,
                'booking_ref_id' => ($data['booking_ref_id'] ?? null) ?: $booking->booking_ref_id,
                // What's owed comes from the ledger (BookingLedgerSync); an enquiry has no
                // ledger and nothing paid, so it simply owes its quoted total.
                ...($isEnquiry ? ['outstanding' => $totals['total']] : []),
            ]);

            // Rooms are updated in place so each keeps its identity (and its room folio);
            // only rooms beyond the new count are removed, through Eloquent so the
            // ledger mirror can move their folios onto the booking first.
            $existingRooms = $booking->rooms()->orderBy('id')->get()->values();

            foreach (array_values($data['rooms']) as $index => $room) {
                if ($existingRoom = $existingRooms->get($index)) {
                    $existingRoom->update($room);
                } else {
                    $booking->rooms()->create($room);
                }
            }

            $existingRooms->slice(count($data['rooms']))->each->delete();

            $booking->guests()->sync($data['guest_ids'] ?? []);

            // Add-ons are set when the form sends them; kept ones are repriced for the new stay.
            if (array_key_exists('addons', $data)) {
                app(BookingAddons::class)->sync($booking, $data['addons'], $request->expectsJson() ? 'api' : 'front_desk', $request->user());
            }

            $removedAttachments = $booking->attachments()->whereIn('id', $data['removed_attachment_ids'] ?? [])->get();

            foreach ($removedAttachments as $attachment) {
                Storage::disk('local')->delete($attachment->path);
                $attachment->delete();
            }

            $this->storeAttachments($booking, $data['attachments'] ?? [], $request);

            ActivityLog::create([
                'user_id' => $request->user()->id,
                'property_id' => $property->id,
                'booking_id' => $booking->id,
                'guest_id' => $booking->guests()->first()?->id,
                'action' => 'Reservation modified via Web',
                'date' => now(),
            ]);
        });

        // Enquiries have no ledger, so nothing to charge or settle.
        $settled = $isEnquiry ? [] : $settlement->settle($booking, $billBefore, $checkInBefore, $settlementChoice, $request->user());
        $message = "Booking {$booking->booking_ref_id} updated".($settled === [] ? '.' : ': '.implode(', ', $settled).'.');

        // The API: the same message and the booking, as JSON.
        if ($request->expectsJson()) {
            return response()->json([
                'message' => $message,
                'booking' => ['id' => $booking->id, 'booking_ref_id' => $booking->booking_ref_id, 'status' => $booking->status->value],
            ]);
        }

        return redirect()->route('admin.bookings.show', [$property, $booking])
            ->with('success', $message);
    }

    /**
     * Who a folio window can be billed to: the booking's guests, or a company of the property.
     *
     * @return array{guests: list<array{id: int, name: string}>, companies: list<array{id: int, name: string}>}
     */
    private function folioPayers(Property $property, Booking $booking): array
    {
        return [
            'guests' => array_values($booking->guests()->get()->map(fn (Guest $guest): array => ['id' => $guest->id, 'name' => trim("{$guest->first_name} {$guest->last_name}")])->all()),
            'companies' => array_values(CompanyProfile::query()
                ->where(fn ($query) => $query->where('property_id', $property->id)->orWhereNull('property_id'))
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (CompanyProfile $company): array => ['id' => $company->id, 'name' => $company->name])
                ->all()),
        ];
    }

    /**
     * Every folio of the booking — one per room (its own invoice) plus the booking's
     * master folio(s) — with each billing window's entries and balance.
     *
     * @return list<array<string, mixed>>
     */
    public function folioPayload(Booking $booking): array
    {
        $folios = $booking->allFoliosQuery()
            ->with(['owner', 'subFolios.payer', 'subFolios.transactions' => fn ($query) => $query->with('chargeCode:id,code,name')->orderBy('posted_at')->orderBy('id')])
            ->orderBy('id')
            ->get();

        $folios->loadMorph('owner', [SubBooking::class => ['inventoryUnit:id,name', 'roomCategory:id,name']]);

        // Rooms are numbered as the booking page's room details number them (in booking order),
        // since different categories can have rooms with the same number.
        $ordinals = $booking->rooms()->orderBy('id')->pluck('id')->flip()->map(fn (int $index): int => $index + 1);
        // The booking's oldest folio is its master folio; any others were added for another payer.
        $masterId = $folios->first(fn (Folio $folio): bool => ! $folio->owner instanceof SubBooking)?->id;

        return array_values($folios
            ->sortBy(fn (Folio $folio): int => $folio->owner instanceof SubBooking ? $folio->owner->id : PHP_INT_MAX)
            ->map(fn (Folio $folio): array => [
                'id' => $folio->id,
                'label' => $folio->owner instanceof SubBooking
                    ? 'Room '.($ordinals[$folio->owner->id] ?? '?').' | '.($folio->owner->roomCategory->name ?? 'Room')
                    : ($folio->id === $masterId ? 'Booking (master folio)' : 'Booking (additional folio)'),
                'room_number' => $folio->owner instanceof SubBooking ? ($folio->owner->inventoryUnit->name ?? 'Unassigned') : null,
                'is_room' => $folio->owner instanceof SubBooking,
                // The room behind a room folio: its own stay status and dates (early check-outs differ).
                'stay' => $folio->owner instanceof SubBooking ? [
                    'sub_booking_id' => $folio->owner->id,
                    'status' => $folio->owner->status->value,
                    'check_in_date' => $folio->owner->check_in_date->toDateString(),
                    'check_out_date' => $folio->owner->check_out_date->toDateString(),
                ] : null,
                'status' => $folio->status->value,
                'currency' => $folio->currency,
                'windows' => $folio->subFolios->map(fn (SubFolio $window): array => [
                    'id' => $window->id,
                    'name' => $window->window_name,
                    // Who settles this window when it isn't the folio owner.
                    'payer' => $window->payer ? [
                        'type' => $window->payer_type,
                        'id' => $window->payer_id,
                        'name' => $window->payer instanceof Guest ? trim("{$window->payer->first_name} {$window->payer->last_name}") : $window->payer->getAttribute('name'),
                    ] : null,
                    'balance' => round($window->transactions->sum(fn (Transaction $entry): float => $entry->transaction_type === TransactionType::Debit ? (float) $entry->amount : -(float) $entry->amount), 2),
                    'entries' => $window->transactions->map(fn (Transaction $entry): array => [
                        'id' => $entry->id,
                        'date' => $entry->posted_at->toIso8601ZuluString(),
                        'code' => $entry->chargeCode?->code,
                        'description' => $entry->description ?? $entry->chargeCode?->name,
                        'outlet_bill_id' => $entry->outlet_bill_id,
                        'type' => $entry->transaction_type->value,
                        'amount' => (float) $entry->amount,
                    ])->values()->all(),
                ])->values()->all(),
            ])
            ->all());
    }

    /**
     * Booking summary card data for the Smart Booking Chart popover.
     */
    public function summary(Property $property, Booking $booking): JsonResponse
    {
        abort_unless($booking->property_id === $property->id, 404);

        $booking->load(['rooms.roomCategory:id,name', 'guests']);

        $guest = $booking->guests->first();
        // Like the chart bar: without a guest, the first "Copy To" recipient names the booking.
        $recipient = $booking->copy_to[0] ?? null;

        return response()->json([
            'id' => $booking->id,
            'booking_ref_id' => $booking->booking_ref_id,
            'status' => $booking->status->value,
            'status_label' => $booking->status->label(),
            'check_in' => $booking->check_in->toDateString(),
            'check_out' => $booking->check_out->toDateString(),
            'nights' => max(1, (int) $booking->check_in->diffInDays($booking->check_out)),
            // One line per room category & meal plan, like the booking form (resources/js/lib/roomSummaryLines.ts).
            'room_lines' => $booking->rooms
                ->groupBy(fn ($room): string => ($room->roomCategory->name ?? '—').'|'.($room->meal_plan ?: '—'))
                ->map(fn ($rooms): string => $rooms->count().' - '.($rooms->first()->roomCategory->name ?? '—').' - '.($rooms->first()->meal_plan ?: '—').' X '.$rooms->sum('adults'))
                ->values()
                ->all(),
            'guest' => $guest ? [
                'name' => trim(collect([$guest->title, $guest->first_name, $guest->last_name])->filter()->implode(' ')),
                'phone' => trim(($guest->phone_country_code ?? '').' '.($guest->phone ?? '')) ?: null,
                'email' => $guest->emails[0] ?? null,
            ] : ($recipient ? [
                'name' => (string) ($recipient['name'] ?? ''),
                'phone' => trim(($recipient['phone_country_code'] ?? '').' '.($recipient['phone'] ?? '')) ?: null,
                'email' => $recipient['email'] ?? null,
            ] : null),
            'source' => $booking->source,
            'total' => (float) $booking->total,
            'outstanding' => (float) $booking->outstanding,
            'editable' => $this->isEditable($booking),
        ]);
    }

    private function isEditable(Booking $booking): bool
    {
        return in_array($booking->status, [BookingStatus::Confirmed, BookingStatus::OnHold, BookingStatus::CheckedIn, BookingStatus::Pending, BookingStatus::Enquiry], true);
    }

    /**
     * Props shared by the add and edit booking form.
     *
     * @return array<string, mixed>
     */
    private function bookingFormProps(Property $property, BookingDocumentTypes $documentTypes): array
    {
        return [
            'property' => $property->only(['id', 'name', 'country_id']),
            'roomCategories' => RoomCategory::query()
                ->where('property_id', $property->id)
                ->with('rooms:id,room_category_id,name')
                ->orderBy('display_order')
                ->get(['id', 'name', 'abbreviation', 'display_order', 'meal_plans', 'max_adults', 'max_children', 'ideal_occupancy', 'default_number_of_children', 'inclusion_details', 'sell_by_bed']),
            'countries' => Country::query()->orderBy('name')->get(['id', 'name', 'iso2', 'phonecode']),
            'holdDefaults' => $this->holdDefaults($property),
            'documentTypes' => $documentTypes->forProperty($property),
            'typeOptions' => app(ApplicationSettings::class)->activeReservationSubtypes(),
            // Marked on the date picker (Properties → Policies → Weekends & Holidays).
            'holidays' => app(WeekendHolidays::class)->get($property)['holidays'],
            // For adding an agent from the booking form's agent picker.
            'agencies' => $property->agencies()->orderBy('name')->get(['id', 'name', 'agent_rate_code_id']),
            'agentRateCodes' => $property->agentRateCodes()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'otaChannels' => BookingChannel::query()->active()->get(['id', 'name']),
            // Meta is offered only with "Enable Meta search engine listings" on (Edit property),
            // listing the engines the property has a listing ID for.
            'metaPlatforms' => $this->listedMetasearchPlatforms($property)->map(fn (SupportedMetasearchPlatform $platform): array => $platform->only(['id', 'name']))->values(),
            'reminderChannels' => app(ApplicationSettings::class)->reminderChannels(),
            // Add-on services the booking can include (Property → Add-ons).
            'addonCatalogue' => app(BookingAddons::class)->catalogue($property)->map(fn (Addon $addon): array => $addon->toOption())->values(),
        ];
    }

    /**
     * The metasearch engines the property is listed on (a listing ID saved under Edit property),
     * when its "Enable Meta search engine listings" is on.
     *
     * @return Collection<int, SupportedMetasearchPlatform>
     */
    private function listedMetasearchPlatforms(Property $property): Collection
    {
        if (! $property->metasearch_engines_listings_enabled) {
            return new Collection;
        }

        return $property->metasearchPlatforms()->wherePivotNotNull('listing_id')->wherePivot('listing_id', '!=', '')->orderBy('name')->get();
    }

    /**
     * @param  list<string>  $allowedStatuses
     * @param  array<string, mixed>  $extraRules
     * @return array<string, mixed>
     */
    private function validateBookingForm(Request $request, Property $property, BookingDocumentTypes $documentTypes, array $allowedStatuses, array $extraRules = []): array
    {
        $data = $request->validate([
            ...$this->attachmentRules($request, $property, $documentTypes),
            ...$extraRules,
            'hold_release_at' => ['exclude_unless:status,on_hold,enquiry', 'required', 'date', 'after:now'],
            'hold_auto_release' => ['exclude_unless:status,on_hold', 'boolean'],
            'hold_reminders' => ['exclude_unless:status,on_hold,enquiry', 'array', 'max:10'],
            'hold_reminders.*.channel' => ['required', 'string', Rule::in(array_column(app(ApplicationSettings::class)->reminderChannels(), 'value'))],
            'hold_reminders.*.days' => ['required', 'integer', 'min:0', 'max:365'],
            'hold_reminders.*.relative_to' => ['required', 'string', 'in:hold_release_date,check_in_date'],
            'booking_ref_id' => ['nullable', 'string', 'max:255'],
            'source' => ['required', 'string', 'max:255'],
            // An agent booking names the agency contact it came through (Databases → Agents).
            'agent_id' => ['nullable', 'required_if:source,'.Booking::SOURCE_TRAVEL_AGENT, 'integer', Rule::exists(Agent::class, 'id')
                ->where(fn ($query) => $query->whereIn('agency_id', Agency::query()->where('property_id', $property->id)->select('id')))],
            // An OTA booking names its OTA.
            'booking_channel_id' => ['nullable', 'required_if:source,'.Booking::SOURCE_OTA, 'integer', Rule::exists(BookingChannel::class, 'id')],
            // A Meta booking names the metasearch engine (its "Type") — one the property is listed on.
            'metasearch_platform_id' => ['nullable', 'required_if:source,'.Booking::SOURCE_META, 'integer', Rule::in($this->listedMetasearchPlatforms($property)->modelKeys())],
            'type' => ['required', 'string', 'max:255'],
            'booking_type' => ['nullable', 'string', 'max:255'],
            'check_in' => ['required', 'date'],
            'check_out' => ['required', 'date', 'after:check_in'],
            'check_in_time' => ['nullable', 'string', 'max:20'],
            'check_out_time' => ['nullable', 'string', 'max:20'],
            'same_plan_for_all_rooms' => ['boolean'],
            'pay_at_hotel' => ['boolean'],
            'rate_plan' => ['nullable', 'string', 'max:255'],
            'discount' => ['numeric', 'min:0'],
            'status' => ['required', 'string', Rule::in($allowedStatuses)],
            'room_bill_to' => ['required', 'string', 'in:to_guest,to_company'],
            'extras_bill_to' => ['required', 'string', 'in:to_guest,to_company'],
            'bill_room_to_guest_company' => ['boolean'],
            'bill_extras_to_guest_company' => ['boolean'],
            'no_post' => ['boolean'],
            'inclusions' => ['array'],
            'inclusions.*' => ['nullable', 'string'],
            'special_instructions' => ['nullable', 'string'],
            'internal_notes' => ['nullable', 'string'],
            'copy_to' => ['array'],
            'copy_to.*.name' => ['required', 'string', 'max:255'],
            'copy_to.*.phone_country_code' => ['nullable', 'string', 'max:10'],
            'copy_to.*.phone' => ['nullable', 'string', 'max:20'],
            'copy_to.*.email' => ['nullable', 'email', 'max:255'],
            // Guest Details: the booking form names at least one guest (the API may import a
            // booking before its guest is known).
            'guest_ids' => $request->inertia() ? ['required', 'array', 'min:1'] : ['array'],
            'guest_ids.*' => ['integer', 'exists:guests,id'],
            'rooms' => ['required', 'array', 'min:1'],
            'rooms.*.room_category_id' => ['required', 'integer', 'exists:room_categories,id'],
            'rooms.*.room_id' => ['nullable', 'integer', 'exists:rooms,id'],
            'rooms.*.meal_plan' => ['nullable', 'string', 'max:255'],
            // A package from Rate Settings → Package the room is sold under.
            'rooms.*.package_id' => ['nullable', 'integer', 'min:1'],
            'rooms.*.adults' => ['required', 'integer', 'min:1'],
            'rooms.*.children' => ['integer', 'min:0'],
            'rooms.*.infants' => ['integer', 'min:0'],
            'rooms.*.rate_per_night' => ['numeric', 'min:0'],
            'addons' => ['sometimes', 'array', 'max:50'],
            'addons.*.addon_id' => ['required', 'integer'],
            'addons.*.quantity' => ['required', 'integer', 'min:1', 'max:999'],
            // Book although Rate Settings → Restrictions or a package's conditions rule the stay out.
            'override_restrictions' => ['boolean'],
        ]);

        $data['check_in'] = CarbonImmutable::parse($data['check_in'])->toDateString();
        $data['check_out'] = CarbonImmutable::parse($data['check_out'])->toDateString();

        return $data;
    }

    /**
     * Rate Settings → Restrictions and each room's package conditions: a room whose stay they
     * rule out is refused, unless the user may override and ticked "Override restrictions".
     * Rooms of an edited booking that keep their category and dates aren't checked again.
     * Sets each room's package name.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function applyStayRules(Request $request, Property $property, array $data, ?Booking $booking = null): array
    {
        $override = ($data['override_restrictions'] ?? false) && app(PermissionGate::class)->allows($request->user(), 'reservation.override_restrictions');
        $unchanged = $booking?->rooms()->get()->map(fn ($room): string => "{$room->room_category_id}|{$room->check_in_date->toDateString()}|{$room->check_out_date->toDateString()}|{$room->package_id}")->all() ?? [];
        $packages = app(RatePackages::class);
        $categories = RoomCategory::query()->where('property_id', $property->id)->get()->keyBy('id');
        $problems = [];

        foreach ($data['rooms'] as $index => $room) {
            $checkIn = $room['check_in_date'] ?? $data['check_in'];
            $checkOut = $room['check_out_date'] ?? $data['check_out'];
            $category = $categories->get((int) $room['room_category_id']);
            $label = 'Room '.($index + 1).($category ? " ({$category->name})" : '');
            $package = filled($room['package_id'] ?? null) ? $packages->find($property, (int) $room['package_id']) : null;

            if (filled($room['package_id'] ?? null) && $package === null) {
                throw ValidationException::withMessages(["rooms.{$index}.package_id" => "{$label}: that package no longer exists."]);
            }

            $data['rooms'][$index]['package_id'] = $package['id'] ?? null;
            $data['rooms'][$index]['package_name'] = $package['name'] ?? null;

            if ($category === null || $data['status'] === BookingStatus::Enquiry->value || in_array("{$category->id}|{$checkIn}|{$checkOut}|".($package['id'] ?? ''), $unchanged, true)) {
                continue;
            }

            $problem = app(StayRestrictions::class)->violation($property->id, $category->id, $checkIn, $checkOut)
                ?? ($package !== null ? $packages->rejection($property, $package, $category, (string) ($room['meal_plan'] ?? ''), $checkIn, $checkOut) : null);

            if ($problem !== null) {
                $problems["rooms.{$index}.room_category_id"] = "{$label}: {$problem}";
            }
        }

        if ($problems !== [] && ! $override) {
            $hint = app(PermissionGate::class)->allows($request->user(), 'reservation.override_restrictions') ? ' Tick "Override restrictions" to book anyway.' : '';

            throw ValidationException::withMessages(array_map(fn (string $message): string => $message.$hint, $problems));
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{room_total: float, discount: float, taxes: float, total: float}
     */
    private function calculateTotals(Property $property, array $data): array
    {
        $bookingNights = BookingPricing::nights($data['check_in'], $data['check_out']);

        // Rooms of a split booking carry their own dates; the rest stay the booking's nights.
        $rooms = array_values(array_map(fn (array $room): array => [
            'rate_per_night' => (float) ($room['rate_per_night'] ?? 0),
            'nights' => isset($room['check_in_date'], $room['check_out_date']) ? BookingPricing::nights($room['check_in_date'], $room['check_out_date']) : $bookingNights,
        ], $data['rooms']));

        return app(BookingPricing::class)->totals($property, $rooms, (float) ($data['discount'] ?? 0));
    }

    /**
     * The "Company pays" panel: the account billed, its credit left, what this booking moved
     * to it and what of that is still unpaid. Null when the booking isn't billed to a company.
     *
     * @return array<string, mixed>|null
     */
    private function companyBilling(Booking $booking): ?array
    {
        if (! $booking->bill_room_to_guest_company && ! $booking->bill_extras_to_guest_company) {
            return null;
        }

        $account = $booking->cityLedgerAccount;

        if ($account === null) {
            return ['linked' => false];
        }

        $billing = app(CompanyBilling::class);
        $balance = $account->balance();

        return [
            'linked' => true,
            'account_id' => $account->id,
            'name' => $account->name,
            'tax_number' => $account->tax_number,
            'payment_terms_days' => $account->payment_terms_days,
            'credit_limit' => $account->credit_limit === null ? null : (float) $account->credit_limit,
            'available_credit' => $account->credit_limit === null ? null : round((float) $account->credit_limit - $balance, 2),
            'room' => (bool) $booking->bill_room_to_guest_company,
            'extras' => (bool) $booking->bill_extras_to_guest_company,
            'billed' => round((float) $account->entries()->where('booking_id', $booking->id)->where('entry_type', CityLedgerEntry::CHARGE)->sum('amount'), 2),
            'unpaid' => $billing->openByBooking($account)[$booking->id] ?? 0.0,
            'due_at_checkout' => $booking->status === BookingStatus::CheckedOut ? 0.0 : $billing->dueToCompany($booking),
        ];
    }

    /**
     * Bill To Guest Company: the guest's City Ledger account the booking bills (none when the
     * company isn't linked to one). The room bill must fit in the account's credit limit;
     * what is already on the account for this booking doesn't count twice.
     *
     * @param  array<string, mixed>  $data
     * @param  array{room_total: float, discount: float, taxes: float, total: float}  $totals
     *
     * @throws ValidationException
     */
    private function companyAccountId(Property $property, array $data, array $totals, BookingStatus $status, ?Booking $booking = null): ?int
    {
        $billRoom = (bool) ($data['bill_room_to_guest_company'] ?? false);
        $billing = app(CompanyBilling::class);
        $account = $billing->accountFor($property->id, $billRoom, (bool) ($data['bill_extras_to_guest_company'] ?? false), array_map('intval', array_values($data['guest_ids'] ?? [])));

        if ($account !== null && $billRoom && $status !== BookingStatus::Enquiry) {
            $billed = $booking === null ? 0.0 : (float) $account->entries()->where('booking_id', $booking->id)->sum('amount');
            $billing->ensureCredit($account, round(max(0.0, (float) $totals['total'] - $billed), 2));
        }

        return $account?->id;
    }

    /**
     * Columns written by both create and update.
     *
     * @param  array<string, mixed>  $data
     * @param  array{room_total: float, discount: float, taxes: float, total: float}  $totals
     * @return array<string, mixed>
     */
    private function bookingAttributes(array $data, array $totals, bool $isHold): array
    {
        return [
            'source' => $data['source'],
            'agent_id' => $data['source'] === Booking::SOURCE_TRAVEL_AGENT ? ($data['agent_id'] ?? null) : null,
            'booking_channel_id' => $data['source'] === Booking::SOURCE_OTA ? ($data['booking_channel_id'] ?? null) : null,
            'metasearch_platform_id' => $data['source'] === Booking::SOURCE_META ? ($data['metasearch_platform_id'] ?? null) : null,
            'type' => $data['type'],
            'booking_type' => $data['booking_type'] ?? null,
            'check_in' => $data['check_in'],
            'check_out' => $data['check_out'],
            'check_in_time' => $data['check_in_time'] ?? null,
            'check_out_time' => $data['check_out_time'] ?? null,
            'same_plan_for_all_rooms' => $data['same_plan_for_all_rooms'] ?? true,
            'pay_at_hotel' => $data['pay_at_hotel'] ?? false,
            'rate_plan' => $data['rate_plan'] ?? null,
            ...$totals,
            'room_bill_to' => $data['room_bill_to'],
            'extras_bill_to' => $data['extras_bill_to'],
            'bill_room_to_guest_company' => $data['bill_room_to_guest_company'] ?? false,
            'bill_extras_to_guest_company' => $data['bill_extras_to_guest_company'] ?? false,
            'no_post' => $data['no_post'] ?? false,
            'inclusions' => $data['inclusions'] ?? [],
            'special_instructions' => $data['special_instructions'] ?? null,
            'internal_notes' => $data['internal_notes'] ?? null,
            'copy_to' => $data['copy_to'] ?? [],
            // Sent as an instant with its offset (the form converts the property's wall-clock time); stored in UTC.
            'hold_release_at' => $isHold ? CarbonImmutable::parse($data['hold_release_at'])->utc() : null,
            'hold_auto_release' => $isHold && ($data['hold_auto_release'] ?? false),
            'hold_reminders' => $isHold ? array_values($data['hold_reminders'] ?? []) : null,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $attachments
     */
    private function storeAttachments(Booking $booking, array $attachments, Request $request): void
    {
        foreach ($attachments as $attachment) {
            $file = $attachment['file'];

            $booking->attachments()->create([
                'user_id' => $request->user()?->id,
                'document_type' => $attachment['document_type'],
                'document_number' => $attachment['document_number'] ?? null,
                'expiry_date' => $attachment['expiry_date'] ?? null,
                'path' => $file->store("booking-attachments/{$booking->id}", 'local'),
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getClientMimeType(),
                'size' => $file->getSize(),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function attachmentPayload(Property $property, Booking $booking, BookingAttachment $attachment): array
    {
        return [
            'id' => $attachment->id,
            'document_type' => $attachment->document_type,
            'document_number' => $attachment->document_number,
            'expiry_date' => $attachment->expiry_date?->toDateString(),
            'original_name' => $attachment->original_name,
            'mime_type' => $attachment->mime_type,
            'size' => $attachment->size,
            'uploaded_by' => $attachment->user?->name,
            'uploaded_at' => $attachment->created_at?->toIso8601ZuluString(),
            'url' => route('admin.bookings.attachments.show', [$property, $booking, $attachment]),
            'download_url' => route('admin.bookings.attachments.show', [$property, $booking, $attachment, 'download' => 1]),
        ];
    }

    /**
     * Streams an uploaded document inline for the document viewer, or as a
     * download with `?download=1`. Files are never publicly reachable.
     */
    public function showAttachment(Request $request, Property $property, Booking $booking, BookingAttachment $attachment): StreamedResponse
    {
        abort_unless($booking->property_id === $property->id && $attachment->booking_id === $booking->id, 404);
        abort_unless(Storage::disk('local')->exists($attachment->path), 404);

        return Storage::disk('local')->response(
            $attachment->path,
            $attachment->original_name,
            ['Content-Type' => $attachment->mime_type ?: 'application/octet-stream', 'X-Content-Type-Options' => 'nosniff'],
            $request->boolean('download') ? 'attachment' : 'inline',
        );
    }

    public function show(Request $request, Property $property, Booking $booking): Response
    {
        if ($booking->property_id !== $property->id) {
            abort(404);
        }

        $booking->load([
            'property',
            'rooms.roomCategory',
            'rooms.room',
            'guests',
            'createdBy:id,name',
            'updatedBy:id,name',
            'payments' => fn ($q) => $q->latest('payments.id'),
        ]);

        $primaryGuest = $booking->guests->first();

        $checkIn = CarbonImmutable::parse($booking->check_in);
        $checkOut = CarbonImmutable::parse($booking->check_out);
        $nights = max(1, (int) $checkIn->diffInDays($checkOut));

        $taxComponents = $this->activeRoomTaxComponents($property);
        $totalRoomsCount = $booking->rooms->count();
        $totalRoomsAmount = (float) $booking->rooms->sum(fn ($r) => (float) $r->rate_per_night * $nights);

        $calculatedRoomTaxes = $booking->rooms->map(function ($r) use ($nights, $totalRoomsAmount, $booking, $taxComponents) {
            $rackRate = (float) $r->rate_per_night;
            $roomAmount = $rackRate * $nights;
            $discountShare = $totalRoomsAmount > 0 ? (float) $booking->discount * ($roomAmount / $totalRoomsAmount) : 0.0;
            $netRate = max(0, $roomAmount - $discountShare) / $nights;

            return round($this->taxForAmount($taxComponents, $rackRate, $netRate) * $nights, 2);
        });

        $useCalculatedTaxes = ! empty($taxComponents) && abs($calculatedRoomTaxes->sum() - (float) $booking->taxes) < 0.05;

        $runningTaxSum = 0.0;
        $runningDiscountSum = 0.0;

        $housekeepingStatuses = app(Housekeeping::class)->statuses($property);

        $roomsData = $booking->rooms->values()->map(function ($room, $idx) use (
            $nights,
            $totalRoomsCount,
            $totalRoomsAmount,
            $booking,
            $calculatedRoomTaxes,
            $useCalculatedTaxes,
            $housekeepingStatuses,
            &$runningTaxSum,
            &$runningDiscountSum
        ) {
            $roomAmount = (float) $room->rate_per_night * $nights;
            $ratio = $totalRoomsAmount > 0 ? ($roomAmount / $totalRoomsAmount) : (1.0 / max(1, $totalRoomsCount));
            $isLast = ($idx === $totalRoomsCount - 1);

            if ($isLast) {
                $roomDiscount = max(0.0, round((float) $booking->discount - $runningDiscountSum, 2));
            } else {
                $roomDiscount = round((float) $booking->discount * $ratio, 2);
                $runningDiscountSum += $roomDiscount;
            }

            if ($useCalculatedTaxes) {
                $roomTax = (float) ($calculatedRoomTaxes[$idx] ?? 0.0);
            } else {
                if ($isLast) {
                    $roomTax = max(0.0, round((float) $booking->taxes - $runningTaxSum, 2));
                } else {
                    $roomTax = round((float) $booking->taxes * $ratio, 2);
                    $runningTaxSum += $roomTax;
                }
            }

            $roomTotal = round($roomAmount - $roomDiscount + $roomTax, 2);

            return [
                'id' => $room->id,
                'room_category_id' => $room->room_category_id,
                'category_name' => $room->roomCategory->name ?? 'Room',
                'category_abbreviation' => $room->roomCategory->abbreviation ?? '',
                'room_id' => $room->room_id,
                'room_number' => $room->room->name ?? 'Unassigned',
                'meal_plan' => $room->meal_plan ?: 'EP',
                'adults' => $room->adults,
                'children' => $room->children,
                'infants' => $room->infants,
                'rate_per_night' => (float) $room->rate_per_night,
                'total_amount' => $roomAmount,
                'discount' => $roomDiscount,
                'taxes' => $roomTax,
                'total' => $roomTotal,
                'sub_booking_id' => $room->id,
                'stay_status' => $room->status->value,
                // The physical room's housekeeping status: check-in warns about (or refuses) a room that isn't ready.
                'housekeeping' => ($status = $room->room ? ($housekeepingStatuses->firstWhere('name', $room->room->housekeeping_status) ?? $housekeepingStatuses->first()) : null)
                    ? collect($status)->only(['name', 'color', 'allow_check_in', 'hard_stop'])->all()
                    : null,
                'check_in_date' => $room->check_in_date->toDateString(),
                'check_out_date' => $room->check_out_date->toDateString(),
                'checked_in_at' => $room->checked_in_at?->toIso8601ZuluString(),
                'checked_out_at' => $room->checked_out_at?->toIso8601ZuluString(),
            ];
        });

        $guestsData = $booking->guests->map(function ($g) {
            $email = $this->guestEmail($g);

            return [
                'id' => $g->id,
                'name' => trim(($g->title ? $g->title.' ' : '').$g->first_name.' '.$g->last_name),
                'first_name' => $g->first_name,
                'last_name' => $g->last_name,
                'mobile' => $g->phone,
                'phone_country_code' => $g->phone_country_code,
                'email' => $email,
                'nationality' => $g->nationality,
                'address' => $g->address,
            ];
        });

        $paymentsData = $this->paymentsPayload($property, $booking);

        $bookingEngineService = app(BookingEngineService::class);
        $policyLines = $bookingEngineService->cancellationPolicyLines($property, $checkIn);
        $cancellationPolicyText = ! empty($policyLines)
            ? implode("\n", $policyLines)
            : 'Cancellation Charges of 100% will be applicable if cancelled/No Show on or after '.$checkIn->subDay()->format('d-F-Y');

        // Only this booking's own entries (status changes, payments, folios, e-mails…); rows logged
        // before entries carried a booking id are matched by the booking reference in their text.
        $activityLogs = ActivityLog::query()
            ->where('property_id', $property->id)
            ->where(function ($q) use ($booking) {
                $q->where('booking_id', $booking->id)
                    ->orWhere(fn ($legacy) => $legacy->whereNull('booking_id')->where('action', 'like', '%'.$booking->booking_ref_id.'%'));
            })
            ->with('user:id,name')
            ->latest('date')
            ->limit(10)
            ->get()
            ->map(fn ($log) => [
                'id' => $log->id,
                'user_name' => $log->user->name ?? 'Staff',
                'action' => $log->action,
                'date' => $log->date->toIso8601ZuluString(),
            ]);

        if ($activityLogs->isEmpty()) {
            $defaultLogs = collect();

            $stayLabel = match ($booking->status) {
                BookingStatus::CheckedIn => 'StayStatus : Checked In via Web',
                BookingStatus::CheckedOut => 'StayStatus : Checked Out via Web',
                default => 'StayStatus : Confirmed via Web',
            };
            $defaultLogs->push([
                'id' => 1,
                'user_name' => $booking->createdBy->name ?? 'Staff',
                'action' => $stayLabel,
                'date' => ($booking->updated_at ?? now())->toIso8601ZuluString(),
            ]);

            $invoiceNumber = 'INV'.($property->abbreviation ?: 'CON').str_pad((string) $booking->id, 6, '0', STR_PAD_LEFT);
            $defaultLogs->push([
                'id' => 2,
                'user_name' => $booking->createdBy->name ?? 'Staff',
                'action' => "Bill # {$invoiceNumber} generated for amount {$booking->total}.",
                'date' => ($booking->created_at ?? now())->toIso8601ZuluString(),
            ]);

            foreach ($booking->payments as $idx => $p) {
                $rcpt = $p->receipt_number ?? ('RCPT/'.$p->id);
                $pMode = $p->payment_mode->value ?? 'Cash';
                $defaultLogs->push([
                    'id' => 10 + $idx,
                    'user_name' => $p->collected_by_staff ?: ($booking->createdBy->name ?? 'Staff'),
                    'action' => "{$pMode} receipt # {$rcpt} for amount {$p->total_txn_amount} received.",
                    'date' => ($p->payment_creation_date ?? $booking->created_at ?? now())->toIso8601ZuluString(),
                ]);
                $defaultLogs->push([
                    'id' => 20 + $idx,
                    'user_name' => auth()->user()->name ?? 'Staff',
                    'action' => Currency::forProperty($property)['code'].' '.number_format((float) $p->total_txn_amount, 2, '.', '').' is tagged with Room Charges',
                    'date' => ($p->payment_creation_date ?? $booking->created_at ?? now())->toIso8601ZuluString(),
                ]);
            }

            $activityLogs = $defaultLogs;
        }

        return Inertia::render('admin/bookings/Show', [
            'property' => $property->only(['id', 'name', 'abbreviation', 'country_id']),
            'properties' => Property::query()->orderBy('name')->get(['id', 'name', 'abbreviation']),
            'booking' => [
                'id' => $booking->id,
                'booking_ref_id' => $booking->booking_ref_id,
                'source' => $booking->source,
                'agent' => $booking->agent?->toOption(),
                'booking_channel_id' => $booking->booking_channel_id,
                'booking_channel' => $booking->bookingChannel?->name,
                'metasearch_platform_id' => $booking->metasearch_platform_id,
                'metasearch_platform' => $booking->metasearchPlatform?->name,
                'type' => $booking->type,
                'booking_type' => $booking->booking_type,
                'check_in' => $booking->check_in->format('Y-m-d'),
                'check_out' => $booking->check_out->format('Y-m-d'),
                'rooms_split' => (bool) $booking->rooms_split,
                'check_in_time' => $booking->check_in_time,
                'check_out_time' => $booking->check_out_time,
                'same_plan_for_all_rooms' => (bool) $booking->same_plan_for_all_rooms,
                'pay_at_hotel' => (bool) $booking->pay_at_hotel,
                'rate_plan' => $booking->rate_plan,
                'check_in_status' => $booking->check_in_status->value ?? '',
                'status' => $booking->status->value,
                'room_total' => (float) $booking->room_total,
                'discount' => (float) $booking->discount,
                'taxes' => (float) $booking->taxes,
                'total' => (float) $booking->total,
                'outstanding' => (float) $booking->outstanding,
                'room_bill_to' => $booking->room_bill_to ?? 'to_guest',
                'extras_bill_to' => $booking->extras_bill_to ?? 'to_guest',
                'bill_room_to_guest_company' => (bool) $booking->bill_room_to_guest_company,
                'bill_extras_to_guest_company' => (bool) $booking->bill_extras_to_guest_company,
                'company_billing' => $this->companyBilling($booking),
                'no_post' => (bool) $booking->no_post,
                'special_instructions' => $booking->special_instructions,
                'internal_notes' => $booking->internal_notes,
                'copy_to' => $booking->copy_to ?? [],
                'inclusions' => $booking->inclusions ?? [],
                'created_at' => $booking->created_at?->toIso8601ZuluString(),
                'updated_at' => $booking->updated_at?->toIso8601ZuluString(),
                'created_by' => $booking->createdBy->name ?? 'Staff',
                'updated_by' => $booking->updatedBy->name ?? $booking->createdBy->name ?? 'Staff',
                'nights' => $nights,
                'rooms' => $roomsData,
                'guests' => $guestsData,
                'payments' => $paymentsData,
                'payment_links' => $this->paymentLinksPayload($booking),
                'addons_total' => (float) $booking->addons_total,
                'addons' => $booking->addons()->get()->map(fn (BookingAddon $line): array => $line->toLine())->values()->all(),
                'attachments' => $booking->attachments()->with('user:id,name')->latest('id')->get()
                    ->map(fn (BookingAttachment $attachment): array => $this->attachmentPayload($property, $booking, $attachment))->all(),
            ],
            'folios' => $this->folioPayload($booking),
            'folioPayers' => $this->folioPayers($property, $booking),
            'splitPaymentModes' => array_map(fn (PaymentMode $mode): string => $mode->value, SplitPaymentController::MODES),
            // Settings → Booking → Payment Settings: the modes offered on Add Payment.
            'paymentModes' => app(PaymentModeSettings::class)->paymentModes($property),
            'guestLedgerBalance' => $this->guestLedgerBalance($request, $property, $booking),
            'cancellationPolicy' => $cancellationPolicyText,
            'activityLogs' => $activityLogs,
            'staff' => User::query()->orderBy('name')->get(['id', 'name']),
            'currentUser' => $request->user()?->only(['id', 'name']),
            // Check-out → "Mark room as" (Booking Settings → Housekeeping), pre-selecting the check-out default.
            'housekeepingStatuses' => app(Housekeeping::class)->statuses($property)->map(fn (array $status): array => ['name' => $status['name'], 'color' => $status['color']])->all(),
            'housekeepingCheckOutDefault' => app(Housekeeping::class)->checkOutDefault($property),
            'canCheckoutWithOutstanding' => (bool) ($request->user() && (
                $request->user()->can('checkout_on_outstanding_payments') ||
                $request->user()->can('checkout on outstanding payments')
            )),
            'today' => $property->today(),
            'reservations' => $this->reservationsPayload($booking),
            'countries' => Country::query()->orderBy('name')->get(['id', 'name', 'iso2', 'phonecode']),
            'addonCatalogue' => app(BookingAddons::class)->catalogue($property)->map(fn (Addon $addon): array => $addon->toOption())->values(),
            'webCheckin' => app(WebCheckins::class)->summary($booking),
            // Room upgrades made on the booking (complimentary or paid), for the audit.
            'upgrades' => RoomUpgrade::query()->where('booking_id', $booking->id)->with(['fromCategory:id,name', 'toCategory:id,name', 'fromRoom:id,name', 'toRoom:id,name', 'upgrader:id,name'])->latest('id')->get()
                ->map(fn (RoomUpgrade $upgrade): array => [
                    'id' => $upgrade->id,
                    'sub_booking_id' => $upgrade->sub_booking_id,
                    'from' => trim($upgrade->fromCategory?->name.' '.($upgrade->fromRoom ? "({$upgrade->fromRoom->name})" : '')),
                    'to' => trim($upgrade->toCategory?->name.' '.($upgrade->toRoom ? "({$upgrade->toRoom->name})" : '')),
                    'type' => $upgrade->type,
                    'supplement_per_night' => (float) $upgrade->supplement_per_night,
                    'nights' => $upgrade->nights,
                    'reason' => $upgrade->reason,
                    'by' => $upgrade->upgrader?->name,
                    'at' => $upgrade->created_at?->toIso8601String(),
                ])->values(),
            // Guest ledger: what the property holds for the booking's guest, and what this booking owes or has in credit.
            'guestLedger' => (function () use ($property, $booking): ?array {
                $guest = $booking->primaryGuest;

                if ($guest === null) {
                    return null;
                }

                $guestLedger = app(GuestLedger::class);
                $account = GuestLedgerAccount::query()->where('property_id', $property->id)->where('guest_id', $guest->id)->first();
                $balance = round($booking->outstandingBalance(), 2);

                return [
                    'guest_name' => trim("{$guest->first_name} {$guest->last_name}"),
                    'balance' => $account ? $guestLedger->accountBalance($account) : 0.0,
                    'statement' => $account ? $guestLedger->statement($account, 10) : [],
                    'booking_owed' => max(0.0, $balance),
                    'booking_credit' => max(0.0, -$balance),
                ];
            })(),
            // MORE → Bill to City Ledger: the property's active company / agency accounts.
            'cityLedgerAccounts' => app(PermissionGate::class)->allows($request->user(), 'database.city_ledger.manage')
                ? CityLedgerAccount::query()->where('property_id', $property->id)->where('is_active', true)->orderBy('name')->get(['id', 'name', 'code'])
                : [],
            // The balance shown in the currencies the property has a rate for (Settings → Exchange rates).
            'exchangeRates' => app(ExchangeRates::class)->current($property),
        ]);
    }

    /**
     * The booking's receipts, as the payments tables list them.
     *
     * @return list<array<string, mixed>>
     */
    private function paymentsPayload(Property $property, Booking $booking): array
    {
        return array_values($booking->payments->map(function (Payment $payment) use ($booking, $property): array {
            return [
                'id' => $payment->id,
                'payment_mode' => $payment->payment_mode->value ?? 'Cash',
                'receipt_number' => $payment->receipt_number ?? ('RCPT/'.$payment->id),
                'voucher_number' => $payment->voucher_number ?? ($booking->booking_ref_id ?? '—'),
                'booking_id' => $payment->booking_id ?? $booking->id,
                'property_name' => $property->name,
                'guest_name' => $payment->guest_name ?? ($booking->guests->first() ? trim($booking->guests->first()->first_name.' '.$booking->guests->first()->last_name) : '—'),
                'payment_creation_date' => $payment->payment_creation_date?->toIso8601ZuluString() ?? $payment->created_at?->toIso8601ZuluString(),
                'payment_transaction_date' => $payment->payment_transaction_date?->toIso8601ZuluString() ?? $payment->created_at?->toIso8601ZuluString(),
                'paid_by' => $payment->payee_name ?? ($payment->paid_by ?? 'Guest'),
                'total_txn_amount' => (float) $payment->total_txn_amount,
                'tagged_amount' => (float) ($payment->pivot->tagged_amount ?? $payment->tagged_amount ?? $payment->total_txn_amount),
                'untagged_amount' => (float) ($payment->untagged_amount ?? 0),
                'status' => $payment->status->value,
                'booking_source' => $payment->booking_source ?? ($booking->source ?? 'Direct'),
                'collected_by_staff' => $payment->collected_by_staff ?? 'Staff',
                'payment_ref_id' => $payment->payment_ref_id,
                'pg_txn_id' => $payment->pg_txn_id,
                'bank' => $payment->bank,
                'card_name' => $payment->card_name,
                'card_number' => $payment->card_number,
                'cancellation_reason' => $payment->cancellation_reason,
                'notes' => $payment->notes,
            ];
        })->all());
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function paymentLinksPayload(Booking $booking): array
    {
        return array_values($booking->paymentLinks()->with(['creator:id,name', 'payment:id,receipt_number'])->latest('id')->get()
            ->map(fn (PaymentLink $link): array => [
                'id' => $link->id,
                'url' => $link->url(),
                'amount' => (float) $link->amount,
                'currency' => $link->currency,
                'collect_for' => $link->collect_for,
                'email' => $link->email,
                'mobile' => $link->mobile,
                'status' => $link->effectiveStatus()->value,
                'status_label' => $link->effectiveStatus()->label(),
                // Pending but unusable right now (e.g. nothing left to pay) — shown as a warning.
                'unpayable_reason' => $link->effectiveStatus() === PaymentLinkStatus::Pending ? $link->setRelation('booking', $booking)->unpayableReason() : null,
                'created_by' => $link->creator?->name,
                'created_at' => $link->created_at?->toIso8601ZuluString(),
                'sent_at' => $link->sent_at?->toIso8601ZuluString(),
                'expires_at' => $link->expires_at?->toIso8601ZuluString(),
                'paid_at' => $link->paid_at?->toIso8601ZuluString(),
                'receipt_number' => $link->payment?->receipt_number,
            ])->all());
    }

    /**
     * The booking as the Add Payment modal's reservation picker lists it.
     *
     * @return list<array<string, mixed>>
     */
    private function reservationsPayload(Booking $booking): array
    {
        $primaryGuest = $booking->guests->first();

        return [[
            'id' => $booking->id,
            'booking_ref_id' => $booking->booking_ref_id,
            'guest_name' => $primaryGuest ? trim("{$primaryGuest->first_name} {$primaryGuest->last_name}") : '—',
            'guest_id' => $primaryGuest?->id,
            // Rooms and add-ons: the booking's whole bill, as the page header shows it.
            'total' => round((float) $booking->total + (float) $booking->addons_total, 2),
            'outstanding' => (float) $booking->outstanding,
            'taxes' => (float) $booking->taxes,
            'check_in_status' => $booking->check_in_status->value ?? '',
            'status' => $booking->status->value,
            'folio_ids' => $booking->folioIds(),
            // Add Payment → a tagged amount can be split over these (auto-filled in this order).
            'windows' => app(PaymentDistributionService::class)->folioWindowsFor($booking),
        ]];
    }

    /** Guests keep e-mails as a list of addresses; older rows may hold ['email' => ...] entries. */
    private function guestEmail(Guest $guest): ?string
    {
        $firstEmail = collect(is_array($guest->emails) ? $guest->emails : [])
            ->map(fn ($entry) => is_array($entry) ? ($entry['email'] ?? null) : $entry)
            ->first(fn ($entry): bool => filled($entry));

        return $firstEmail;
    }

    /**
     * What the booking's guest holds on their ledger, offered as a payment mode on Add Payment
     * to staff who may move ledger balances (0 otherwise).
     */
    private function guestLedgerBalance(Request $request, Property $property, Booking $booking): float
    {
        if (! app(PermissionGate::class)->allows($request->user(), 'reservation.change_billing_preference')) {
            return 0.0;
        }

        return app(GuestLedger::class)->balance($property->id, $booking->primaryGuest);
    }

    /**
     * What the billing panel (payments, payment links, folios) needs, shared by the booking
     * page and the edit form.
     *
     * @return array<string, mixed>
     */
    private function billingProps(Request $request, Property $property, Booking $booking): array
    {
        $booking->loadMissing(['guests', 'payments' => fn ($q) => $q->latest('payments.id')]);
        $primaryGuest = $booking->guests->first();

        return [
            'property' => $property->only(['id', 'name', 'abbreviation', 'country_id']),
            'booking' => [
                'id' => $booking->id,
                'booking_ref_id' => $booking->booking_ref_id,
                'status' => $booking->status->value,
                'check_out' => $booking->check_out->format('Y-m-d'),
                'rooms_split' => (bool) $booking->rooms_split,
                'rooms_count' => $booking->rooms()->count(),
                'outstanding' => (float) $booking->outstanding,
                'room_bill_to' => $booking->room_bill_to ?? 'to_guest',
                'payments' => $this->paymentsPayload($property, $booking),
                'payment_links' => $this->paymentLinksPayload($booking),
            ],
            'guest' => $primaryGuest ? [
                'name' => trim("{$primaryGuest->first_name} {$primaryGuest->last_name}"),
                'email' => $this->guestEmail($primaryGuest),
                'mobile' => $primaryGuest->phone,
            ] : null,
            'folios' => $this->folioPayload($booking),
            'folioPayers' => $this->folioPayers($property, $booking),
            'splitPaymentModes' => array_map(fn (PaymentMode $mode): string => $mode->value, SplitPaymentController::MODES),
            // Settings → Booking → Payment Settings: the modes offered on Add Payment.
            'paymentModes' => app(PaymentModeSettings::class)->paymentModes($property),
            'guestLedgerBalance' => $this->guestLedgerBalance($request, $property, $booking),
            'properties' => Property::query()->orderBy('name')->get(['id', 'name', 'abbreviation']),
            'staff' => User::query()->orderBy('name')->get(['id', 'name']),
            'currentUser' => $request->user()?->only(['id', 'name']),
            'reservations' => $this->reservationsPayload($booking),
            'countries' => Country::query()->orderBy('name')->get(['id', 'name', 'iso2', 'phonecode']),
            'today' => $property->today(),
        ];
    }

    /**
     * Roles and Permissions: cancelling by the reservation's kind and arrival date; checking in
     * and out, and undoing them (or a cancellation).
     */
    private function authorizeStatusChange(Request $request, Property $property, Booking $booking, BookingStatus $status): void
    {
        $gate = app(PermissionGate::class);
        $from = $booking->status;

        if ($status === $from) {
            return;
        }

        match (true) {
            $status === BookingStatus::Cancelled => $gate->reservation($request->user(), $property, 'cancel', $from, $booking->check_in->toDateString()),
            $from === BookingStatus::Cancelled => $gate->require($request->user(), 'reservation.undo_cancel'),
            $from === BookingStatus::NoShow => $gate->require($request->user(), 'reservation.undo_no_show'),
            $status === BookingStatus::CheckedIn && $from === BookingStatus::CheckedOut => $gate->require($request->user(), 'reservation.undo_check_out'),
            $status === BookingStatus::CheckedIn => $gate->require($request->user(), 'reservation.check_in'),
            $status === BookingStatus::CheckedOut => $gate->require($request->user(), 'reservation.check_out'),
            $from === BookingStatus::CheckedIn => $gate->require($request->user(), 'reservation.undo_check_in'),
            default => null,
        };
    }

    public function updateStatus(Request $request, Property $property, Booking $booking): RedirectResponse|JsonResponse
    {
        if ($booking->property_id !== $property->id) {
            abort(404);
        }

        $validated = $request->validate([
            'status' => ['nullable', 'string', 'in:confirmed,checked_in,checked_out,cancelled,no_stay'],
            'check_in_status' => ['nullable', 'string'],
            ...RoomStays::reportedAtRules(),
            'housekeeping_status' => ['nullable', 'string', Rule::in(app(Housekeeping::class)->statuses($property)->pluck('name'))],
        ], ['reported_at.before_or_equal' => 'The reported time can\'t be in the future.']);

        $requestedStatus = $validated['status'] ?? null;
        $reportedAt = RoomStays::reportedAt($validated['reported_at'] ?? null);
        $requestedCheckInStatus = $validated['check_in_status'] ?? null;

        if ($requestedStatus === 'no_stay' || ($requestedCheckInStatus && strtolower(str_replace(['_', ' '], '-', $requestedCheckInStatus)) === 'no-stay')) {
            $booking->update(['check_in_status' => BookingCheckInStatus::NoStay]);

            ActivityLog::create([
                'user_id' => $request->user()->id,
                'property_id' => $property->id,
                'booking_id' => $booking->id,
                'guest_id' => $booking->guests->first()?->id,
                'action' => 'StayStatus : No Stay via Web',
                'date' => now(),
            ]);

            return $this->statusResponse($request, 'Booking check-in status updated to No Stay.');
        }

        if (! $requestedStatus) {
            return back();
        }

        $status = BookingStatus::from($requestedStatus);
        $this->authorizeStatusChange($request, $property, $booking, $status);

        // Early check-in / late check-out: "Waive the charge" needs its permission.
        $waiveStayTimeCharges = $request->boolean('waive_stay_time_charges');

        if ($waiveStayTimeCharges) {
            app(PermissionGate::class)->require($request->user(), 'reservation.waive_stay_charges');
        }

        // A pay-at-hotel booking must be fully paid (late check-out charge included) before it checks out; others leave an amount due only with "checkout_on_outstanding_payments".
        if ($status === BookingStatus::CheckedOut && $booking->status !== BookingStatus::CheckedOut) {
            $lateCharge = app(StayTimeCharges::class)->lateCheckOutTotal($booking->subBookings()->where('status', SubBookingStatus::CheckedIn)->get(), $reportedAt, $waiveStayTimeCharges);

            try {
                app(RoomStays::class)->ensureCanCheckOut($booking, (float) $booking->outstanding + $lateCharge, $request->user());
            } catch (ValidationException $exception) {
                return $this->statusResponse($request, collect($exception->errors())->flatten()->first(), false);
            }
        }

        // The rooms this check-in brings in: those arrived by the reported date (a split booking's later rooms wait).
        $arrivingStays = $status === BookingStatus::CheckedIn && $booking->status === BookingStatus::Confirmed
            ? $booking->subBookings()->where('status', SubBookingStatus::Booked)->whereDate('check_in_date', '<=', $property->localTime($reportedAt)->toDateString())->get()
            : collect();

        if ($status === BookingStatus::CheckedIn && $booking->status === BookingStatus::Confirmed) {
            try {
                app(RoomStays::class)->ensureCanArrive($booking->check_in->toDateString(), $booking->check_out->toDateString(), $property, $reportedAt);
                // Housekeeping: a room that isn't ready is refused (hard stop) or needs "Check in anyway".
                app(Housekeeping::class)->ensureCanCheckIn($property, $arrivingStays, $request->boolean('override_housekeeping'));
            } catch (ValidationException $exception) {
                return $this->statusResponse($request, collect($exception->errors())->flatten()->first(), false);
            }
        }

        if (in_array($status, [BookingStatus::CheckedIn, BookingStatus::CheckedOut], true) && $status !== $booking->status) {
            $missing = app(StayRequirements::class)->missing($booking, $status === BookingStatus::CheckedIn);

            if ($missing !== []) {
                $action = $status === BookingStatus::CheckedIn ? 'check in' : 'check out';

                return $this->statusResponse($request, "Can't {$action} yet. ".implode(' ', $missing), false);
            }
        }

        $oldStatus = $booking->status;

        $newCheckInStatus = match ($status) {
            BookingStatus::CheckedIn => BookingCheckInStatus::CheckIn,
            BookingStatus::CheckedOut => BookingCheckInStatus::CheckOut,
            BookingStatus::Confirmed => $oldStatus === BookingStatus::CheckedOut
                ? BookingCheckInStatus::CheckIn
                : null,
            default => $booking->check_in_status,
        };

        try {
            DB::transaction(function () use ($booking, $status, $newCheckInStatus, $reportedAt, $waiveStayTimeCharges): void {
                $booking->update([
                    'status' => $status,
                    'check_in_status' => $newCheckInStatus,
                ]);

                // The whole-booking buttons move every room; room-level moves go through RoomStayController.
                app(RoomStays::class)->applyToAllRooms($booking, $status, $reportedAt, $waiveStayTimeCharges);
            });
        } catch (ValidationException $exception) {
            $booking->refresh();

            return $this->statusResponse($request, collect($exception->errors())->flatten()->first(), false);
        }

        $invoiceProblem = null;

        // Housekeeping → Check-in default: the rooms that just took guests.
        if ($arrivingStays->isNotEmpty()) {
            app(Housekeeping::class)->markCheckedIn($property, $arrivingStays);
        }

        if ($status === BookingStatus::CheckedOut) {
            Invoice::generateForBooking($booking, $request->user()->name);

            // Check-out → "Mark room as": the rooms left take that housekeeping status (default: the check-out default).
            app(Housekeeping::class)->markCheckedOut($property, $booking->subBookings()->where('status', SubBookingStatus::CheckedOut)->get(), $request->input('housekeeping_status'));

            // Check-out → "Send invoice to guest" (ticked by default in the dialog).
            if ($request->boolean('send_invoice')) {
                $invoiceProblem = app(InvoiceEmails::class)->sendToGuest($booking->fresh(), $request->user());
            }
        }

        $statusLabel = match ($status) {
            BookingStatus::CheckedIn => 'Checked In',
            BookingStatus::CheckedOut => 'Checked Out',
            BookingStatus::Confirmed => $oldStatus === BookingStatus::CheckedOut
                ? 'Confirmed (Check out undone)'
                : 'Confirmed (Check in undone)',
            BookingStatus::Cancelled => 'Cancelled',
            default => $status->label(),
        };

        ActivityLog::create([
            'user_id' => $request->user()->id,
            'property_id' => $property->id,
            'booking_id' => $booking->id,
            'guest_id' => $booking->guests->first()?->id,
            'action' => "StayStatus : {$statusLabel} via Web",
            'date' => now(),
        ]);

        if ($invoiceProblem !== null && ! $request->wantsJson()) {
            // The check-out stands; only the e-mail failed.
            session()->flash('warning', $invoiceProblem);
        }

        $message = "Booking status updated to {$statusLabel}.";

        if ($status === BookingStatus::CheckedOut && $request->boolean('send_invoice') && $invoiceProblem === null) {
            $message .= ' The invoice was e-mailed to the guest.';
        }

        return $this->statusResponse($request, $message);
    }

    /**
     * The booking page gets a redirect with a flash message; the Smart Booking Chart
     * popover (which calls this with fetch) gets the same message as JSON.
     */
    private function statusResponse(Request $request, string $message, bool $succeeded = true): RedirectResponse|JsonResponse
    {
        if ($request->wantsJson()) {
            return response()->json(['message' => $message], $succeeded ? 200 : 422);
        }

        return back()->with($succeeded ? 'success' : 'error', $message);
    }

    public function updateCheckInStatus(Request $request, Property $property, Booking $booking): RedirectResponse
    {
        if ($booking->property_id !== $property->id) {
            abort(404);
        }

        $validated = $request->validate([
            'check_in_status' => ['required', 'string'],
        ]);

        $statusEnum = BookingCheckInStatus::tryFromNormalized($validated['check_in_status']);
        if (! $statusEnum) {
            return back()->withErrors(['check_in_status' => 'Invalid check-in status.']);
        }

        $booking->update(['check_in_status' => $statusEnum]);

        $statusLabel = $statusEnum->label();

        ActivityLog::create([
            'user_id' => $request->user()->id,
            'property_id' => $property->id,
            'booking_id' => $booking->id,
            'guest_id' => $booking->guests->first()?->id,
            'action' => "StayStatus : {$statusLabel} via Web",
            'date' => now(),
        ]);

        return back()->with('success', "Booking check-in status updated to {$statusLabel}.");
    }
}
