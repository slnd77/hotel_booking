<?php

use App\Enums\TransactionType;
use App\Mail\WebCheckinLinkMail;
use App\Models\Addon;
use App\Models\Booking;
use App\Models\BookingAddon;
use App\Models\ChargeCode;
use App\Models\Currency;
use App\Models\Guest;
use App\Models\GuestConsent;
use App\Models\Language;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WebCheckin;
use App\Services\WebCheckins;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Role::firstOrCreate(['name' => 'admin']);
    Currency::firstOrCreate(['code' => 'USD'], ['name' => 'USD']);
    Language::firstOrCreate(['code' => 'en'], ['name' => 'English']);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');

    $this->property = Property::factory()->create();
    $this->category = RoomCategory::factory()->create(['property_id' => $this->property->id]);
    Room::factory()->count(2)->create(['room_category_id' => $this->category->id]);

    // 1 000 a night; per booking 500 + 10% tax; breakfast 200 per guest per night.
    $this->pickup = Addon::factory()->create(['property_id' => $this->property->id, 'name' => 'Airport pickup', 'pricing' => 'per_booking', 'price' => 500, 'tax_rate' => 10]);
    $this->breakfast = Addon::factory()->create(['property_id' => $this->property->id, 'name' => 'Breakfast', 'pricing' => 'per_guest_per_night', 'price' => 200, 'tax_rate' => 0, 'show_on_booking_engine' => false]);
});

