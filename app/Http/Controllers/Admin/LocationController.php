<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\City;
use App\Models\State;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class LocationController extends Controller
{
    /**
     * @return Collection<int, State>
     */
    public function states(Request $request): Collection
    {
        $request->validate(['country_id' => ['required', 'integer', 'exists:countries,id']]);

        return State::query()
            ->where('country_id', $request->integer('country_id'))
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    /**
     * @return Collection<int, City>
     */
    public function cities(Request $request): Collection
    {
        $request->validate(['state_id' => ['required', 'integer', 'exists:states,id']]);

        return City::query()
            ->where('state_id', $request->integer('state_id'))
            ->orderBy('name')
            ->get(['id', 'name']);
    }
}
