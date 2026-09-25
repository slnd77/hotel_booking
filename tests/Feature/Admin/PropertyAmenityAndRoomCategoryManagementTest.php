<?php

use App\Models\Amenity;
use App\Models\AmenityCategory;
use App\Models\Currency;
use App\Models\Language;
use App\Models\MetaField;
use App\Models\Property;
use App\Models\PropertyGroups;
use App\Models\RoomCategoryBedType;
use App\Models\RoomCategoryImage;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Role::firstOrCreate(['name' => 'admin']);
    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');

    Currency::create(['code' => 'USD', 'name' => 'USD']);
    Language::create(['code' => 'en', 'name' => 'English']);

    $this->property = Property::factory()->create(['property_group_id' => PropertyGroups::factory()->create()->id]);
});

test('property amenities can be updated', function () {
    $category = AmenityCategory::query()->create(['name' => 'Property Amenities', 'status' => true]);
    $amenity = Amenity::query()->create(['key' => 'free_parking', 'name' => 'FREE_PARKING', 'amenity_category_id' => $category->id, 'type' => 'property']);

    $response = $this->actingAs($this->admin)->put(
        "/admin/properties/{$this->property->id}/amenities",
        ['amenity_ids' => [$amenity->id]],
    );

    $response->assertRedirect("/admin/properties/{$this->property->id}/edit?tab=property-amenities");
    $this->assertDatabaseHas('property_amenities', [
        'property_id' => $this->property->id,
        'amenity_id' => $amenity->id,
    ]);
});

test('a room category can be created for a property', function () {
    $response = $this->actingAs($this->admin)->post(
        "/admin/properties/{$this->property->id}/room-categories",
        ['name' => 'Deluxe Room'],
    );

    $response->assertRedirect("/admin/properties/{$this->property->id}/edit?tab=rooms-setup");
    $this->assertDatabaseHas('room_categories', [
        'property_id' => $this->property->id,
        'name' => 'Deluxe Room',
    ]);
});

test('a room category can be updated with full details', function () {
    $roomCategory = $this->property->roomCategories()->create(['name' => 'Deluxe Room']);

    $response = $this->actingAs($this->admin)->put(
        "/admin/properties/{$this->property->id}/room-categories/{$roomCategory->id}",
        [
            'name' => 'Deluxe Room Updated',
            'description' => 'A spacious deluxe room',
            'abbreviation' => 'DLX',
            'integration_code' => 'DLX-01',
            'inclusion_details' => 'Free breakfast included',
            'base_occupancy' => 1,
            'ideal_occupancy' => 2,
            'max_adults' => 3,
            'max_children' => 1,
            'default_number_of_children' => 0,
            'total_number_of_guests' => 4,
            'sync_category_on_ota' => true,
            'exclude_from_revenue' => false,
            'exclude_from_occupancy' => false,
            'exclude_from_upgrade' => false,
            'meal_plans' => ['EP', 'CP'],
        ],
    );

    $response->assertRedirect("/admin/properties/{$this->property->id}/edit?tab=rooms-setup");
    $this->assertDatabaseHas('room_categories', [
        'id' => $roomCategory->id,
        'name' => 'Deluxe Room Updated',
        'abbreviation' => 'DLX',
        'max_adults' => 3,
    ]);
    expect($roomCategory->fresh()->meal_plans)->toBe(['EP', 'CP']);
});

test('room category amenities can be updated', function () {
    $roomCategory = $this->property->roomCategories()->create(['name' => 'Deluxe Room']);
    $category = AmenityCategory::query()->create(['name' => 'Room amenity type', 'status' => true]);
    $amenity = Amenity::query()->create(['key' => 'hairdryer', 'name' => 'Hairdryer', 'amenity_category_id' => $category->id, 'type' => 'room_category']);

    $response = $this->actingAs($this->admin)->put(
        "/admin/properties/{$this->property->id}/room-categories/{$roomCategory->id}/amenities",
        ['amenity_ids' => [$amenity->id]],
    );

    $response->assertRedirect("/admin/properties/{$this->property->id}/edit?tab=rooms-setup");
    $this->assertDatabaseHas('room_category_amenities', [
        'property_id' => $this->property->id,
        'room_category_id' => $roomCategory->id,
        'amenity_id' => $amenity->id,
    ]);
});

test('a room can be created under a room category', function () {
    $roomCategory = $this->property->roomCategories()->create(['name' => 'Deluxe Room']);

    $response = $this->actingAs($this->admin)->post(
        "/admin/properties/{$this->property->id}/room-categories/{$roomCategory->id}/rooms",
        ['name' => 'Room 101'],
    );

    $response->assertRedirect("/admin/properties/{$this->property->id}/edit?tab=rooms-setup");
    $this->assertDatabaseHas('rooms', [
        'room_category_id' => $roomCategory->id,
        'name' => 'Room 101',
    ]);
});

