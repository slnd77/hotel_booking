<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StorePropertyGroupsRequest;
use App\Http\Requests\Admin\UpdatePropertyGroupsRequest;
use App\Models\PropertyGroups;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class PropertyGroupsController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/property-groups/Index', [
            'propertyGroups' => PropertyGroups::query()->latest()->paginate(10),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/property-groups/Create');
    }

    public function store(StorePropertyGroupsRequest $request): RedirectResponse
    {
        PropertyGroups::create($request->validated());

        return redirect()->route('admin.property-groups.index')->with('success', 'Property group created.');
    }

    public function show(PropertyGroups $propertyGroup): Response
    {
        return Inertia::render('admin/property-groups/Show', [
            'propertyGroup' => $propertyGroup,
        ]);
    }

    public function edit(PropertyGroups $propertyGroup): Response
    {
        return Inertia::render('admin/property-groups/Edit', [
            'propertyGroup' => $propertyGroup,
        ]);
    }

    public function update(UpdatePropertyGroupsRequest $request, PropertyGroups $propertyGroup): RedirectResponse
    {
        $propertyGroup->update($request->validated());

        return redirect()->route('admin.property-groups.index')->with('success', 'Property group updated.');
    }
}
