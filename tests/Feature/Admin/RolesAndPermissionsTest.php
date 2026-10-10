<?php

use App\Models\Currency;
use App\Models\Language;
use App\Models\MetaField;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\User;
use App\Services\PermissionCatalog;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Currency::firstOrCreate(['code' => 'USD'], ['name' => 'USD']);
    Language::firstOrCreate(['code' => 'en'], ['name' => 'English']);

    $this->superAdmin = User::factory()->create();
    $this->superAdmin->assignRole(PermissionCatalog::ADMIN);

    $this->frontOffice = User::factory()->create();
    $this->frontOffice->assignRole('Sales Agent');

    $this->property = Property::factory()->create();
});

function grant(string $role, string ...$permissions): void
{
    Role::findByName($role)->givePermissionTo($permissions);
}

/** The test user's role with every permission removed, for checking each one alone. */
function restrictSalesAgent(): void
{
    Role::findByName('Sales Agent')->syncPermissions([]);
}

/** A confirmed booking request for one room, arriving on $checkIn. */
function bookingPayload(Property $property, string $checkIn): array
{
    $category = RoomCategory::factory()->create(['property_id' => $property->id, 'meal_plans' => ['EP']]);
    $room = Room::factory()->create(['room_category_id' => $category->id]);

    return [
        'source' => 'direct', 'type' => 'walk_in', 'status' => 'confirmed',
        'check_in' => $checkIn, 'check_out' => CarbonImmutable::parse($checkIn)->addDay()->toDateString(), 'discount' => 0,
        'room_bill_to' => 'to_guest', 'extras_bill_to' => 'to_guest',
        'rooms' => [['room_category_id' => $category->id, 'room_id' => $room->id, 'meal_plan' => 'EP', 'adults' => 1, 'children' => 0, 'infants' => 0, 'rate_per_night' => 1000]],
    ];
}

it('creates the permission catalogue, the predefined roles and the Account Admin matrix', function () {
    expect(Permission::query()->whereIn('name', PermissionCatalog::names())->count())->toBe(count(PermissionCatalog::names()))
        ->and(Permission::query()->where('name', 'database.payments.add_payment_link_to_payment')->exists())->toBeFalse();

    $accountAdmin = Role::findByName(PermissionCatalog::ACCOUNT_ADMIN)->permissions->pluck('name');
    expect($accountAdmin)->toHaveCount(count(PermissionCatalog::defaultsFor(PermissionCatalog::ACCOUNT_ADMIN)))
        ->toContain('reservations.enquiry.cancel_today', 'housekeeping.mark.clean')
        ->not->toContain('reservations.enquiry.cancel_past', 'smart_chart.expand_categories', 'database.payments.cancel', 'manage_account.tax_exempt_settings');

    $propertyAdmin = Role::findByName('Property Admin')->permissions->pluck('name');
    expect($propertyAdmin)->toHaveCount(count(PermissionCatalog::defaultsFor('Property Admin')))
        ->toContain('reservations.enquiry.cancel_past', 'manage_property.property_settings', 'manage_account.expense_management_settings', 'housekeeping.change_status')
        ->not->toContain('invoice.print_consolidated', 'manage_account.users', 'manage_property.rollover_settings', 'housekeeping.mark.clean');

    $reservationManager = Role::findByName('Reservation Manager')->permissions->pluck('name');
    expect($reservationManager)->toHaveCount(count(PermissionCatalog::ROLE_DEFAULTS_ONLY['Reservation Manager']))
        ->toContain('reservations.confirm.create_today', 'reservations.enquiry.create_past', 'database.payments.add')
        ->not->toContain('reservations.confirm.create_past', 'smart_chart.block_past', 'front_office.access', 'manage_property.property_settings');

    $frontOffice = Role::findByName('Front Office')->permissions->pluck('name');
    expect($frontOffice)->toHaveCount(count(PermissionCatalog::defaultsFor('Front Office')))
        ->toContain('reservations.confirm.create_today', 'reservations.enquiry.create_future', 'housekeeping.mark.dirty', 'front_office.access')
        ->not->toContain('reservations.confirm.create_future', 'reservations.enquiry.create_today', 'database.bookings.download', 'database.agents.add_agency');

    expect(Role::findByName('Sales Agent')->permissions->pluck('name')->sort()->values()->all())
        ->toBe($frontOffice->sort()->values()->all());
});