test('a room name can be updated', function () {
    $roomCategory = $this->property->roomCategories()->create(['name' => 'Deluxe Room']);
    $room = $roomCategory->rooms()->create(['name' => 'Room 101', 'display_order' => 1]);

    $response = $this->actingAs($this->admin)->put(
        "/admin/properties/{$this->property->id}/room-categories/{$roomCategory->id}/rooms/{$room->id}",
        ['name' => 'Room 102'],
    );

    $response->assertRedirect("/admin/properties/{$this->property->id}/edit?tab=rooms-setup");
    $this->assertDatabaseHas('rooms', ['id' => $room->id, 'name' => 'Room 102']);
});

test('rooms under a category can be reordered', function () {
    $roomCategory = $this->property->roomCategories()->create(['name' => 'Deluxe Room']);
    $first = $roomCategory->rooms()->create(['name' => 'Room 101', 'display_order' => 1]);
    $second = $roomCategory->rooms()->create(['name' => 'Room 102', 'display_order' => 2]);

    $response = $this->actingAs($this->admin)->put(
        "/admin/properties/{$this->property->id}/room-categories/{$roomCategory->id}/rooms/reorder",
        ['room_ids' => [$second->id, $first->id]],
    );

    $response->assertRedirect("/admin/properties/{$this->property->id}/edit?tab=rooms-setup");
    $this->assertDatabaseHas('rooms', ['id' => $second->id, 'display_order' => 1]);
    $this->assertDatabaseHas('rooms', ['id' => $first->id, 'display_order' => 2]);
});

test('an image can be uploaded for a room category', function () {
    Storage::fake('public');
    $roomCategory = $this->property->roomCategories()->create(['name' => 'Deluxe Room']);

    $response = $this->actingAs($this->admin)->post(
        "/admin/properties/{$this->property->id}/room-categories/{$roomCategory->id}/images",
        ['image' => UploadedFile::fake()->image('room.jpg', 300, 250)->size(500)],
    );

    $response->assertRedirect("/admin/properties/{$this->property->id}/edit?tab=rooms-setup");
    $this->assertDatabaseHas('room_category_images', [
        'room_category_id' => $roomCategory->id,
        'selected' => false,
    ]);

    $image = RoomCategoryImage::query()->where('room_category_id', $roomCategory->id)->first();
    Storage::disk('public')->assertExists($image->path);
});

test('a room category image can be toggled as selected', function () {
    Storage::fake('public');
    $roomCategory = $this->property->roomCategories()->create(['name' => 'Deluxe Room']);
    $image = $roomCategory->images()->create(['path' => 'room-category-images/test.jpg', 'selected' => false]);

    $response = $this->actingAs($this->admin)->put(
        "/admin/properties/{$this->property->id}/room-categories/{$roomCategory->id}/images/{$image->id}",
        ['selected' => true],
    );

    $response->assertRedirect("/admin/properties/{$this->property->id}/edit?tab=rooms-setup");
    $this->assertDatabaseHas('room_category_images', ['id' => $image->id, 'selected' => true]);
});

test('bed types can be updated for a room category', function () {
    $roomCategory = $this->property->roomCategories()->create(['name' => 'Deluxe Room']);

    $response = $this->actingAs($this->admin)->put(
        "/admin/properties/{$this->property->id}/room-categories/{$roomCategory->id}/bed-types",
        ['items' => [['name' => 'Double Bed', 'enabled' => true, 'quantity' => 2]]],
    );

    $response->assertRedirect("/admin/properties/{$this->property->id}/edit?tab=rooms-setup");
    $this->assertDatabaseHas('room_category_bed_types', [
        'room_category_id' => $roomCategory->id,
        'name' => 'Double Bed',
        'type' => 'bed',
        'quantity' => 2,
    ]);
});

test('extra bed types can be updated for a room category', function () {
    $roomCategory = $this->property->roomCategories()->create(['name' => 'Deluxe Room']);

    $response = $this->actingAs($this->admin)->put(
        "/admin/properties/{$this->property->id}/room-categories/{$roomCategory->id}/extra-bed-types",
        ['items' => [['name' => 'Single Bed', 'enabled' => true]]],
    );

    $response->assertRedirect("/admin/properties/{$this->property->id}/edit?tab=rooms-setup");
    $this->assertDatabaseHas('room_category_bed_types', [
        'room_category_id' => $roomCategory->id,
        'name' => 'Single Bed',
        'type' => 'extra_bed',
    ]);
});

