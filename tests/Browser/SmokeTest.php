<?php

use App\Models\Currency;
use App\Models\Language;
use App\Models\Property;
use App\Models\RoomCategory;
use App\Models\User;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Role::firstOrCreate(['name' => 'admin']);
    Currency::firstOrCreate(['code' => 'USD'], ['name' => 'USD']);
    Language::firstOrCreate(['code' => 'en'], ['name' => 'English']);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');
    $this->property = Property::factory()->create(['timezone' => 'UTC']);
    $category = RoomCategory::factory()->create(['property_id' => $this->property->id, 'meal_plans' => ['EP']]);
    sellOnBookingEngine($this->property, $category, 1500);
});

test('the admin pages open in a browser without JavaScript errors', function () {
    $this->actingAs($this->admin);
    $property = ['property' => $this->property->id];

    visit([
        route('admin.frontDesk.show', $this->property),
        route('admin.cashierShifts.show', $this->property),
        route('admin.pos.show', $this->property),
        route('admin.rateSettings.edit', $this->property),
        route('admin.rateSettings.edit', [$this->property, 'tab' => 'rounding']),
        route('admin.rateRestrictions.edit', $this->property),
        route('admin.legalSettings.edit', $this->property),
        route('admin.exchangeRates.edit', $this->property),
        route('admin.nightAuditSettings.edit', $this->property),
        route('admin.generalLedger.show', $this->property),
        route('admin.databases.cityLedger.index', $property),
        route('admin.databases.guests.index', $property),
        route('admin.databases.invoices.index', $property),
        route('admin.databases.payments.index', $property),
        route('admin.databases.bookings.index', $property),
        route('admin.bookings.create', $this->property),
    ])->assertNoJavaScriptErrors();
});

test('the guest-facing pages open in a browser without JavaScript errors', function () {
    visit([
        route('booking-engine.web', $this->property->ulid),
        route('legal.privacy', $this->property->ulid),
        route('legal.terms', $this->property->ulid),
    ])->assertNoJavaScriptErrors();
});
