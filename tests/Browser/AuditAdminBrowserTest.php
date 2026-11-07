<?php

use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;

require_once __DIR__.'/Support/AuditBrowser.php';

beforeEach(function () {
    auditBootstrap();
    $this->admin = auditAdmin();
    [$this->property, $this->category, $this->room] = auditProperty();
});

// TC-ADM-01
test('the property pages list its room categories and rooms, and rate settings opens on the rate plans', function () {
    $suite = RoomCategory::factory()->create(['property_id' => $this->property->id, 'name' => 'Suite', 'meal_plans' => ['EP'], 'display_order' => 2]);
    Room::factory()->create(['room_category_id' => $suite->id, 'name' => 'S1']);
    $this->actingAs($this->admin);

    visit(route('admin.properties.show', $this->property))
        ->assertSee($this->property->name)
        ->assertNoJavaScriptErrors();

    visit(route('admin.properties.edit', [$this->property, 'tab' => 'rooms-setup']))
        ->assertSee('Deluxe')
        ->assertSee('Suite')
        ->click('tr:has-text("Deluxe") button:text-is("Edit")')
        ->assertSee('Editing Room Category: Deluxe')
        ->click('button:text-is("Rooms under Category")')
        ->assertSee('101')
        ->assertSee('Add new room')
        ->assertNoJavaScriptErrors();

    visit(route('admin.rateSettings.edit', $this->property))
        ->assertSee('Rate Plan')
        ->assertSee('Active Plans')
        ->assertSee('Default')
        ->assertNoJavaScriptErrors();

    // The rate planner reads as a price grid: each date shows the default plan's single rate.
    visit(route('admin.rateSettings.edit', [$this->property, 'tab' => 'rate-planner']))
        ->assertSee('BAR Calendar')
        ->assertVisible('select[title^="The price shown"]')
        ->assertSee('USD 2000')
        ->assertNoJavaScriptErrors();
});

// TC-ADM-02
test('a Front Office user is refused the night audit, rate settings and users pages that the admin opens', function () {
    $frontOffice = auditRoleUser('Front Office');
    $this->actingAs($frontOffice);

    visit(route('admin.nightAudits.edit', $this->property))->assertSee('403');
    visit(route('admin.rateSettings.edit', $this->property))->assertSee('403');
    visit(route('admin.users.index'))->assertSee('403');

    $this->actingAs($this->admin);

    visit(route('admin.nightAudits.edit', $this->property))->assertSee('Night Audit')->assertDontSee('403');
    visit(route('admin.rateSettings.edit', $this->property))->assertSee('Rate Plan')->assertDontSee('403');
    visit(route('admin.users.index'))->assertSee('Users')->assertDontSee('403')->assertNoJavaScriptErrors();
});

// TC-ADM-03
test('the tax, booking and invoice settings pages render', function () {
    $this->actingAs($this->admin);

    visit(route('admin.taxSettings.edit', [$this->property, 'tab' => 'tax-components']))
        ->assertSee('Tax Settings')
        ->assertSee('CGST')
        ->assertSee('SGST')
        ->assertSee('Rates are set per tax rule')
        ->assertNoJavaScriptErrors();

    visit(route('admin.bookingSettings.edit', $this->property))
        ->assertSee('Booking Settings: '.$this->property->name)
        ->assertNoJavaScriptErrors();

    visit(route('admin.invoiceSettings.edit', $this->property))
        ->assertSee('Invoice Setting')
        ->assertNoJavaScriptErrors();
});

// TC-ADM-04
test('the users page lists the users with their roles', function () {
    $frontOffice = auditRoleUser('Front Office', [], 'Fiona Front');
    $manager = auditRoleUser('Reservation Manager', [], 'Ravi Reservations');
    $this->actingAs($this->admin);

    visit(route('admin.users.index'))
        ->assertSee('Users')
        ->assertSee($frontOffice->name)
        ->assertSee('Front Office')
        ->assertSee($manager->name)
        ->assertSee('Reservation Manager')
        ->assertSee($this->admin->name)
        ->assertNoJavaScriptErrors();
});

// TC-ADM-05
test('the roles page lists the system defined and the user defined roles', function () {
    auditCustomRoleUser('Housekeeper', ['housekeeping.board', 'housekeeping.tasks']);
    $this->actingAs($this->admin);

    visit(route('admin.roles.index'))
        ->assertSee('Roles and Permissions')
        ->assertSee('System Defined Roles')
        ->assertSee('Front Office')
        ->assertSee('Reservation Manager')
        ->assertSee('User Defined Roles')
        ->assertSee('Housekeeper')
        ->assertNoJavaScriptErrors();
});

// TC-ADM-06
test('the dashboard opens on the property in the link, with the header picker and sidebar on it', function () {
    $other = Property::factory()->create(['name' => 'Other Hotel', 'abbreviation' => 'OTH']);
    $this->actingAs($this->admin);

    visit(route('admin.dashboard', ['property' => $other->id]))
        ->assertSee('Smart Booking Chart')
        ->assertSee('Other Hotel (OTH)')
        ->assertAttribute('a[href$="/admin/front-desk/'.$other->id.'"]', 'href', '/admin/front-desk/'.$other->id)
        ->assertNoJavaScriptErrors();

    // A page about no property in particular keeps the one last used.
    visit(route('admin.users.index'))
        ->assertSee('Other Hotel (OTH)')
        ->assertNoJavaScriptErrors();
});
