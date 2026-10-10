<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Services\ApplicationSettings;
use Illuminate\Http\JsonResponse;

/**
 * The properties, in the account's order, with the timezone and business date the app shows
 * their times in.
 */
class PropertyController extends Controller
{
    public function index(ApplicationSettings $settings): JsonResponse
    {
        return response()->json([
            'data' => $settings->orderedProperties(['id', 'name', 'abbreviation', 'timezone'])->map(fn (Property $property): array => [
                'id' => $property->id,
                'name' => $property->name,
                'abbreviation' => $property->abbreviation,
                'timezone' => $property->timezoneName(),
                'today' => $property->today(),
            ])->values(),
        ]);
    }
}
