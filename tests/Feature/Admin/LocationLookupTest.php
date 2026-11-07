<?php

use App\Models\City;
use App\Models\Country;
use App\Models\State;
use App\Models\User;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Role::firstOrCreate(['name' => 'admin']);
    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');
});

test('states are filtered by country', function () {
    $country = Country::create(['name' => 'Testland', 'iso3' => 'TST', 'iso2' => 'TL']);
    $otherCountry = Country::create(['name' => 'Otherland', 'iso3' => 'OTH', 'iso2' => 'OT']);

    $state = State::create(['name' => 'Test State', 'country_id' => $country->id]);
    State::create(['name' => 'Other State', 'country_id' => $otherCountry->id]);

    $response = $this->actingAs($this->admin)->get("/admin/locations/states?country_id={$country->id}");

    $response->assertOk();
    $response->assertJson([['id' => $state->id, 'name' => 'Test State']]);
});

test('cities are filtered by state', function () {
    $country = Country::create(['name' => 'Testland', 'iso3' => 'TST', 'iso2' => 'TL']);
    $state = State::create(['name' => 'Test State', 'country_id' => $country->id]);
    $otherState = State::create(['name' => 'Other State', 'country_id' => $country->id]);

    $city = City::create(['name' => 'Test City', 'state_id' => $state->id, 'country_id' => $country->id]);
    City::create(['name' => 'Other City', 'state_id' => $otherState->id, 'country_id' => $country->id]);

    $response = $this->actingAs($this->admin)->get("/admin/locations/cities?state_id={$state->id}");

    $response->assertOk();
    $response->assertJson([['id' => $city->id, 'name' => 'Test City']]);
});
