<?php

use App\Enums\OutletType;
use App\Models\Currency;
use App\Models\Language;
use App\Models\MetaField;
use App\Models\Outlet;
use App\Models\OutletDisplayCategory;
use App\Models\OutletItem;
use App\Models\OutletPricePlan;
use App\Models\OutletTaxRate;
use App\Models\Property;
use App\Models\User;
use App\Services\OutletPricing;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Role::firstOrCreate(['name' => 'admin']);
    Currency::firstOrCreate(['code' => 'USD'], ['name' => 'USD']);
    Language::firstOrCreate(['code' => 'en'], ['name' => 'English']);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');
    $this->property = Property::factory()->create();
});

test('an outlet is added, listed, edited and deleted with an activity log', function () {
    $this->actingAs($this->admin)
        ->post(route('admin.outlets.store', $this->property), ['name' => 'Meal', 'type' => 'FoodAndBeverages', 'printer_setting' => 'a4', 'status' => true])
        ->assertSessionHas('success', 'Outlet Meal added.');

    $outlet = $this->property->outlets()->sole();
    expect($outlet->type)->toBe(OutletType::FoodAndBeverages)
        ->and($outlet->display_order)->toBe(1);

    $this->actingAs($this->admin)
        ->put(route('admin.outlets.update', [$this->property, $outlet]), ['name' => 'Restaurant', 'type' => 'FoodAndBeverages', 'printer_setting' => 'thermal_80', 'display_order' => 2, 'status' => false])
        ->assertSessionHas('success');

    $this->actingAs($this->admin)
        ->get(route('admin.outlets.edit', $this->property))
        ->assertInertia(fn ($page) => $page
            ->component('admin/outlets/Edit')
            ->where('outlets.0.name', 'Restaurant')
            ->where('outlets.0.printer_setting_label', 'Thermal 80mm')
            ->where('outlets.0.status', false)
            ->where('activityLogs.0.action', 'Outlet Restaurant updated')
            ->has('types', count(OutletType::cases())));

    $this->actingAs($this->admin)->delete(route('admin.outlets.destroy', [$this->property, $outlet]))->assertRedirect(route('admin.outlets.edit', $this->property));
    expect(Outlet::query()->count())->toBe(0);
});

test('an outlet rejects an unknown type or printer setting', function () {
    $this->actingAs($this->admin)
        ->post(route('admin.outlets.store', $this->property), ['name' => 'X', 'type' => 'Casino', 'printer_setting' => 'dot_matrix'])
        ->assertSessionHasErrors(['type', 'printer_setting']);
});

test('another property\'s outlet can\'t be reached through this property', function () {
    $outlet = Outlet::factory()->create();

    $this->actingAs($this->admin)->get(route('admin.outlets.show', [$this->property, $outlet]))->assertNotFound();
});

test('display categories group catalogue items, and one still holding items can\'t be deleted', function () {
    $outlet = Outlet::factory()->create(['property_id' => $this->property->id, 'name' => 'Meal']);

    $this->actingAs($this->admin)->post(route('admin.outlets.displayCategories.store', [$this->property, $outlet]), ['name' => 'Special Items'])->assertSessionHas('success');
    $category = $outlet->displayCategories()->sole();

    // Tax Settings → Tax Categories.
    $field = MetaField::query()->firstOrCreate(['key' => 'tax_settings_tax_category'], ['key_label' => 'Tax category', 'status' => true]);
    $this->property->metaFieldValues()->create(['meta_fields_key_id' => $field->id, 'enabled' => true, 'data' => json_encode(['categories' => [
        ['id' => 1, 'name' => 'Room', 'deleted' => false], ['id' => 2, 'name' => 'Meal', 'deleted' => false], ['id' => 3, 'name' => 'Old', 'deleted' => true],
    ]])]);

    $this->actingAs($this->admin)
        ->post(route('admin.outlets.items.store', [$this->property, $outlet]), ['name' => 'BreakfastAdult', 'outlet_display_category_id' => $category->id, 'tax_category_id' => 2, 'item_type' => 'none', 'status' => true])
        ->assertSessionHas('success');

    $this->actingAs($this->admin)
        ->get(route('admin.outlets.show', [$this->property, $outlet]))
        ->assertInertia(fn ($page) => $page
            ->component('admin/outlets/Show')
            ->where('displayCategories.0.items_count', 1)
            ->where('items.0.display_category', 'Special Items')
            ->where('items.0.tax_category', 'Meal')
            ->has('taxCategories', 2));

    $this->actingAs($this->admin)
        ->delete(route('admin.outlets.displayCategories.destroy', [$this->property, $outlet, $category]))
        ->assertSessionHas('error');
    expect($category->fresh())->not->toBeNull();

    // Another outlet's category can't be used.
    $foreign = OutletDisplayCategory::factory()->create();
    $this->actingAs($this->admin)
        ->post(route('admin.outlets.items.store', [$this->property, $outlet]), ['name' => 'X', 'outlet_display_category_id' => $foreign->id, 'item_type' => 'none'])
        ->assertSessionHasErrors('outlet_display_category_id');
});

