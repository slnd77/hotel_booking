<?php

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\ChartNote;
use App\Models\Currency;
use App\Models\Language;
use App\Models\Property;
use App\Models\User;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Role::firstOrCreate(['name' => 'admin']);
    Currency::firstOrCreate(['code' => 'USD'], ['name' => 'USD']);
    Language::firstOrCreate(['code' => 'en'], ['name' => 'English']);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');
    $this->property = Property::factory()->create();
});

function chartBooking(Property $property, string $checkIn, BookingStatus $status): Booking
{
    return Booking::create([
        'property_id' => $property->id,
        'booking_ref_id' => fake()->unique()->bothify('REF####'),
        'source' => 'direct',
        'type' => 'walk_in',
        'check_in' => $checkIn,
        'check_out' => now()->parse($checkIn)->addDay()->toDateString(),
        'status' => $status,
        'room_total' => 1000,
        'taxes' => 0,
        'total' => 1000,
        'outstanding' => 0,
        'room_bill_to' => 'to_guest',
        'extras_bill_to' => 'to_guest',
    ]);
}

test('chart days count the open enquiries checking in that day', function () {
    $today = now()->toDateString();
    $tomorrow = now()->addDay()->toDateString();
    chartBooking($this->property, $today, BookingStatus::Enquiry);
    chartBooking($this->property, $today, BookingStatus::Enquiry);
    chartBooking($this->property, $today, BookingStatus::Confirmed);
    chartBooking($this->property, $tomorrow, BookingStatus::ExpiredQuote);
    chartBooking(Property::factory()->create(), $today, BookingStatus::Enquiry);

    $dates = $this->actingAs($this->admin)
        ->getJson(route('admin.smartBookingChartData', ['property_id' => $this->property->id]))
        ->assertOk()
        ->json('dates');

    expect($dates[0]['enquiries_count'])->toBe(2)
        ->and($dates[1]['enquiries_count'])->toBe(0);
});

test('chart days carry their notes with who wrote them', function () {
    $writer = User::factory()->create(['name' => 'Asha']);
    ChartNote::factory()->create(['property_id' => $this->property->id, 'user_id' => $writer->id, 'date' => now()->toDateString(), 'body' => 'VIP group arriving']);
    ChartNote::factory()->create(['date' => now()->toDateString()]);

    $dates = $this->actingAs($this->admin)
        ->getJson(route('admin.smartBookingChartData', ['property_id' => $this->property->id]))
        ->json('dates');

    expect($dates[0]['notes'])->toHaveCount(1)
        ->and($dates[0]['notes'][0])->toMatchArray(['user_id' => $writer->id, 'user_name' => 'Asha', 'body' => 'VIP group arriving'])
        ->and($dates[1]['notes'])->toBe([]);
});

test('a user adds a note to a chart day and gets the day\'s notes back', function () {
    ChartNote::factory()->create(['property_id' => $this->property->id, 'date' => '2026-10-12', 'body' => 'Earlier note']);

    $this->actingAs($this->admin)
        ->postJson(route('admin.chartNotes.store', $this->property), ['date' => '2026-10-12', 'body' => 'Pool closed'])
        ->assertOk()
        ->assertJsonCount(2, 'notes')
        ->assertJsonPath('notes.1.body', 'Pool closed')
        ->assertJsonPath('notes.1.user_id', $this->admin->id);

    $this->actingAs($this->admin)
        ->postJson(route('admin.chartNotes.store', $this->property), ['date' => '2026-10-12', 'body' => ''])
        ->assertJsonValidationErrors('body');
});

test('a user edits only their own chart notes', function () {
    $own = ChartNote::factory()->create(['property_id' => $this->property->id, 'user_id' => $this->admin->id]);
    $other = ChartNote::factory()->create(['property_id' => $this->property->id]);

    $this->actingAs($this->admin)
        ->putJson(route('admin.chartNotes.update', [$this->property, $own]), ['body' => 'Updated'])
        ->assertOk()
        ->assertJsonPath('notes.0.body', 'Updated');

    $this->actingAs($this->admin)
        ->putJson(route('admin.chartNotes.update', [$this->property, $other]), ['body' => 'Hijacked'])
        ->assertForbidden();

    expect($other->fresh()->body)->not->toBe('Hijacked');
});

test('front desk roles can use chart notes', function () {
    $clerk = User::factory()->create();
    $clerk->assignRole(Role::findOrCreate('Front Office', 'web'));

    $this->actingAs($clerk)
        ->postJson(route('admin.chartNotes.store', $this->property), ['date' => now()->toDateString(), 'body' => 'Late arrival'])
        ->assertOk();
});
