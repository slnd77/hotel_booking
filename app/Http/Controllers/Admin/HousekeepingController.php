<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Property;
use App\Models\Room;
use App\Services\Housekeeping;
use App\Services\PermissionGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Smart chart → room name → "Mark as Clean / Dirty / …".
 */
class HousekeepingController extends Controller
{
    public function __invoke(Request $request, Property $property, Room $room, Housekeeping $housekeeping): JsonResponse
    {
        abort_unless($room->roomCategory?->property_id === $property->id, 404);

        $status = $request->validate(['status' => ['required', 'string', 'max:64']])['status'];
        app(PermissionGate::class)->housekeeping($request->user(), $status);

        $housekeeping->mark($property, [$room], $status);

        ActivityLog::create([
            'user_id' => $request->user()->id,
            'property_id' => $property->id,
            'guest_id' => null,
            'action' => "Housekeeping : {$room->name} marked as {$status}",
            'date' => now(),
        ]);

        return response()->json([
            'message' => "{$room->name} marked as {$status}.",
            'housekeeping' => $housekeeping->statusOf($property, $room->fresh()),
        ]);
    }
}