test('catalogue items save which items the outlet sells', function () {
    $outlet = Outlet::factory()->create(['property_id' => $this->property->id]);
    [$breakfast, $lunch, $dinner] = OutletItem::factory()->count(3)->create(['outlet_id' => $outlet->id, 'status' => true]);

    $this->actingAs($this->admin)
        ->put(route('admin.outlets.items.statuses', [$this->property, $outlet]), ['active_item_ids' => [$breakfast->id, $dinner->id]])
        ->assertSessionHas('success');

    expect($breakfast->fresh()->status)->toBeTrue()
        ->and($lunch->fresh()->status)->toBeFalse()
        ->and($dinner->fresh()->status)->toBeTrue();
});

test('a price plan holds item prices for a date range; blank prices drop out', function () {
    $outlet = Outlet::factory()->create(['property_id' => $this->property->id]);
    [$breakfast, $lunch] = OutletItem::factory()->count(2)->create(['outlet_id' => $outlet->id]);

    $this->actingAs($this->admin)
        ->post(route('admin.outlets.pricePlans.store', [$this->property, $outlet]), ['name' => 'Special Items Price Planner', 'start_date' => '1970-01-01', 'end_date' => '2099-12-31', 'status' => true])
        ->assertSessionHas('success');
    $plan = $outlet->pricePlans()->sole();

    $this->actingAs($this->admin)
        ->put(route('admin.outlets.pricePlans.prices', [$this->property, $outlet, $plan]), ['prices' => [
            ['outlet_item_id' => $breakfast->id, 'price' => '250'],
            ['outlet_item_id' => $lunch->id, 'price' => '400.5'],
        ]])
        ->assertSessionHas('success');

    $this->actingAs($this->admin)
        ->get(route('admin.outlets.show', [$this->property, $outlet]))
        ->assertInertia(fn ($page) => $page->where("pricePlans.0.prices.{$breakfast->id}", 250)->where("pricePlans.0.prices.{$lunch->id}", 400.5));

    $this->actingAs($this->admin)
        ->put(route('admin.outlets.pricePlans.prices', [$this->property, $outlet, $plan]), ['prices' => [['outlet_item_id' => $lunch->id, 'price' => null]]])
        ->assertSessionHas('success');

    expect($plan->prices()->pluck('outlet_item_id')->all())->toBe([$breakfast->id]);
});

test('a price plan needs an end date on or after its start, and only prices its own outlet\'s items', function () {
    $outlet = Outlet::factory()->create(['property_id' => $this->property->id]);
    $plan = OutletPricePlan::factory()->create(['outlet_id' => $outlet->id]);
    $foreignItem = OutletItem::factory()->create();

    $this->actingAs($this->admin)
        ->post(route('admin.outlets.pricePlans.store', [$this->property, $outlet]), ['name' => 'Bad', 'start_date' => '2026-10-10', 'end_date' => '2026-10-01'])
        ->assertSessionHasErrors('end_date');

    $this->actingAs($this->admin)
        ->put(route('admin.outlets.pricePlans.prices', [$this->property, $outlet, $plan]), ['prices' => [['outlet_item_id' => $foreignItem->id, 'price' => 10]]])
        ->assertSessionHasErrors('prices.0.outlet_item_id');
});

