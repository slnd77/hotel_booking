<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ChartNote;
use App\Models\Property;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Smart chart → a date's note icon: users add notes to a day and edit their own.
 */
class ChartNoteController extends Controller
{
    public function store(Request $request, Property $property): JsonResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'body' => ['required', 'string', 'max:1000'],
        ]);

        ChartNote::create([...$data, 'property_id' => $property->id, 'user_id' => $request->user()->id]);

        return response()->json(['notes' => $this->notesOn($property, $data['date'])]);
    }

    public function update(Request $request, Property $property, ChartNote $chartNote): JsonResponse
    {
        abort_unless($chartNote->property_id === $property->id, 404);
        abort_unless($chartNote->user_id === $request->user()->id, 403, 'You can only edit your own notes.');

        $chartNote->update($request->validate(['body' => ['required', 'string', 'max:1000']]));

        return response()->json(['notes' => $this->notesOn($property, $chartNote->date->toDateString())]);
    }

    /**
     * @return list<array{id: int, user_id: int, user_name: string, body: string, updated_at: string|null}>
     */
    private function notesOn(Property $property, string $date): array
    {
        return array_values(ChartNote::query()
            ->where('property_id', $property->id)
            ->whereDate('date', $date)
            ->with('user:id,name')
            ->orderBy('id')
            ->get()
            ->map(fn (ChartNote $note): array => $note->toChartArray())
            ->all());
    }
}
