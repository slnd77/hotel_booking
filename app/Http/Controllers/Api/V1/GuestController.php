<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Admin\GuestController as AdminGuestController;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\GuestResource;
use App\Models\Guest;
use App\Models\Property;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Guest profiles of a property: list (search, changed since), read one and edit it. Editing
 * uses the panel's guest form rules (Application Settings → Guest); guests are added with
 * POST guests (the booking form's guest picker, scope bookings:write).
 */
class GuestController extends Controller
{
    public function index(Request $request, Property $property): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'updated_since' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $guests = $property->guests()
            ->when($filters['q'] ?? null, fn (Builder $query, string $term) => $query->where(fn (Builder $query) => $query
                ->whereLike('first_name', "%{$term}%")
                ->orWhereLike('last_name', "%{$term}%")
                ->orWhereLike('phone', "%{$term}%")
                ->orWhereLikeText('emails', "%{$term}%")))
            ->when($filters['updated_since'] ?? null, fn (Builder $query, string $since) => $query->where('updated_at', '>=', CarbonImmutable::parse($since)->utc()))
            ->latest('id')
            ->paginate($filters['per_page'] ?? 25)
            ->withQueryString();

        return GuestResource::collection($guests);
    }

    public function show(Property $property, Guest $guest): GuestResource
    {
        abort_unless($guest->property_id === $property->id, 404);

        return new GuestResource($guest);
    }

    public function update(Request $request, Property $property, Guest $guest): GuestResource
    {
        app(AdminGuestController::class)->update($request, $property, $guest);

        return new GuestResource($guest->refresh());
    }
}
