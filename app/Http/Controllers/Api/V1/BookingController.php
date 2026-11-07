<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\BookingStatus;
use App\Http\Controllers\Admin\BookingController as AdminBookingController;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\BookingResource;
use App\Models\Booking;
use App\Models\Property;
use App\Models\RoomCategory;
use App\Services\ApplicationSettings;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Reservations and enquiries: listing them (for syncing into another system), reading one in
 * full, finding one by reservation ID, and what the booking form offers (categories with their
 * rooms and meal plans, booking types, hold defaults). Creating, editing, moving and changing
 * status go through the admin booking actions, which answer the API in JSON.
 */
class BookingController extends Controller
{
    /**
     * Reservations (enquiries and expired quotes are under /enquiries), newest first. Filters:
     * status (comma-separated), stay dates overlapping from/to, updated_since (an instant),
     * and the reservation ID.
     */
    public function index(Request $request, Property $property): AnonymousResourceCollection
    {
        return $this->listing($request, $property, enquiries: false);
    }

    /**
     * Enquiries (quotes), including expired ones.
     */
    public function enquiries(Request $request, Property $property): AnonymousResourceCollection
    {
        return $this->listing($request, $property, enquiries: true);
    }

    /**
     * Logs an enquiry: the booking form's fields, with the status set to enquiry.
     */
    public function storeEnquiry(Request $request, Property $property): JsonResponse
    {
        $request->merge(['status' => BookingStatus::Enquiry->value]);

        return app()->call([app(AdminBookingController::class), 'store'], ['request' => $request, 'property' => $property]);
    }

    /**
     * One reservation or enquiry with its rooms, guests, receipts and folios.
     */
    public function show(Property $property, Booking $booking): JsonResponse
    {
        abort_unless($booking->property_id === $property->id, 404);

        $booking->load(['rooms.roomCategory:id,name', 'rooms.inventoryUnit:id,name', 'guests', 'payments.bookings:id,booking_ref_id']);

        return response()->json([
            'data' => [
                ...(new BookingResource($booking))->resolve(),
                'folios' => app(AdminBookingController::class)->folioPayload($booking),
            ],
        ]);
    }

    public function search(Request $request, Property $property): JsonResponse
    {
        $term = (string) $request->validate(['q' => ['required', 'string', 'max:100']])['q'];

        $bookings = Booking::query()
            ->where('property_id', $property->id)
            ->whereLike('booking_ref_id', "%{$term}%")
            ->with('guests:id,title,first_name,last_name')
            ->latest('id')
            ->limit(20)
            ->get();

        return response()->json([
            'data' => $bookings->map(fn (Booking $booking): array => [
                'id' => $booking->id,
                'booking_ref_id' => $booking->booking_ref_id,
                'status' => $booking->status->value,
                'status_label' => $booking->status->label(),
                'check_in' => $booking->check_in->toDateString(),
                'check_out' => $booking->check_out->toDateString(),
                'guest' => ($guest = $booking->guests->first())
                    ? trim(collect([$guest->title, $guest->first_name, $guest->last_name])->filter()->implode(' '))
                    : null,
            ])->values(),
        ]);
    }

    private function listing(Request $request, Property $property, bool $enquiries): AnonymousResourceCollection
    {
        $statuses = $enquiries
            ? BookingStatus::enquiryStatuses()
            : array_values(array_filter(BookingStatus::cases(), fn (BookingStatus $status): bool => ! in_array($status, BookingStatus::enquiryStatuses(), true)));

        $filters = $request->validate([
            'status' => ['nullable', 'string', 'max:255'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'updated_since' => ['nullable', 'date'],
            'q' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $wanted = collect(explode(',', (string) ($filters['status'] ?? '')))->map(fn (string $status): string => trim($status))->filter();
        $invalid = $wanted->diff(array_map(fn (BookingStatus $status): string => $status->value, $statuses));
        abort_if($invalid->isNotEmpty(), 422, 'Unknown status: '.$invalid->implode(', ').'.');

        $bookings = Booking::query()
            ->where('property_id', $property->id)
            ->whereIn('status', $wanted->isNotEmpty() ? $wanted->all() : $statuses)
            ->when($filters['from'] ?? null, fn (Builder $query, string $from) => $query->whereDate('check_out', '>', $from))
            ->when($filters['to'] ?? null, fn (Builder $query, string $to) => $query->whereDate('check_in', '<=', $to))
            ->when($filters['updated_since'] ?? null, fn (Builder $query, string $since) => $query->where('updated_at', '>=', CarbonImmutable::parse($since)->utc()))
            ->when($filters['q'] ?? null, fn (Builder $query, string $term) => $query->whereLike('booking_ref_id', "%{$term}%"))
            ->with(['rooms.roomCategory:id,name', 'rooms.inventoryUnit:id,name', 'guests'])
            ->latest('id')
            ->paginate($filters['per_page'] ?? 25)
            ->withQueryString();

        return BookingResource::collection($bookings);
    }

    public function options(Property $property, ApplicationSettings $settings): JsonResponse
    {
        return response()->json([
            'categories' => RoomCategory::query()
                ->where('property_id', $property->id)
                ->with(['rooms' => fn ($query) => $query->orderBy('display_order')->orderBy('name')->select(['id', 'room_category_id', 'name'])])
                ->orderBy('display_order')
                ->get(['id', 'name', 'abbreviation', 'meal_plans', 'max_adults', 'max_children', 'sell_by_bed'])
                ->map(fn (RoomCategory $category): array => [
                    'id' => $category->id,
                    'name' => $category->name,
                    'abbreviation' => $category->abbreviation,
                    'meal_plans' => array_values((array) $category->meal_plans),
                    'max_adults' => (int) $category->max_adults,
                    'max_children' => (int) $category->max_children,
                    'sell_by_bed' => (bool) $category->sell_by_bed,
                    'rooms' => $category->rooms->map->only(['id', 'name'])->values(),
                ]),
            'types' => $settings->activeReservationSubtypes(),
            'sources' => collect(Booking::SOURCES)->only([Booking::SOURCE_DIRECT, Booking::SOURCE_CORPORATE])->map(fn (string $label, string $value): array => ['value' => $value, 'label' => $label])->values(),
            'statuses' => collect([BookingStatus::Confirmed, BookingStatus::OnHold, BookingStatus::Enquiry])->map(fn (BookingStatus $status): array => ['value' => $status->value, 'label' => $status->label()]),
            'hold_defaults' => app(AdminBookingController::class)->holdDefaults($property),
        ]);
    }
}
