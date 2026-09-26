<?php

use App\Models\Currency;
use App\Models\Guest;
use App\Models\Language;
use App\Models\Property;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Role::firstOrCreate(['name' => 'admin']);
    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');

    Currency::create(['code' => 'USD', 'name' => 'USD']);
    Language::create(['code' => 'en', 'name' => 'English']);
});

test('guests index filters by search term within the selected property', function () {
    $property = Property::factory()->create();
    $otherProperty = Property::factory()->create();

    $match = Guest::factory()->create(['property_id' => $property->id, 'first_name' => 'Sushant']);
    Guest::factory()->create(['property_id' => $property->id, 'first_name' => 'Rahul']);
    Guest::factory()->create(['property_id' => $otherProperty->id, 'first_name' => 'Sushant']);

    $response = $this->actingAs($this->admin)
        ->get("/admin/databases/guests?property={$property->id}&q=Sushant");

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('admin/databases/Guests')
        ->has('guests.data', 1)
        ->where('guests.data.0.id', $match->id));
});

test('downloading the guest list streams a csv and logs the activity', function () {
    $property = Property::factory()->create();
    Guest::factory()->create(['property_id' => $property->id, 'first_name' => 'Sushant']);

    $response = $this->actingAs($this->admin)->get("/admin/databases/guests/download?property={$property->id}");

    $response->assertOk();
    $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    expect($response->getContent())->toContain('Sushant');

    $this->assertDatabaseHas('activity_logs', [
        'user_id' => $this->admin->id,
        'property_id' => $property->id,
        'guest_id' => null,
        'action' => 'guest_list_downloaded',
    ]);
});

test('guest upload template can be downloaded', function () {
    $response = $this->actingAs($this->admin)->get('/admin/databases/guests/upload-template');

    $response->assertOk();
    $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    expect($response->getContent())->toContain('First Name')->toContain('Mobile');
});

test('uploading a guest csv imports rows into the selected property and logs the activity', function () {
    $property = Property::factory()->create();

    $csv = "Title,First Name,Last Name,Phone Country Code,Mobile,E-mail,Gender,Date of Birth,Nationality,Purpose of Visit,Preferences,Anniversary,Frequent Flyer,Vehicle Number,Address,City,State,Country,Postal Code\n"
        ."Mr,Imported,Guest,+91,9998887777,imported@example.com,Male,1990-01-01,Indian,Leisure,Quiet room,,,,,,,,\n"
        .",,,,,,,,,,,,,,,,,,\n";
    $file = UploadedFile::fake()->createWithContent('guests.csv', $csv);

    $response = $this->actingAs($this->admin)
        ->post("/admin/databases/guests/upload?property={$property->id}", ['file' => $file]);

    $response->assertRedirect();

    $this->assertDatabaseHas('guests', [
        'property_id' => $property->id,
        'first_name' => 'Imported',
        'last_name' => 'Guest',
        'phone' => '9998887777',
    ]);
    $this->assertDatabaseCount('guests', 1);

    $this->assertDatabaseHas('activity_logs', [
        'user_id' => $this->admin->id,
        'property_id' => $property->id,
        'guest_id' => null,
        'action' => 'guest_list_uploaded',
    ]);
});
