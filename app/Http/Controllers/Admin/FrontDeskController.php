<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Services\FrontDeskBoard;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FrontDeskController extends Controller
{
    /**
     * Opens on the property picked in the header.
     */
    public function index(): Response
    {
        return Inertia::render('admin/front-desk/Index');
    }

    /**
     * Arrivals, departures and the in-house list for a day (default: the property's today).
     */
    public function show(Request $request, Property $property, FrontDeskBoard $board): Response
    {
        $validated = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);
        $date = CarbonImmutable::parse($validated['date'] ?? $property->today());

        return Inertia::render('admin/front-desk/Show', [
            'property' => $property->only(['id', 'name']),
            'today' => $property->today(),
            'board' => $board->forDate($property, $date),
        ]);
    }
}