test('an item is priced by the active plan covering the day, the latest-starting one winning', function () {
    $outlet = Outlet::factory()->create(['property_id' => $this->property->id]);
    $item = OutletItem::factory()->create(['outlet_id' => $outlet->id]);
    $standard = OutletPricePlan::factory()->create(['outlet_id' => $outlet->id, 'start_date' => '1970-01-01', 'end_date' => '2099-12-31']);
    $festive = OutletPricePlan::factory()->create(['outlet_id' => $outlet->id, 'start_date' => '2026-12-20', 'end_date' => '2027-01-05']);
    $inactive = OutletPricePlan::factory()->create(['outlet_id' => $outlet->id, 'start_date' => '2026-12-25', 'end_date' => '2026-12-25', 'status' => false]);
    $standard->prices()->create(['outlet_item_id' => $item->id, 'price' => 250]);
    $festive->prices()->create(['outlet_item_id' => $item->id, 'price' => 300]);
    $inactive->prices()->create(['outlet_item_id' => $item->id, 'price' => 999]);
    $pricing = app(OutletPricing::class);

    expect($pricing->priceFor($item, '2026-10-01'))->toBe(250.0)
        ->and($pricing->priceFor($item, '2026-12-25'))->toBe(300.0)
        ->and($pricing->priceFor(OutletItem::factory()->create(['outlet_id' => $outlet->id]), '2026-10-01'))->toBeNull();
});

test('the tax settings tab adds tax rates that items pick, with a SAC/HSN code', function () {
    $outlet = Outlet::factory()->create(['property_id' => $this->property->id, 'name' => 'Meal']);

    $this->actingAs($this->admin)
        ->post(route('admin.outlets.taxRates.store', [$this->property, $outlet]), ['name' => 'GST 5%', 'rate' => 5, 'status' => true])
        ->assertSessionHas('success', 'Tax rate GST 5% added.');
    $taxRate = $outlet->taxRates()->sole();

    $this->actingAs($this->admin)
        ->post(route('admin.outlets.items.store', [$this->property, $outlet]), ['name' => 'BreakfastAdult', 'outlet_tax_rate_id' => $taxRate->id, 'sac_hsn_code' => '996331', 'item_type' => 'none'])
        ->assertSessionHas('success');
    $item = $outlet->items()->sole();

    expect($item->sac_hsn_code)->toBe('996331')
        ->and(app(OutletPricing::class)->taxFor($item, 250))->toBe(12.5);

    $this->actingAs($this->admin)
        ->get(route('admin.outlets.show', [$this->property, $outlet]))
        ->assertInertia(fn ($page) => $page
            ->where('taxRates.0.name', 'GST 5%')
            ->where('taxRates.0.rate', 5)
            ->where('taxRates.0.items_count', 1)
            ->where('items.0.tax_rate', 'GST 5%')
            ->where('items.0.sac_hsn_code', '996331'));

    // In use: it can't be deleted, but switched off it isn't charged.
    $this->actingAs($this->admin)->delete(route('admin.outlets.taxRates.destroy', [$this->property, $outlet, $taxRate]))->assertSessionHas('error');
    $this->actingAs($this->admin)->put(route('admin.outlets.taxRates.update', [$this->property, $outlet, $taxRate]), ['name' => 'GST 5%', 'rate' => 5, 'status' => false]);

    expect(app(OutletPricing::class)->taxFor($item->fresh(), 250))->toBe(0.0);
});

test('an item only takes its own outlet\'s tax rates and a 4–8 digit SAC/HSN code', function () {
    $outlet = Outlet::factory()->create(['property_id' => $this->property->id]);
    $foreignRate = OutletTaxRate::factory()->create();

    $this->actingAs($this->admin)
        ->post(route('admin.outlets.items.store', [$this->property, $outlet]), ['name' => 'Tea', 'outlet_tax_rate_id' => $foreignRate->id, 'sac_hsn_code' => '99-63', 'item_type' => 'none'])
        ->assertSessionHasErrors(['outlet_tax_rate_id', 'sac_hsn_code' => 'The SAC/HSN code must be 4 to 8 digits.']);

    $this->actingAs($this->admin)
        ->post(route('admin.outlets.taxRates.store', [$this->property, $outlet]), ['name' => 'Bad', 'rate' => 150])
        ->assertSessionHasErrors('rate');
});
