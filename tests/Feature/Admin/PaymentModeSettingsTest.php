<?php

use App\Models\Booking;
use App\Models\Currency;
use App\Models\Language;
use App\Models\MetaField;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\User;
use App\Services\PaymentModeSettings;
use App\Services\PropertySettings;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Role::firstOrCreate(['name' => 'admin']);
    Currency::firstOrCreate(['code' => 'USD'], ['name' => 'USD']);
    Language::firstOrCreate(['code' => 'en'], ['name' => 'English']);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');
    $this->property = Property::factory()->create();

    $this->field = MetaField::query()->firstOrCreate(['key' => PaymentModeSettings::KEY], ['key_label' => 'Payment modes', 'status' => true]);
    app(PropertySettings::class)->forgetMetaFields();
});

/**
 * Saves Payment Settings the way the Booking Settings page does.
 *
 * @param  array<string, array{enabled: bool, payment: bool, refund: bool}>  $changes
 */
function savePaymentModes(Property $property, MetaField $field, array $changes): void
{
    $modes = collect(app(PaymentModeSettings::class)->all($property))
        ->map(fn (array $row): array => [...$row, ...($changes[$row['mode']] ?? [])])
        ->values()
        ->all();

    test()->actingAs(test()->admin)
        ->put(route('admin.bookingSettings.update', $property), ['meta_fields' => [$field->id => ['enabled' => true, 'data' => json_encode(['modes' => $modes])]]])
        ->assertSessionHasNoErrors();
}

test('every mode is on for payments and refunds until set otherwise; system modes are not listed', function () {
    $this->actingAs($this->admin)
        ->get(route('admin.bookingSettings.edit', $this->property))
        ->assertInertia(fn ($page) => $page
            ->where('paymentModes.0', ['mode' => 'Cash', 'enabled' => true, 'payment' => true, 'refund' => true])
            ->where('paymentModes', fn ($rows): bool => ! collect($rows)->pluck('mode')->intersect(['Online', 'OTA', 'Razorpay', 'Cashfree', 'E-Collect'])->count()));
});

test('payment and refund pickers follow Payment Settings', function () {
    savePaymentModes($this->property, $this->field, [
        // Off altogether: neither list, whatever its other ticks.
        'Cheque/DD' => ['enabled' => false, 'payment' => true, 'refund' => true],
        // Taken, never paid back.
        'Credit Card' => ['enabled' => true, 'payment' => true, 'refund' => false],
        // Only paid back.
        'Bank Transfer' => ['enabled' => true, 'payment' => false, 'refund' => true],
    ]);

    $settings = app(PaymentModeSettings::class);

    expect($settings->paymentModes($this->property))->toContain('Credit Card')->not->toContain('Bank Transfer')->not->toContain('Cheque/DD')
        ->and($settings->refundModes($this->property))->toContain('Bank Transfer')->not->toContain('Credit Card')->not->toContain('Cheque/DD');

    // The booking page's Add Payment and the change dialog's refund modes.
    $category = RoomCategory::factory()->create(['property_id' => $this->property->id]);
    Room::factory()->create(['room_category_id' => $category->id]);
    $this->actingAs($this->admin)->post(route('admin.bookings.store', $this->property), [
        'booking_ref_id' => 'PM-1', 'source' => 'direct', 'type' => 'walk_in', 'status' => 'confirmed', 'discount' => 0,
        'check_in' => now()->addDays(2)->toDateString(), 'check_out' => now()->addDays(3)->toDateString(),
        'room_bill_to' => 'to_guest', 'extras_bill_to' => 'to_guest',
        'rooms' => [['room_category_id' => $category->id, 'room_id' => null, 'meal_plan' => 'EP', 'adults' => 2, 'children' => 0, 'infants' => 0, 'rate_per_night' => 1000]],
    ])->assertSessionHasNoErrors();
    $booking = Booking::query()->where('booking_ref_id', 'PM-1')->sole();

    $this->actingAs($this->admin)
        ->get(route('admin.bookings.show', [$this->property, $booking]))
        ->assertInertia(fn ($page) => $page->where('paymentModes', fn ($modes): bool => collect($modes)->contains('Credit Card') && ! collect($modes)->contains('Bank Transfer')));

    $this->actingAs($this->admin)
        ->get(route('admin.bookings.edit', [$this->property, $booking]))
        ->assertInertia(fn ($page) => $page
            ->where('billing.paymentModes', fn ($modes): bool => ! collect($modes)->contains('Cheque/DD'))
            ->where('changeSettlement.refund_modes', fn ($modes): bool => collect($modes)->pluck('value')->contains('Bank Transfer') && ! collect($modes)->pluck('value')->contains('Credit Card')));

    // A refund in a mode not on for refunds is refused.
    $this->actingAs($this->admin)
        ->put(route('admin.bookings.update', [$this->property, $booking]), [
            'booking_ref_id' => 'PM-1', 'source' => 'direct', 'type' => 'walk_in', 'status' => 'confirmed', 'discount' => 0,
            'check_in' => now()->addDays(2)->toDateString(), 'check_out' => now()->addDays(3)->toDateString(),
            'room_bill_to' => 'to_guest', 'extras_bill_to' => 'to_guest',
            'rooms' => [['room_category_id' => $category->id, 'room_id' => null, 'meal_plan' => 'EP', 'adults' => 2, 'children' => 0, 'infants' => 0, 'rate_per_night' => 1000]],
            'credit_action' => 'refund', 'refund_mode' => 'Credit Card',
        ])
        ->assertSessionHasErrors('refund_mode');
});

test("a front-desk booking's own receipt takes the first mode allowed for payments, not E-Collect", function () {
    $category = RoomCategory::factory()->create(['property_id' => $this->property->id]);
    Room::factory()->count(2)->create(['room_category_id' => $category->id]);
    $book = fn (string $ref) => $this->actingAs($this->admin)->post(route('admin.bookings.store', $this->property), [
        'booking_ref_id' => $ref, 'source' => 'direct', 'type' => 'walk_in', 'status' => 'confirmed', 'discount' => 0,
        'check_in' => now()->addDays(2)->toDateString(), 'check_out' => now()->addDays(3)->toDateString(),
        'room_bill_to' => 'to_guest', 'extras_bill_to' => 'to_guest',
        'rooms' => [['room_category_id' => $category->id, 'room_id' => null, 'meal_plan' => 'EP', 'adults' => 2, 'children' => 0, 'infants' => 0, 'rate_per_night' => 1000]],
    ])->assertSessionHasNoErrors();
    $receiptMode = fn (string $ref): string => Payment::query()
        ->where('booking_id', Booking::query()->where('booking_ref_id', $ref)->value('id'))
        ->where('type', 'receipt')->sole()->payment_mode->value;

    $book('RM-1');
    expect($receiptMode('RM-1'))->toBe('Cash');

    savePaymentModes($this->property, $this->field, ['Cash' => ['enabled' => false, 'payment' => true, 'refund' => true]]);
    $book('RM-2');
    expect($receiptMode('RM-2'))->toBe('Credit Card');
});
