<?php

/*
 * Shared set-up for the audit browser tests (tests/Browser/Audit*BrowserTest.php): a sellable
 * property with one Deluxe room at 2 000 a night and 5% tax (CGST 2.5% + SGST 2.5%), users by
 * role, and bookings in every stay state. Each test file requires it; the functions are guarded
 * so Pest may load the file as well.
 */

use App\Enums\BookingStatus;
use App\Enums\SubBookingStatus;
use App\Models\Booking;
use App\Models\Currency;
use App\Models\Guest;
use App\Models\Language;
use App\Models\MetaField;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\User;
use App\Services\PermissionCatalog;
use App\Services\PropertySettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

if (! function_exists('auditBootstrap')) {
    /**
     * The reference rows, system roles and the permission catalogue every audit scenario starts
     * from: the predefined roles carry their default permissions.
     */
    function auditBootstrap(): void
    {
        // Render pages in the browser: a developer's Inertia SSR server (port 13714) answers a
        // later page with the body of the one before it, so the tests must not depend on it.
        config(['inertia.ssr.enabled' => false]);

        Currency::firstOrCreate(['code' => 'USD'], ['name' => 'USD']);
        Language::firstOrCreate(['code' => 'en'], ['name' => 'English']);

        foreach (PermissionCatalog::systemRoles() as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        PermissionCatalog::ensurePermissions();

        foreach (PermissionCatalog::PREDEFINED_ROLES as $role) {
            Role::findByName($role, 'web')->syncPermissions(PermissionCatalog::defaultsFor($role));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    function auditAdmin(string $name = 'Admin Ana'): User
    {
        $admin = User::factory()->create(['name' => $name]);
        $admin->assignRole('admin');

        return $admin;
    }

    /**
     * A user with a predefined role (its default permissions) and any extra permissions.
     *
     * @param  list<string>  $extraPermissions
     */
    function auditRoleUser(string $role, array $extraPermissions = [], string $name = 'Front Desk Fiona'): User
    {
        $user = User::factory()->create(['name' => $name]);
        $user->assignRole($role);

        foreach ($extraPermissions as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }

    /**
     * A user with a user-defined role holding exactly these permissions.
     *
     * @param  list<string>  $permissions
     */
    function auditCustomRoleUser(string $role, array $permissions, string $name = 'Housekeeper Hema'): User
    {
        $customRole = Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        $customRole->syncPermissions(array_map(fn (string $permission) => Permission::findOrCreate($permission, 'web'), $permissions));

        $user = User::factory()->create(['name' => $name]);
        $user->assignRole($customRole);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }

    /**
     * A property in UTC with one Deluxe category (two adults) and room 101, sold on the booking
     * engine at $rate a night with $taxPercent tax on rooms.
     *
     * @return array{0: Property, 1: RoomCategory, 2: Room}
     */
    function auditProperty(float $rate = 2000, float $taxPercent = 5): array
    {
        $property = Property::factory()->create(['timezone' => 'UTC']);
        RoomCategory::query()->where('property_id', $property->id)->delete();

        $category = RoomCategory::factory()->create([
            'property_id' => $property->id, 'name' => 'Deluxe', 'max_adults' => 2, 'max_children' => 1,
            'ideal_occupancy' => 2, 'default_number_of_children' => 0, 'meal_plans' => ['EP'],
        ]);
        $room = Room::factory()->create(['room_category_id' => $category->id, 'name' => '101', 'display_order' => 1, 'housekeeping_status' => 'Clean']);

        sellOnBookingEngine($property, $category, $rate);
        auditTaxes($property, $taxPercent);

        return [$property, $category, $room];
    }

    /**
     * Tax Settings: one "Room" tax category with a rule of two components (CGST + SGST), each half
     * of $percent, on the rack rate.
     */
    function auditTaxes(Property $property, float $percent): void
    {
        $categoryField = MetaField::query()->firstOrCreate(['key' => 'tax_settings_tax_category'], ['key_label' => 'Tax Category', 'status' => true]);
        $ruleField = MetaField::query()->firstOrCreate(['key' => 'tax_settings_tax_rule'], ['key_label' => 'Tax Rule', 'status' => true]);
        $component = fn (int $id, string $name): array => [
            'id' => $id, 'component_choice' => $name, 'applied_on' => 'rack_rate', 'calculated_on' => 'per_room_per_room_night',
            'tax_value_type' => 'percentage', 'tax_value' => $percent / 2, 'define_tax_slab' => false, 'define_los_slab' => false, 'slabs' => [],
        ];

        $property->metaFieldValues()->updateOrCreate(['meta_fields_key_id' => $categoryField->id], ['enabled' => true, 'data' => json_encode(['categories' => [['id' => 1, 'name' => 'Room']]])]);
        $property->metaFieldValues()->updateOrCreate(['meta_fields_key_id' => $ruleField->id], ['enabled' => true, 'data' => json_encode([
            'component_choices' => [
                ['id' => 1, 'name' => 'Default Tax Choice', 'description' => ''],
                ['id' => 2, 'name' => 'CGST', 'description' => ''],
                ['id' => 3, 'name' => 'SGST', 'description' => ''],
            ],
            'rules' => [[
                'id' => 1, 'tax_category_id' => 1, 'name' => 'Room Tax Rule', 'description' => '', 'deleted' => false,
                'start_date' => null, 'end_date' => null, 'components' => [$component(1, 'CGST'), $component(2, 'SGST')],
            ]],
        ])]);

        app(PropertySettings::class)->forgetMetaFields();
        app(PropertySettings::class)->forget($property);
    }

    /**
     * Saves one of the property's JSON settings documents.
     *
     * @param  array<string, mixed>  $data
     */
    function auditSetting(Property $property, string $key, array $data): void
    {
        $field = MetaField::query()->firstOrCreate(['key' => $key], ['key_label' => $key, 'status' => true]);
        $property->metaFieldValues()->updateOrCreate(['meta_fields_key_id' => $field->id], ['enabled' => true, 'data' => json_encode($data)]);
        app(PropertySettings::class)->forgetMetaFields();
        app(PropertySettings::class)->forget($property);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    function auditGuest(Property $property, array $overrides = []): Guest
    {
        return Guest::factory()->create([
            'property_id' => $property->id, 'title' => 'Ms', 'first_name' => 'Asha', 'last_name' => 'Rao',
            'phone_country_code' => '+91', 'phone' => '9000000001', 'emails' => ['asha@example.com'],
            'nationality' => 'India', 'gender' => 'Female', 'dob' => '1990-01-01', 'purpose_of_visit' => 'Leisure',
            ...$overrides,
        ]);
    }

    /**
     * Books the room through the admin booking form (as $by), the way the front desk does: one
     * room of two adults at 2 000 a night, confirmed.
     *
     * @param  array<string, mixed>  $overrides
     */
    function auditStoreBooking(TestCase $test, User $by, Property $property, RoomCategory $category, ?Room $room, string $checkIn, string $checkOut, ?Guest $guest = null, array $overrides = []): Booking
    {
        $reference = 'AUD-'.Str::upper(Str::random(6));

        $test->actingAs($by)->post(route('admin.bookings.store', $property), [
            'booking_ref_id' => $reference, 'source' => 'direct', 'type' => 'walk_in', 'status' => 'confirmed', 'discount' => 0,
            'room_bill_to' => 'to_guest', 'extras_bill_to' => 'to_guest',
            'check_in' => $checkIn, 'check_out' => $checkOut,
            'guest_ids' => $guest !== null ? [$guest->id] : [],
            'rooms' => [['room_category_id' => $category->id, 'room_id' => $room?->id, 'meal_plan' => 'EP', 'adults' => 2, 'children' => 0, 'infants' => 0, 'rate_per_night' => 2000]],
            ...$overrides,
        ])->assertSessionHasNoErrors();

        return Booking::query()->where('booking_ref_id', $reference)->sole();
    }

    /**
     * A stay written straight to the database in the given room state (booked, checked in,
     * checked out), priced at $rate a night plus $taxPercent tax, nothing paid.
     */
    function auditStay(Property $property, Room $room, string $checkIn, string $checkOut, SubBookingStatus $status, ?Guest $guest = null, float $rate = 2000, float $taxPercent = 5): Booking
    {
        $nights = max(1, (int) CarbonImmutable::parse($checkIn)->diffInDays(CarbonImmutable::parse($checkOut)));
        $roomTotal = $rate * $nights;
        $taxes = round($roomTotal * $taxPercent / 100, 2);
        $bookingStatus = match ($status) {
            SubBookingStatus::CheckedIn => BookingStatus::CheckedIn,
            SubBookingStatus::CheckedOut => BookingStatus::CheckedOut,
            default => BookingStatus::Confirmed,
        };

        $booking = DB::transaction(fn (): Booking => $property->bookings()->create([
            'booking_ref_id' => 'AUD-'.Str::upper(Str::random(6)), 'source' => 'direct', 'type' => 'walk_in', 'status' => $bookingStatus,
            'check_in' => $checkIn, 'check_out' => $checkOut,
            'room_total' => $roomTotal, 'discount' => 0, 'taxes' => $taxes, 'total' => $roomTotal + $taxes, 'outstanding' => $roomTotal + $taxes,
            'room_bill_to' => 'to_guest', 'extras_bill_to' => 'to_guest',
        ]));

        $booking->rooms()->create(['room_category_id' => $room->room_category_id, 'room_id' => $room->id, 'meal_plan' => 'EP', 'adults' => 2, 'rate_per_night' => $rate])
            ->update(['status' => $status]);

        if ($guest !== null) {
            $booking->guests()->attach($guest->id);
        }

        return $booking->fresh();
    }

    /**
     * A 1×1 PNG on disk, to upload as an ID document.
     */
    function auditPng(): string
    {
        $path = sys_get_temp_dir().'/audit-id-'.Str::random(8).'.png';
        file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));

        return $path;
    }

    /**
     * The booking engine for one room of two adults over the nights.
     */
    function auditEngineUrl(Property $property, string $checkIn, string $checkOut): string
    {
        return route('booking-engine.web', [$property->ulid, 'check_in' => $checkIn, 'check_out' => $checkOut]);
    }
}
