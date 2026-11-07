<?php

use App\Enums\BookingStatus;
use App\Models\ActivityLog;
use App\Models\Booking;
use App\Models\Currency;
use App\Models\Guest;
use App\Models\GuestConsent;
use App\Models\Language;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Role::firstOrCreate(['name' => 'admin']);
    Currency::firstOrCreate(['code' => 'USD'], ['name' => 'USD']);
    Language::firstOrCreate(['code' => 'en'], ['name' => 'English']);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');
    $this->property = Property::factory()->create();
});

function guestWithBooking(Property $property, array $attributes): array
{
    $guest = Guest::factory()->create(['property_id' => $property->id, ...$attributes]);
    $booking = DB::transaction(fn (): Booking => $property->bookings()->create([
        'booking_ref_id' => 'MERGE-'.fake()->unique()->numerify('####'), 'source' => 'direct', 'status' => BookingStatus::CheckedOut, 'guest_id' => $guest->id,
        'check_in' => now()->subDays(3)->toDateString(), 'check_out' => now()->subDay()->toDateString(),
        'room_total' => 2000, 'discount' => 0, 'taxes' => 0, 'total' => 2000, 'outstanding' => 2000,
        'room_bill_to' => 'to_guest', 'extras_bill_to' => 'to_guest',
    ]));
    $booking->guests()->attach($guest->id);

    return [$guest, $booking];
}

test('possible duplicates share a phone, an e-mail or the full name', function () {
    $guest = Guest::factory()->create(['property_id' => $this->property->id, 'first_name' => 'Asha', 'last_name' => 'Rao', 'phone' => '9000000001', 'emails' => ['asha@example.com']]);
    $samePhone = Guest::factory()->create(['property_id' => $this->property->id, 'first_name' => 'A', 'last_name' => 'R', 'phone' => '9000000001', 'emails' => []]);
    $sameEmail = Guest::factory()->create(['property_id' => $this->property->id, 'first_name' => 'B', 'last_name' => 'S', 'phone' => null, 'emails' => ['asha@example.com']]);
    $sameName = Guest::factory()->create(['property_id' => $this->property->id, 'first_name' => 'Asha', 'last_name' => 'Rao', 'phone' => null, 'emails' => []]);
    Guest::factory()->create(['property_id' => $this->property->id, 'first_name' => 'Other', 'last_name' => 'Person', 'phone' => '9111111111', 'emails' => ['other@example.com']]);
    Guest::factory()->create(['phone' => '9000000001']);

    $ids = collect($this->actingAs($this->admin)->getJson(route('admin.databases.guests.merge.candidates', $guest))->assertOk()->json('guests'))->pluck('id')->sort()->values()->all();

    expect($ids)->toBe(collect([$samePhone->id, $sameEmail->id, $sameName->id])->sort()->values()->all());

    $this->getJson(route('admin.databases.guests.merge.candidates', [$guest, 'q' => 'Other']))->assertJsonPath('guests.0.name', 'Other Person');
});

test('merging moves the duplicate\'s bookings, consents and history to the kept guest and deletes it', function () {
    [$keep, $keptBooking] = guestWithBooking($this->property, ['first_name' => 'Asha', 'last_name' => 'Rao', 'phone' => '9000000001', 'emails' => ['asha@example.com'], 'nationality' => null, 'address' => ['address' => '1 MG Road', 'postal_code' => null], 'subscribe_newsletter' => false]);
    [$duplicate, $duplicateBooking] = guestWithBooking($this->property, ['first_name' => 'Asha', 'last_name' => 'R', 'phone' => null, 'emails' => ['Asha.Rao@work.com', 'asha@example.com'], 'nationality' => 'Indian', 'address' => ['address' => 'Old address', 'postal_code' => '560001']]);
    // Both are on one booking: it keeps a single guest row.
    $keptBooking->guests()->attach($duplicate->id);
    GuestConsent::factory()->create(['property_id' => $this->property->id, 'guest_id' => $duplicate->id, 'purpose' => GuestConsent::MARKETING, 'granted' => true]);
    ActivityLog::create(['user_id' => $this->admin->id, 'property_id' => $this->property->id, 'guest_id' => $duplicate->id, 'action' => 'Earlier stay', 'date' => now()]);

    $this->actingAs($this->admin)->post(route('admin.databases.guests.merge.store', $keep), ['duplicate_id' => $duplicate->id])->assertSessionHas('success');

    $keep->refresh();
    expect(Guest::query()->find($duplicate->id))->toBeNull()
        ->and($duplicateBooking->fresh()->guest_id)->toBe($keep->id)
        ->and($duplicateBooking->guests()->pluck('guests.id')->all())->toBe([$keep->id])
        ->and($keptBooking->guests()->pluck('guests.id')->all())->toBe([$keep->id])
        ->and(GuestConsent::query()->sole()->guest_id)->toBe($keep->id)
        ->and(ActivityLog::query()->where('action', 'Earlier stay')->sole()->guest_id)->toBe($keep->id)
        // The kept guest's details win; its blanks are filled from the duplicate.
        ->and($keep->last_name)->toBe('Rao')
        ->and($keep->nationality)->toBe('Indian')
        ->and($keep->address)->toBe(['address' => '1 MG Road', 'postal_code' => '560001'])
        ->and($keep->emails)->toBe(['asha@example.com', 'asha.rao@work.com'])
        // The duplicate's marketing consent is on record for the kept guest now.
        ->and($keep->subscribe_newsletter)->toBeTrue()
        ->and(ActivityLog::query()->where('guest_id', $keep->id)->where('action', 'like', "Guest #{$duplicate->id} (Asha R) merged into guest #{$keep->id}.")->exists())->toBeTrue();
});

test('guests of another property, the same guest or an unknown one are not merged', function () {
    $keep = Guest::factory()->create(['property_id' => $this->property->id]);
    $elsewhere = Guest::factory()->create();

    $this->actingAs($this->admin);
    $this->post(route('admin.databases.guests.merge.store', $keep), ['duplicate_id' => $elsewhere->id])->assertSessionHasErrors('duplicate_id');
    $this->post(route('admin.databases.guests.merge.store', $keep), ['duplicate_id' => $keep->id])->assertSessionHasErrors('duplicate_id');
    $this->post(route('admin.databases.guests.merge.store', $keep), ['duplicate_id' => 999999])->assertSessionHasErrors('duplicate_id');

    expect(Guest::query()->count())->toBe(2);
});

test('merging guests needs the permission', function () {
    $keep = Guest::factory()->create(['property_id' => $this->property->id]);
    $duplicate = Guest::factory()->create(['property_id' => $this->property->id]);
    $clerk = User::factory()->create();
    $clerk->assignRole(Role::findOrCreate('Clerk', 'web'));

    $this->actingAs($clerk)->getJson(route('admin.databases.guests.merge.candidates', $keep))->assertForbidden();
    $this->actingAs($clerk)->post(route('admin.databases.guests.merge.store', $keep), ['duplicate_id' => $duplicate->id])->assertForbidden();
});
