<?php

namespace App\Http\Controllers\Admin;

use App\Enums\WaitlistStatus;
use App\Http\Controllers\Controller;
use App\Models\Guest;
use App\Models\Property;
use App\Models\RoomCategory;
use App\Models\WaitlistRequest;
use App\Services\ApplicationSettings;
use App\Services\CurrentProperty;
use App\Services\Waitlists;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Databases → Waitlist: hold requests for sold-out dates, and converting them into bookings
 * once inventory frees up.
 */
class WaitlistController extends Controller
{
    public function index(Request $request, Waitlists $waitlists): Response
    {
        $property = $this->selectedProperty($request);
        $filters = array_filter($request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', Rule::enum(WaitlistStatus::class)],
        ]), fn (mixed $value): bool => filled($value));

        if ($property !== null) {
            $waitlists->refresh($property);
        }

        $requests = $property
            ? $this->filteredQuery($property, $filters)->paginate(15)->withQueryString()->through(fn (WaitlistRequest $item): array => $this->row($item))
            : null;

        return Inertia::render('admin/databases/Waitlist', [
            'property' => $property?->only(['id', 'name']),
            'requests' => $requests,
            'filters' => $filters,
            'counts' => $property ? $property->waitlistRequests()->open()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status') : [],
            'roomCategories' => $property ? $property->roomCategories()->orderBy('display_order')->get(['id', 'name']) : [],
            'statuses' => collect(WaitlistStatus::cases())->map(fn (WaitlistStatus $status): array => ['value' => $status->value, 'label' => $status->label()])->values(),
        ]);
    }

    public function store(Request $request, Property $property, Waitlists $waitlists): RedirectResponse
    {
        $data = $request->validate([
            'guest_id' => ['nullable', 'integer', Rule::exists(Guest::class, 'id')->where('property_id', $property->id)],
            'guest_name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'room_category_id' => ['nullable', 'integer', Rule::exists(RoomCategory::class, 'id')->where('property_id', $property->id)],
            'check_in' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$property->today()],
            'check_out' => ['required', 'date_format:Y-m-d', 'after:check_in'],
            'rooms' => ['required', 'integer', 'min:1', 'max:20'],
            'adults' => ['required', 'integer', 'min:1', 'max:20'],
            'children' => ['nullable', 'integer', 'min:0', 'max:20'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], ['check_in.after_or_equal' => 'The check-in date cannot be in the past.']);

        $item = $waitlists->create($property, $data, WaitlistRequest::SOURCE_FRONT_DESK, $request->user());

        return back()->with('success', $item->status === WaitlistStatus::Available
            ? "{$item->guest_name} added to the waitlist. Rooms are free for these dates: convert the request to book them."
            : "{$item->guest_name} added to the waitlist.");
    }

    public function cancel(Property $property, WaitlistRequest $waitlistRequest): RedirectResponse
    {
        abort_unless($waitlistRequest->property_id === $property->id, 404);
        abort_unless($waitlistRequest->isOpen(), 422, 'Only an open request can be cancelled.');

        $waitlistRequest->update(['status' => WaitlistStatus::Cancelled]);

        return back()->with('success', "Waitlist request for {$waitlistRequest->guest_name} cancelled.");
    }

    /**
     * Opens the booking form for the request's dates, rooms and guest; saving the booking marks
     * the request converted.
     */
    public function convert(Property $property, WaitlistRequest $waitlistRequest, Waitlists $waitlists): RedirectResponse
    {
        abort_unless($waitlistRequest->property_id === $property->id, 404);
        abort_unless($waitlistRequest->isOpen(), 422, 'Only an open request can be converted.');

        if (! $waitlists->hasAvailability($waitlistRequest)) {
            $waitlists->refresh($property);

            return back()->with('error', "Rooms aren't free for {$waitlistRequest->guest_name}'s dates yet.");
        }

        return redirect()->route('admin.bookings.create', ['property' => $property->id, 'waitlist' => $waitlistRequest->id]);
    }

    public function refresh(Property $property, Waitlists $waitlists): RedirectResponse
    {
        $counts = $waitlists->refresh($property);

        return back()->with('success', "Waitlist re-checked: {$counts['available']} with rooms available, {$counts['waiting']} waiting, {$counts['expired']} expired.");
    }

    /**
     * @param  array{q?: string, status?: string}  $filters
     * @return Builder<WaitlistRequest>
     */
    private function filteredQuery(Property $property, array $filters): Builder
    {
        return $property->waitlistRequests()->getQuery()
            ->with(['roomCategory:id,name', 'booking:id,booking_ref_id', 'createdBy:id,name'])
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['q'] ?? null, function (Builder $query, string $term): void {
                $like = '%'.$term.'%';
                $query->where(fn (Builder $inner) => $inner->whereLike('guest_name', $like)->orWhereLike('phone', $like)->orWhereLike('email', $like));
            })
            // Requests with rooms free come first, then the longest waiting.
            ->orderByRaw("case when status = 'available' then 0 when status = 'waiting' then 1 else 2 end")
            ->orderBy('check_in')
            ->orderBy('created_at');
    }

    /**
     * @return array<string, mixed>
     */
    private function row(WaitlistRequest $item): array
    {
        return [
            'id' => $item->id,
            'guest_name' => $item->guest_name,
            'phone' => $item->phone,
            'email' => $item->email,
            'room_category_id' => $item->room_category_id,
            'room_category' => $item->roomCategory?->name ?? 'Any',
            'check_in' => $item->check_in->toDateString(),
            'check_out' => $item->check_out->toDateString(),
            'nights' => $item->nights(),
            'rooms' => $item->rooms,
            'adults' => $item->adults,
            'children' => $item->children,
            'notes' => $item->notes,
            'source' => $item->source,
            'status' => $item->status->value,
            'status_label' => $item->status->label(),
            'is_open' => $item->isOpen(),
            'available_since' => $item->available_since?->toIso8601ZuluString(),
            'booking_id' => $item->booking_id,
            'booking_ref_id' => $item->booking?->booking_ref_id,
            'created_at' => $item->created_at?->toIso8601ZuluString(),
            'created_by' => $item->createdBy?->name,
        ];
    }

    private function selectedProperty(Request $request): ?Property
    {
        $properties = app(ApplicationSettings::class)->orderedProperties(['id', 'name', 'abbreviation', 'country_id']);

        return app(CurrentProperty::class)->selected($request, $properties);
    }
}
