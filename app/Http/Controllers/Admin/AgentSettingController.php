<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AgentRateCode;
use App\Models\Property;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings → Agent settings: the property's agent rate codes (an agency's "Agency Class").
 */
class AgentSettingController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/agent-settings/Index');
    }

    public function edit(Property $property): Response
    {
        return Inertia::render('admin/agent-settings/Edit', [
            'property' => $property->only(['id', 'name']),
            'rateCodes' => $property->agentRateCodes()
                ->withCount('agencies')
                ->orderBy('name')
                ->get()
                ->map(fn (AgentRateCode $rateCode): array => [
                    'id' => $rateCode->id,
                    'name' => $rateCode->name,
                    'code' => $rateCode->code,
                    'description' => $rateCode->description,
                    'is_active' => $rateCode->is_active,
                    'agencies_count' => $rateCode->agencies_count,
                ]),
        ]);
    }

    public function store(Request $request, Property $property): RedirectResponse
    {
        $rateCode = $property->agentRateCodes()->create($this->validated($request, $property));

        return back()->with('success', "Rate code {$rateCode->name} added.");
    }

    public function update(Request $request, Property $property, AgentRateCode $rateCode): RedirectResponse
    {
        abort_unless($rateCode->property_id === $property->id, 404);

        $rateCode->update($this->validated($request, $property, $rateCode));

        return back()->with('success', "Rate code {$rateCode->name} updated.");
    }

    public function destroy(Property $property, AgentRateCode $rateCode): RedirectResponse
    {
        abort_unless($rateCode->property_id === $property->id, 404);

        if ($rateCode->agencies()->exists()) {
            return back()->with('error', "Rate code {$rateCode->name} is used by agencies. Change their rate code or switch it off instead.");
        }

        $rateCode->delete();

        return back()->with('success', "Rate code {$rateCode->name} deleted.");
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, Property $property, ?AgentRateCode $rateCode = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique(AgentRateCode::class)->where('property_id', $property->id)->ignore($rateCode)],
            'code' => ['nullable', 'string', 'max:30'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['boolean'],
        ], [
            'name.unique' => 'A rate code with this name already exists.',
        ]);
    }
}
