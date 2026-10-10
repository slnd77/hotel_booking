<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\BookingChannel;
use App\Services\PermissionCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Smart chart → Manage Channels: the OTAs bookings can come from (the booking form's "Selected
 * OTA"): add one, rename it, switch it on or off. For the account owner, like other settings.
 */
class BookingChannelController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizeOwner($request);

        return response()->json([
            'data' => BookingChannel::query()->withCount('bookings')->orderBy('name')->get()->map(fn (BookingChannel $channel): array => $this->row($channel)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeOwner($request);

        $channel = BookingChannel::create($request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('booking_channels', 'name')],
        ]));

        return response()->json(['message' => "{$channel->name} added.", 'channel' => $this->row($channel->loadCount('bookings'))], 201);
    }

    public function update(Request $request, BookingChannel $bookingChannel): JsonResponse
    {
        $this->authorizeOwner($request);

        $bookingChannel->update($request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('booking_channels', 'name')->ignore($bookingChannel->id)],
            'is_active' => ['sometimes', 'boolean'],
        ]));

        return response()->json(['message' => "{$bookingChannel->name} saved.", 'channel' => $this->row($bookingChannel->loadCount('bookings'))]);
    }

    private function authorizeOwner(Request $request): void
    {
        abort_unless($request->user()->hasAnyRole(PermissionCatalog::ownerRoles()), 403, 'Only the account owner manages channels.');
    }

    /**
     * @return array{id: int, name: string, is_active: bool, bookings_count: int}
     */
    private function row(BookingChannel $channel): array
    {
        return [
            'id' => $channel->id,
            'name' => $channel->name,
            'is_active' => $channel->is_active,
            'bookings_count' => (int) $channel->bookings_count,
        ];
    }
}
