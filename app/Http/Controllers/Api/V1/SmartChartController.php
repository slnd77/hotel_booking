<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Controller;
use App\Models\Property;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The smart booking chart, as the web admin's chart gets it: the days (with holidays, enquiry
 * counts and notes), availability per category, each room's stays, blocks and housekeeping.
 */
class SmartChartController extends Controller
{
    public function show(Request $request, Property $property): JsonResponse
    {
        $data = $request->validate(['start_date' => ['nullable', 'date_format:Y-m-d']]);

        return response()->json(app(DashboardController::class)->getSmartChartData($property, $data['start_date'] ?? null));
    }
}