test('disabled bed types are removed on update', function () {
    $roomCategory = $this->property->roomCategories()->create(['name' => 'Deluxe Room']);
    RoomCategoryBedType::query()->create(['room_category_id' => $roomCategory->id, 'name' => 'Queen Bed', 'type' => 'bed', 'quantity' => 1]);

    $response = $this->actingAs($this->admin)->put(
        "/admin/properties/{$this->property->id}/room-categories/{$roomCategory->id}/bed-types",
        ['items' => [['name' => 'Queen Bed', 'enabled' => false]]],
    );

    $response->assertRedirect("/admin/properties/{$this->property->id}/edit?tab=rooms-setup");
    $this->assertDatabaseMissing('room_category_bed_types', [
        'room_category_id' => $roomCategory->id,
        'name' => 'Queen Bed',
    ]);
});

test('room categories can be dragged into a new display order and upsell order, both logged', function () {
    $this->property->roomCategories()->delete();
    $suite = $this->property->roomCategories()->create(['name' => 'Suite', 'display_order' => 1]);
    $deluxe = $this->property->roomCategories()->create(['name' => 'Deluxe', 'display_order' => 2]);
    $family = $this->property->roomCategories()->create(['name' => 'Family', 'display_order' => 3]);

    $this->actingAs($this->admin)
        ->put("/admin/properties/{$this->property->id}/room-categories/reorder", ['category_ids' => [$family->id, $suite->id, $deluxe->id]])
        ->assertRedirect("/admin/properties/{$this->property->id}/edit?tab=rooms-setup");

    $this->actingAs($this->admin)
        ->put("/admin/properties/{$this->property->id}/room-categories/upsell-order", ['category_ids' => [$suite->id, $family->id, $deluxe->id]])
        ->assertRedirect("/admin/properties/{$this->property->id}/edit?tab=rooms-setup");

    expect($this->property->roomCategories()->pluck('name')->all())->toBe(['Family', 'Suite', 'Deluxe'])
        ->and($this->property->roomCategories()->orderBy('upsell_order')->reorder('upsell_order')->pluck('name')->all())->toBe(['Suite', 'Family', 'Deluxe']);

    $this->actingAs($this->admin)
        ->get("/admin/properties/{$this->property->id}/edit")
        ->assertInertia(fn ($page) => $page
            ->where('roomCategoryLogs.0.action', 'Room category upsell order (highest price first) changed to: Suite, Family, Deluxe.')
            ->where('roomCategoryLogs.1.action', 'Room category display order changed to: Family, Suite, Deluxe.'));
});

test('a room category order must list every category of the property once', function () {
    $this->property->roomCategories()->delete();
    $suite = $this->property->roomCategories()->create(['name' => 'Suite', 'display_order' => 1]);
    $this->property->roomCategories()->create(['name' => 'Deluxe', 'display_order' => 2]);
    $otherCategory = Property::factory()->create()->roomCategories()->first();

    $this->actingAs($this->admin)
        ->put("/admin/properties/{$this->property->id}/room-categories/reorder", ['category_ids' => [$suite->id]])
        ->assertSessionHasErrors('category_ids');

    $this->actingAs($this->admin)
        ->put("/admin/properties/{$this->property->id}/room-categories/upsell-order", ['category_ids' => [$suite->id, $otherCategory->id]])
        ->assertSessionHasErrors('category_ids.1');
});

test('the upsell ladder defaults to the highest rate first, then the last displayed category first', function () {
    $this->property->roomCategories()->delete();
    $suite = $this->property->roomCategories()->create(['name' => 'Suite', 'display_order' => 1]);
    $deluxe = $this->property->roomCategories()->create(['name' => 'Deluxe', 'display_order' => 2]);
    $family = $this->property->roomCategories()->create(['name' => 'Family', 'display_order' => 3, 'max_adults' => 2]);

    // Only Suite has a direct rate today.
    $ratePlans = MetaField::query()->firstOrCreate(['key' => 'rate_settings_rate_plan'], ['key_label' => 'Rate plan', 'status' => true]);
    $days = array_fill_keys(['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'], 9000);
    $this->property->metaFieldValues()->updateOrCreate(['meta_fields_key_id' => $ratePlans->id], ['enabled' => true, 'data' => json_encode([
        'plans' => [['id' => 'bar', 'mapping_status' => 'default', 'active' => true, 'rates' => ['direct' => ["{$suite->id}:EP:Single" => $days]]]],
    ])]);

    $this->actingAs($this->admin)
        ->get("/admin/properties/{$this->property->id}/edit")
        ->assertInertia(fn ($page) => $page->where('upsellCategoryIds', [$suite->id, $family->id, $deluxe->id]));

    // A dragged order wins.
    $this->actingAs($this->admin)->put("/admin/properties/{$this->property->id}/room-categories/upsell-order", ['category_ids' => [$deluxe->id, $suite->id, $family->id]]);

    $this->actingAs($this->admin)
        ->get("/admin/properties/{$this->property->id}/edit")
        ->assertInertia(fn ($page) => $page->where('upsellCategoryIds', [$deluxe->id, $suite->id, $family->id]));
});
