<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StorePropertyCancellationRuleRequest;
use App\Http\Requests\Admin\UpdatePropertyCancellationRuleRequest;
use App\Models\Property;
use App\Models\PropertyCancellationRule;
use Illuminate\Http\RedirectResponse;

class PropertyCancellationRuleController extends Controller
{
    public function store(StorePropertyCancellationRuleRequest $request, Property $property): RedirectResponse
    {
        $property->cancellationRules()->create($request->validated());

        return redirect()->route('admin.properties.edit', [$property, 'tab' => 'cancellation-rules'])->with('success', 'Cancellation rule created.');
    }

    public function update(UpdatePropertyCancellationRuleRequest $request, Property $property, PropertyCancellationRule $cancellationRule): RedirectResponse
    {
        $cancellationRule->update($request->validated());

        return redirect()->route('admin.properties.edit', [$property, 'tab' => 'cancellation-rules'])->with('success', 'Cancellation rule updated.');
    }

    public function destroy(Property $property, PropertyCancellationRule $cancellationRule): RedirectResponse
    {
        $cancellationRule->delete();

        return redirect()->route('admin.properties.edit', [$property, 'tab' => 'cancellation-rules'])->with('success', 'Cancellation rule deleted.');
    }
}