it('treats Account Admin as the account owner while its matrix still applies', function () {
    $owner = User::factory()->create();
    $owner->assignRole(PermissionCatalog::ACCOUNT_ADMIN);

    // Owner pages no permission covers.
    $this->actingAs($owner)->get(route('admin.property-groups.index'))->assertOk();
    $this->actingAs($owner)->put(route('admin.applicationSettings.update', 'day_posting'), ['transaction_posting_time' => '23:00'])->assertSessionHasNoErrors();

    // Granted in the matrix.
    $this->actingAs($owner)->get(route('admin.dashboard'))->assertOk();
    $this->actingAs($owner)->get(route('admin.roles.index'))->assertOk();

    // Not ticked for Account Admin.
    $this->actingAs($owner)->get(route('admin.databases.invoices.index'))->assertForbidden();
    $this->actingAs($owner)->get(route('admin.databases.payments.index'))->assertForbidden();
});

it('lists system and user defined roles and keeps system roles read only', function () {
    $role = Role::findByName('Reservation Manager');
    Role::create(['name' => 'Night Auditor', 'guard_name' => 'web']);

    $this->actingAs($this->superAdmin)
        ->get(route('admin.roles.index', ['role' => $role->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/roles/Index')
            ->has('systemRoles', 5)
            ->has('customRoles', 1)
            ->where('customRoles.0.name', 'Night Auditor')
            ->where('selectedRoleId', $role->id)
            ->where('catalogue.0.name', 'Reservations')
            ->where('catalogue.0.groups.0.name', 'Confirm')
            ->where('catalogue.0.groups.0.permissions.0.label', 'Create Reservation for Today'));

    $before = $role->permissions->pluck('name')->sort()->values()->all();

    $this->actingAs($this->superAdmin)
        ->put(route('admin.roles.update', $role), ['name' => 'Reservation Manager', 'permissions' => []])
        ->assertForbidden();
    $this->actingAs($this->superAdmin)->delete(route('admin.roles.destroy', $role))->assertForbidden();

    expect($role->fresh()->permissions->pluck('name')->sort()->values()->all())->toBe($before);
});

it('creates, edits and deletes a user defined role', function () {
    $this->actingAs($this->superAdmin)
        ->post(route('admin.roles.store'), ['name' => 'Night Auditor', 'permissions' => ['reservations.confirm.create_today', 'database.bookings.view']])
        ->assertSessionHasNoErrors();

    $role = Role::findByName('Night Auditor');
    expect($role->permissions->pluck('name')->sort()->values()->all())->toBe(['database.bookings.view', 'reservations.confirm.create_today']);

    $this->actingAs($this->superAdmin)
        ->put(route('admin.roles.update', $role), ['name' => 'Night Desk', 'permissions' => ['front_office.access']])
        ->assertRedirect(route('admin.roles.index', ['role' => $role->id]));
    expect($role->fresh()->name)->toBe('Night Desk')
        ->and($role->fresh()->permissions->pluck('name')->all())->toBe(['front_office.access']);

    // System role names, taken names and unknown permissions are refused.
    $this->actingAs($this->superAdmin)
        ->post(route('admin.roles.store'), ['name' => 'Front Office', 'permissions' => ['not.a.permission']])
        ->assertSessionHasErrors(['name', 'permissions.0']);
    $this->actingAs($this->superAdmin)
        ->post(route('admin.roles.store'), ['name' => 'Night Desk', 'permissions' => []])
        ->assertSessionHasErrors('name');

    // A role given to users can't be deleted.
    $user = User::factory()->create();
    $user->assignRole('Night Desk');
    $this->actingAs($this->superAdmin)->delete(route('admin.roles.destroy', $role))->assertSessionHas('error');
    expect(Role::query()->whereKey($role->id)->exists())->toBeTrue();

    $user->syncRoles([]);
    $this->actingAs($this->superAdmin)->delete(route('admin.roles.destroy', $role))->assertRedirect(route('admin.roles.index'));
    expect(Role::query()->whereKey($role->id)->exists())->toBeFalse();
});

it('lets a user defined role into the admin panel with its permissions', function () {
    $role = Role::create(['name' => 'Night Auditor', 'guard_name' => 'web']);
    $role->givePermissionTo('database.bookings.view');

    $this->actingAs($this->superAdmin)
        ->post(route('admin.users.store'), ['name' => 'Night', 'email' => 'night@example.com', 'password' => 'Password123!', 'password_confirmation' => 'Password123!', 'status' => 'active', 'role' => 'Night Auditor'])
        ->assertSessionHasNoErrors();
    $user = User::query()->where('email', 'night@example.com')->sole();

    $this->actingAs($user)->get('/dashboard')->assertRedirect(route('admin.dashboard'));
    $this->actingAs($user)->get(route('admin.databases.bookings.index'))->assertOk();
    $this->actingAs($user)->get(route('admin.databases.guests.index'))->assertForbidden();

    $plain = User::factory()->create();
    $plain->assignRole(Role::findOrCreate(PermissionCatalog::USER, 'web'));
    $this->actingAs($plain)->get(route('admin.dashboard'))->assertForbidden();
});

it('lets a role user into the admin panel only where their permissions allow', function () {
    restrictSalesAgent();
    // The Smart Chart dashboard is open to every panel role; what they may do there is checked per action.
    $this->actingAs($this->frontOffice)->get('/dashboard')->assertRedirect(route('admin.dashboard'));
    $this->actingAs($this->frontOffice)->get(route('admin.dashboard'))->assertOk();
    $this->actingAs($this->frontOffice)->get(route('admin.databases.bookings.index'))->assertForbidden();
    $this->actingAs($this->frontOffice)->get(route('admin.roles.index'))->assertForbidden();

    grant('Sales Agent', 'database.bookings.view');

    $this->actingAs($this->frontOffice)->get(route('admin.databases.bookings.index'))->assertOk();
    // Not in the catalogue's route map: the account owner's only.
    $this->actingAs($this->frontOffice)->put(route('admin.applicationSettings.update', 'reservation'), [])->assertForbidden();
});

it('allows creating a reservation only for the dates the role may book', function () {
    restrictSalesAgent();
    grant('Sales Agent', 'reservations.confirm.create_today');

    $this->actingAs($this->frontOffice)
        ->post(route('admin.bookings.store', $this->property), bookingPayload($this->property, $this->property->today()))
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $this->actingAs($this->frontOffice)
        ->post(route('admin.bookings.store', $this->property), bookingPayload($this->property, now()->addDays(5)->toDateString()))
        ->assertForbidden();
});

it('needs the matching permission to change a room\'s housekeeping status', function () {
    restrictSalesAgent();
    $room = Room::factory()->create(['room_category_id' => RoomCategory::factory()->create(['property_id' => $this->property->id])->id]);

    $this->actingAs($this->frontOffice)
        ->patchJson(route('admin.rooms.housekeeping', [$this->property, $room]), ['status' => 'Dirty'])
        ->assertForbidden();

    grant('Sales Agent', 'housekeeping.change_status', 'housekeeping.mark.dirty');

    $this->actingAs($this->frontOffice)
        ->patchJson(route('admin.rooms.housekeeping', [$this->property, $room]), ['status' => 'Dirty'])
        ->assertOk();
    $this->actingAs($this->frontOffice)
        ->patchJson(route('admin.rooms.housekeeping', [$this->property, $room]), ['status' => 'Clean'])
        ->assertForbidden();
});

it('shares the user\'s permissions with the pages', function () {
    restrictSalesAgent();
    grant('Sales Agent', 'front_office.access');

    $this->actingAs($this->frontOffice)
        ->get(route('admin.dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('auth.isSuperAdmin', false)
            ->where('auth.permissions', ['front_office.access']));
});

it('offers the predefined roles when adding a user', function () {
    $this->actingAs($this->superAdmin)
        ->post(route('admin.users.store'), ['name' => 'Desk', 'email' => 'desk@example.com', 'password' => 'Password123!', 'password_confirmation' => 'Password123!', 'status' => 'active', 'role' => 'Front Office'])
        ->assertSessionHasNoErrors();

    expect(User::query()->where('email', 'desk@example.com')->sole()->hasRole('Front Office'))->toBeTrue();
});

it('lets Super admin do anything, bypassing the permission matrix', function () {
    $super = User::factory()->create();
    $super->assignRole(PermissionCatalog::SUPER_ADMIN);

    expect(Role::findByName(PermissionCatalog::SUPER_ADMIN)->permissions)->toBeEmpty()
        // Laravel permission checks too, including ones outside the catalogue.
        ->and($super->can('checkout_on_outstanding_payments'))->toBeTrue()
        ->and($super->can('database.payments.cancel'))->toBeTrue();

    $this->actingAs($super)->get('/dashboard')->assertRedirect(route('admin.dashboard'));
    $this->actingAs($super)->get(route('admin.databases.invoices.index'))->assertOk();
    $this->actingAs($super)->put(route('admin.applicationSettings.update', 'day_posting'), ['transaction_posting_time' => '23:00'])->assertSessionHasNoErrors();
    $this->actingAs($super)
        ->post(route('admin.bookings.store', $this->property), bookingPayload($this->property, now()->addDays(5)->toDateString()))
        ->assertSessionHasNoErrors()
        ->assertRedirect();
    $this->actingAs($super)->get(route('admin.dashboard'))->assertInertia(fn (Assert $page) => $page->where('auth.isSuperAdmin', true));
});

it('lets only Super admin, Admin and Account Admin give users roles', function () {
    $accountAdmin = User::factory()->create();
    $accountAdmin->assignRole(PermissionCatalog::ACCOUNT_ADMIN);
    $propertyAdmin = User::factory()->create();
    $propertyAdmin->assignRole('Property Admin');
    grant('Property Admin', 'manage_account.users');

    $options = fn (User $grantor) => array_keys(PermissionCatalog::assignableRoles($grantor));

    expect($options($this->superAdmin))->toContain(PermissionCatalog::SUPER_ADMIN, PermissionCatalog::ADMIN, PermissionCatalog::ACCOUNT_ADMIN)
        ->and($options($accountAdmin))->toContain(PermissionCatalog::ACCOUNT_ADMIN, 'Front Office')->not->toContain(PermissionCatalog::SUPER_ADMIN, PermissionCatalog::ADMIN)
        ->and($options($propertyAdmin))->toBeEmpty();

    $this->actingAs($accountAdmin)
        ->post(route('admin.users.store'), ['name' => 'Boss', 'email' => 'boss@example.com', 'password' => 'Password123!', 'password_confirmation' => 'Password123!', 'status' => 'active', 'role' => PermissionCatalog::ADMIN])
        ->assertSessionHasErrors('role');
    $this->actingAs($accountAdmin)
        ->post(route('admin.users.store'), ['name' => 'Owner', 'email' => 'owner@example.com', 'password' => 'Password123!', 'password_confirmation' => 'Password123!', 'status' => 'active', 'role' => PermissionCatalog::ACCOUNT_ADMIN])
        ->assertSessionHasNoErrors();

    // A Super admin user is out of Account Admin's reach.
    $this->actingAs($accountAdmin)->get(route('admin.users.edit', $this->superAdmin))->assertForbidden();
    $this->actingAs($accountAdmin)->delete(route('admin.users.destroy', $this->superAdmin))->assertForbidden();
    $this->actingAs($accountAdmin)->get(route('admin.users.edit', $this->frontOffice))->assertOk();

    // Another role with access to Users edits details but can't give or change roles.
    $this->actingAs($propertyAdmin)
        ->post(route('admin.users.store'), ['name' => 'Desk', 'email' => 'desk@example.com', 'password' => 'Password123!', 'password_confirmation' => 'Password123!', 'status' => 'active', 'role' => 'Front Office'])
        ->assertSessionHasErrors('role');
    $this->actingAs($propertyAdmin)
        ->post(route('admin.users.store'), ['name' => 'Desk', 'email' => 'desk@example.com', 'password' => 'Password123!', 'password_confirmation' => 'Password123!', 'status' => 'active'])
        ->assertSessionHasNoErrors();
    expect(User::query()->where('email', 'desk@example.com')->sole()->getRoleNames()->all())->toBe([PermissionCatalog::USER]);

    $this->actingAs($propertyAdmin)
        ->put(route('admin.users.update', $this->frontOffice), ['name' => 'Renamed', 'email' => $this->frontOffice->email, 'status' => 'active'])
        ->assertSessionHasNoErrors();
    expect($this->frontOffice->fresh()->name)->toBe('Renamed')
        ->and($this->frontOffice->fresh()->hasRole('Sales Agent'))->toBeTrue();
    $this->actingAs($propertyAdmin)->get(route('admin.users.edit', $accountAdmin))->assertForbidden();
});

it('lists a "mark room as" permission for each housekeeping status and checks it', function () {
    $field = MetaField::query()->firstOrCreate(['key' => 'booking_settings_housekeeping'], ['key_label' => 'Housekeeping', 'status' => true]);
    $this->property->metaFieldValues()->create(['meta_fields_key_id' => $field->id, 'enabled' => true, 'data' => json_encode(['statuses' => [
        ['name' => 'Clean', 'color' => '#e3e3e0', 'display_order' => 1],
        ['name' => 'Out of Order', 'color' => '#111111', 'display_order' => 2],
    ]])]);
    $room = Room::factory()->create(['room_category_id' => RoomCategory::factory()->create(['property_id' => $this->property->id])->id]);

    expect(PermissionCatalog::names())->toContain('housekeeping.mark.clean', 'housekeeping.mark.dirty', 'housekeeping.mark.out_of_order');

    // Opening Roles and Permissions creates the new status's permission, so a role can be given it.
    $this->actingAs($this->superAdmin)
        ->get(route('admin.roles.index'))
        ->assertInertia(fn (Assert $page) => $page->where('catalogue.1.groups.0.permissions', fn ($permissions) => collect($permissions)->contains('label', 'User can mark room as Out of Order')));

    restrictSalesAgent();
    grant('Sales Agent', 'housekeeping.change_status');
    $this->actingAs($this->frontOffice)
        ->patchJson(route('admin.rooms.housekeeping', [$this->property, $room]), ['status' => 'Out of Order'])
        ->assertForbidden();

    grant('Sales Agent', 'housekeeping.mark.out_of_order');
    $this->actingAs($this->frontOffice)
        ->patchJson(route('admin.rooms.housekeeping', [$this->property, $room]), ['status' => 'Out of Order'])
        ->assertOk();
});