/**
 * Two nights, one room for two adults at 1 000.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function addonBookingPayload(RoomCategory $category, array $overrides = []): array
{
    return [
        'booking_ref_id' => 'ADDON-1',
        'source' => 'direct',
        'type' => 'walk_in',
        'check_in' => now()->addDays(2)->toDateString(),
        'check_out' => now()->addDays(4)->toDateString(),
        'status' => 'confirmed',
        'discount' => 0,
        'room_bill_to' => 'to_guest',
        'extras_bill_to' => 'to_guest',
        'rooms' => [['room_category_id' => $category->id, 'room_id' => null, 'meal_plan' => 'EP', 'adults' => 2, 'children' => 0, 'infants' => 0, 'rate_per_night' => 1000]],
        ...$overrides,
    ];
}

function addonEntries(Booking $booking): array
{
    return Transaction::query()
        ->whereIn('booking_addon_id', BookingAddon::withTrashed()->where('booking_id', $booking->id)->pluck('id'))
        ->get()
        ->groupBy(fn (Transaction $entry): string => $entry->chargeCode->code)
        ->map(fn ($entries): float => round($entries->sum(fn (Transaction $entry): float => $entry->transaction_type === TransactionType::Debit ? (float) $entry->amount : -(float) $entry->amount), 2))
        ->all();
}

test('add-ons on the booking form are priced for the stay, charged to the folio and reversed when removed', function () {
    $this->actingAs($this->admin)->post(route('admin.bookings.store', $this->property), addonBookingPayload($this->category, [
        'addons' => [['addon_id' => $this->pickup->id, 'quantity' => 1], ['addon_id' => $this->breakfast->id, 'quantity' => 1]],
    ]))->assertSessionHasNoErrors();

    $booking = Booking::query()->where('booking_ref_id', 'ADDON-1')->sole();
    $lines = $booking->addons()->get()->keyBy('name');

    // Pickup 500 + 50 tax; breakfast 200 × 2 guests × 2 nights.
    expect((float) $lines['Airport pickup']->total)->toBe(550.0)
        ->and($lines['Breakfast']->multiplier)->toBe(4)
        ->and((float) $lines['Breakfast']->total)->toBe(800.0)
        ->and((float) $booking->total)->toBe(2000.0)
        ->and((float) $booking->addons_total)->toBe(1350.0)
        ->and(addonEntries($booking))->toBe([ChargeCode::ADDON => 1300.0, ChargeCode::ADDON_TAX => 50.0])
        ->and((float) $booking->refresh()->outstanding)->toBe(3350.0);

    // Edited without the pickup, with three breakfasts.
    $this->actingAs($this->admin)->put(route('admin.bookings.update', [$this->property, $booking]), addonBookingPayload($this->category, [
        'addons' => [['addon_id' => $this->breakfast->id, 'quantity' => 3]],
    ]))->assertSessionHasNoErrors();

    $booking->refresh();
    expect($booking->addons()->count())->toBe(1)
        ->and(BookingAddon::onlyTrashed()->where('booking_id', $booking->id)->value('name'))->toBe('Airport pickup')
        ->and((float) $booking->addons_total)->toBe(2400.0)
        ->and(addonEntries($booking))->toBe([ChargeCode::ADDON => 2400.0, ChargeCode::ADDON_TAX => 0.0])
        ->and((float) $booking->outstanding)->toBe(4400.0);

    $this->actingAs($this->admin)->get(route('admin.bookings.print', [$this->property, $booking, 'bill-summary']).'?preview=1')
        ->assertOk()->assertSee('Add-on Services')->assertSee('Breakfast')->assertSee('Add-ons Total');
});

test('the booking page adds an add-on, and removing one needs the Delete Addon permission', function () {
    $this->actingAs($this->admin)->post(route('admin.bookings.store', $this->property), addonBookingPayload($this->category))->assertSessionHasNoErrors();
    $booking = Booking::query()->where('booking_ref_id', 'ADDON-1')->sole();

    $this->actingAs($this->admin)->post(route('admin.bookings.addons.store', [$this->property, $booking]), ['addon_id' => $this->pickup->id, 'quantity' => 2])->assertSessionHas('success');
    $line = $booking->addons()->sole();
    expect((float) $line->total)->toBe(1100.0);

    // Add New Payment lists the booking with its whole bill: rooms and add-ons.
    $this->actingAs($this->admin)->get(route('admin.bookings.show', [$this->property, $booking]))
        ->assertInertia(fn ($page) => $page->where('reservations.0.total', fn ($total): bool => (float) $total === round((float) $booking->refresh()->total + 1100, 2)));

    // Another property's add-on isn't offered.
    $foreign = Addon::factory()->create();
    $this->actingAs($this->admin)->post(route('admin.bookings.addons.store', [$this->property, $booking]), ['addon_id' => $foreign->id, 'quantity' => 1])->assertSessionHasErrors('addon_id');

    $clerk = User::factory()->create();
    $clerk->assignRole(Role::findOrCreate('Clerk', 'web'));
    $this->actingAs($clerk)->delete(route('admin.bookings.addons.destroy', [$this->property, $booking, $line]))->assertForbidden();

    $clerk->givePermissionTo(Permission::findOrCreate('reservation.delete_addon', 'web'));
    $this->actingAs($clerk)->delete(route('admin.bookings.addons.destroy', [$this->property, $booking, $line]))->assertSessionHas('success');

    expect($booking->refresh()->addons()->count())->toBe(0)
        ->and((float) $booking->addons_total)->toBe(0.0)
        ->and(addonEntries($booking))->toBe([ChargeCode::ADDON => 0.0, ChargeCode::ADDON_TAX => 0.0]);
});

test('the booking engine offers its add-ons and books them, priced on the server', function () {
    $this->get(route('booking-engine.web', $this->property->ulid))
        ->assertInertia(fn ($page) => $page->has('addons', 1)->where('addons.0.name', 'Airport pickup'));

    sellOnBookingEngine($this->property, $this->category, 1000);

    $response = bookThroughEngine($this, $this->property, [
        'check_in' => now()->addDays(5)->toDateString(),
        'check_out' => now()->addDays(7)->toDateString(),
        'rooms' => [['category_id' => $this->category->id, 'meal_plan' => 'EP', 'adults' => 2, 'children' => 0]],
        'addons' => [['addon_id' => $this->pickup->id, 'quantity' => 1]],
    ])->assertOk();

    $booking = Booking::query()->where('booking_ref_id', $response->json('booking.booking_ref_id'))->sole();

    expect($response->json('booking.total'))->toEqual(2550)
        ->and((float) $booking->total)->toBe(2000.0)
        ->and((float) $booking->addons_total)->toBe(550.0)
        ->and($booking->addons()->sole()->added_from)->toBe('booking_engine');
});

test('web check-in follows the property settings, takes the guest details, ID and signature, and shows on the booking', function () {
    Storage::fake('local');
    Mail::fake();
    $checkins = app(WebCheckins::class);

    $this->actingAs($this->admin)->post(route('admin.bookings.store', $this->property), addonBookingPayload($this->category, [
        'check_in' => now()->addDays(10)->toDateString(),
        'check_out' => now()->addDays(12)->toDateString(),
    ]))->assertSessionHasNoErrors();
    $booking = Booking::query()->where('booking_ref_id', 'ADDON-1')->sole();
    $guest = Guest::query()->create(['property_id' => $this->property->id, 'first_name' => 'Old', 'last_name' => 'Name', 'phone' => '9000000002', 'emails' => ['guest@example.com']]);
    $booking->guests()->attach($guest->id);

    // Off: no link.
    $this->actingAs($this->admin)->getJson(route('admin.bookings.webCheckin.link', [$this->property, $booking]))->assertStatus(422);

    $this->actingAs($this->admin)->put(route('admin.properties.webCheckin.update', $this->property), [
        'enabled' => true, 'opens_days_before' => 3, 'require_id_document' => true, 'require_signature' => true,
        'require_address' => false, 'ask_arrival_time' => true, 'include_in_confirmation_email' => true, 'terms' => 'No smoking.',
    ])->assertSessionHasNoErrors();

    $url = $this->actingAs($this->admin)->getJson(route('admin.bookings.webCheckin.link', [$this->property, $booking]))->assertOk()->json('url');
    $checkin = WebCheckin::query()->where('booking_id', $booking->id)->sole();
    expect($url)->toBe(route('web-checkin.show', $checkin->token));

    $this->actingAs($this->admin)->post(route('admin.bookings.webCheckin.send', [$this->property, $booking]))->assertSessionHas('success');
    Mail::assertQueued(WebCheckinLinkMail::class, fn (WebCheckinLinkMail $mail): bool => $mail->hasTo('guest@example.com'));

    // Arrival is 10 days away and the link opens 3 days before.
    auth()->logout();
    $this->get($url)->assertOk()->assertInertia(fn ($page) => $page->component('WebCheckin/Show')->where('closedReason', 'Online check-in opens on '.now()->addDays(7)->format('d M Y').'.')->where('prefill.first_name', 'Old'));
    $this->travelTo(now()->addDays(8));
    $this->get($url)->assertInertia(fn ($page) => $page->where('closedReason', null));

    $submit = fn (array $overrides = []) => $this->post(route('web-checkin.submit', $checkin->token), [
        'first_name' => 'Meera', 'last_name' => 'Rao', 'email' => 'meera@example.com', 'phone' => '9000000003',
        'nationality' => 'Indian', 'id_type' => 'Passport', 'id_number' => 'P1234567', 'arrival_time' => '15:30',
        'id_document' => UploadedFile::fake()->image('passport.jpg'),
        'signature' => 'data:image/png;base64,'.base64_encode(UploadedFile::fake()->image('sig.png', 20, 10)->getContent()),
        'accept_terms' => '1',
        'accept_privacy' => '1',
        'marketing' => '1',
        ...$overrides,
    ]);

    $submit(['signature' => '', 'accept_terms' => '', 'accept_privacy' => ''])->assertSessionHasErrors(['signature', 'accept_terms', 'accept_privacy']);
    $submit()->assertSessionHasNoErrors()->assertSessionHas('success');

    $checkin->refresh();
    expect($checkin->status)->toBe(WebCheckin::COMPLETED)
        ->and($checkin->details['id_number'])->toBe('P1234567')
        ->and($guest->refresh()->first_name)->toBe('Meera')
        ->and($guest->emails[0])->toBe('meera@example.com')
        // What the guest agreed to is kept as evidence; they asked for offers.
        ->and(GuestConsent::query()->where('booking_id', $booking->id)->where('source', 'web_checkin')->pluck('granted', 'purpose')->all())->toBe(['terms' => true, 'privacy' => true, 'marketing' => true])
        ->and($guest->subscribe_newsletter)->toBeTrue();
    Storage::disk('local')->assertExists([$checkin->id_document_path, $checkin->signature_path]);

    $this->actingAs($this->admin)->get(route('admin.bookings.show', [$this->property, $booking]))
        ->assertInertia(fn ($page) => $page->where('webCheckin.status', 'completed')->where('webCheckin.has_id_document', true)->where('webCheckin.details.arrival_time', '15:30'));
    $this->actingAs($this->admin)->get(route('admin.bookings.webCheckin.document', [$this->property, $booking]))->assertOk();
    $this->actingAs($this->admin)->get(route('admin.bookings.print', [$this->property, $booking, 'grc']).'?preview=1')->assertOk()->assertSee('alt="Guest signature"', false);

    // After the arrival date it closes.
    $this->travelTo(now()->addDays(5));
    auth()->logout();
    $submit()->assertSessionHasErrors('form');
});
