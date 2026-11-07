<?php

use App\Enums\BookingStatus;
use App\Models\ActivityLog;
use App\Models\Booking;
use App\Models\Currency;
use App\Models\Guest;
use App\Models\GuestConsent;
use App\Models\Invoice;
use App\Models\Language;
use App\Models\MetaField;
use App\Models\Property;
use App\Models\RoomCategory;
use App\Models\State;
use App\Models\User;
use App\Models\WebCheckin;
use App\Services\GstCompliance;
use App\Services\LegalSettings;
use App\Services\PropertySettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Role::firstOrCreate(['name' => 'admin']);
    Currency::firstOrCreate(['code' => 'USD'], ['name' => 'USD']);
    Language::firstOrCreate(['code' => 'en'], ['name' => 'English']);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');
    $this->property = Property::factory()->create(['name' => 'Sea Breeze']);
});

/**
 * A checked-out booking of the property with its guest.
 */
function privacyBooking(Property $property, Guest $guest, BookingStatus $status = BookingStatus::CheckedOut): Booking
{
    $category = RoomCategory::factory()->create(['property_id' => $property->id]);

    return DB::transaction(function () use ($property, $guest, $status, $category): Booking {
        $booking = $property->bookings()->create([
            'booking_ref_id' => 'PRIV-'.fake()->unique()->numerify('####'), 'source' => 'direct', 'status' => $status,
            'check_in' => now()->subDays(3)->toDateString(), 'check_out' => now()->subDay()->toDateString(),
            'room_total' => 2000, 'discount' => 0, 'taxes' => 0, 'total' => 2000, 'outstanding' => 2000,
            'room_bill_to' => 'to_guest', 'extras_bill_to' => 'to_guest',
        ]);
        $booking->rooms()->create(['room_category_id' => $category->id, 'meal_plan' => 'EP', 'adults' => 1, 'rate_per_night' => 1000]);
        $booking->guests()->attach($guest->id);

        return $booking;
    })->fresh();
}

test('the property\'s privacy policy and terms are public, and changing them starts a new version', function () {
    $this->actingAs($this->admin)
        ->put(route('admin.legalSettings.update', $this->property), ['privacy_policy' => 'We at {property} keep your data safe.', 'terms' => 'Be kind.', 'marketing_opt_in' => true])
        ->assertSessionHasErrors('grievance_officer_email');

    $this->put(route('admin.legalSettings.update', $this->property), [
        'privacy_policy' => 'We at {property} keep your data safe.', 'terms' => 'Be kind.', 'marketing_opt_in' => false,
        'grievance_officer_name' => 'Ravi', 'grievance_officer_email' => 'privacy@seabreeze.test',
    ])->assertSessionHasNoErrors();

    $version = app(LegalSettings::class)->version($this->property);
    expect($version)->not->toBe('default')
        ->and(ActivityLog::query()->where('action', 'Privacy & Terms: Privacy policy updated')->exists())->toBeTrue();

    auth()->logout();
    $this->get(route('legal.privacy', $this->property))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Legal/Show')
            ->where('body', 'We at Sea Breeze keep your data safe.')
            ->where('grievanceOfficer.email', 'privacy@seabreeze.test')
            ->where('version', $version));
    $this->get(route('legal.terms', $this->property))->assertInertia(fn ($page) => $page->where('title', 'Terms of Booking')->where('body', 'Be kind.'));
});

test('the booking engine books only with the terms accepted, and keeps the consents as evidence', function () {
    $category = RoomCategory::factory()->create(['property_id' => $this->property->id, 'meal_plans' => ['EP']]);
    sellOnBookingEngine($this->property, $category, 1000);
    $selection = ['check_in' => now()->addDays(3)->toDateString(), 'check_out' => now()->addDays(4)->toDateString(), 'rooms' => [['category_id' => $category->id, 'meal_plan' => 'EP']]];

    // Pre-ticked or missing consent isn't consent.
    $this->postJson(route('booking-engine.checkout.initiate', $this->property->ulid), [
        ...$selection, 'payment_gateway' => 'test', 'consent' => ['terms' => false],
        'guest' => ['name' => 'Asha Rao', 'phone' => '+91 9000000001', 'email' => 'asha@example.com'],
    ])->assertJsonValidationErrors(['consent.terms' => 'Please accept the terms of booking and the privacy policy.']);

    bookThroughEngine($this, $this->property, [...$selection, 'consent' => ['terms' => true, 'marketing' => true]])->assertOk();

    $booking = Booking::query()->where('property_id', $this->property->id)->sole();
    $consents = GuestConsent::query()->where('booking_id', $booking->id)->get();

    expect($consents->pluck('granted', 'purpose')->all())->toBe(['terms' => true, 'privacy' => true, 'marketing' => true])
        ->and($consents->pluck('source')->unique()->all())->toBe(['booking_engine'])
        ->and($consents->first()->policy_version)->toBe(app(LegalSettings::class)->version($this->property))
        ->and($consents->first()->guest_id)->toBe($booking->guests()->first()->id)
        ->and($booking->guests()->first()->subscribe_newsletter)->toBeTrue();
});

test('a guest gets a copy of their data, can stop marketing, and is erased once no stay is pending', function () {
    Storage::fake('local');
    $guest = Guest::factory()->create(['property_id' => $this->property->id, 'first_name' => 'Meera', 'last_name' => 'Rao', 'phone' => '9000000003', 'emails' => ['meera@example.com'], 'subscribe_newsletter' => true]);
    $past = privacyBooking($this->property, $guest);
    $upcoming = privacyBooking($this->property, $guest, BookingStatus::Confirmed);
    GuestConsent::factory()->create(['property_id' => $this->property->id, 'guest_id' => $guest->id, 'purpose' => GuestConsent::MARKETING]);
    Storage::disk('local')->put('web-checkins/id.jpg', 'id');
    WebCheckin::query()->create(['property_id' => $this->property->id, 'booking_id' => $past->id, 'token' => 'tok-1', 'status' => WebCheckin::COMPLETED, 'details' => ['id_number' => 'P1234567'], 'id_document_path' => 'web-checkins/id.jpg']);

    $this->actingAs($this->admin);
    $export = $this->get(route('admin.databases.guests.privacy.export', $guest))->assertOk();

    expect($export->headers->get('content-disposition'))->toContain("guest-{$guest->id}-data.json")
        ->and($export->json('guest.first_name'))->toBe('Meera')
        ->and($export->json('consents.0.purpose'))->toBe('marketing')
        ->and(collect($export->json('bookings'))->pluck('booking_ref_id')->all())->toContain($past->booking_ref_id)
        ->and(collect($export->json('bookings'))->firstWhere('booking_ref_id', $past->booking_ref_id)['web_checkin']['id_number'])->toBe('P1234567');

    $this->post(route('admin.databases.guests.privacy.withdrawMarketing', $guest))->assertSessionHas('success');
    expect($guest->refresh()->subscribe_newsletter)->toBeFalse()
        ->and(GuestConsent::query()->where('guest_id', $guest->id)->whereNull('withdrawn_at')->exists())->toBeFalse();

    // The upcoming stay still needs the guest's details.
    $this->post(route('admin.databases.guests.privacy.erase', $guest))->assertSessionHasErrors('guest');

    $upcoming->update(['status' => BookingStatus::Cancelled]);
    $this->post(route('admin.databases.guests.privacy.erase', $guest))->assertSessionHasNoErrors();

    $guest->refresh();
    expect($guest->first_name)->toBe('Erased')
        ->and($guest->phone)->toBeNull()
        ->and($guest->emails)->toBe([])
        ->and(WebCheckin::query()->sole()->details)->toBeNull()
        ->and($past->refresh()->guests()->first()->id)->toBe($guest->id);
    Storage::disk('local')->assertMissing('web-checkins/id.jpg');
});

test('guest data requests need the permission', function () {
    $guest = Guest::factory()->create(['property_id' => $this->property->id]);
    $clerk = User::factory()->create();
    $clerk->assignRole(Role::findOrCreate('Clerk', 'web'));

    $this->actingAs($clerk)->get(route('admin.databases.guests.privacy.export', $guest))->assertForbidden();
    $this->actingAs($clerk)->post(route('admin.databases.guests.privacy.erase', $guest))->assertForbidden();
});

test('invoices take consecutive serial numbers that are kept when an invoice is generated again', function () {
    $guest = Guest::factory()->create(['property_id' => $this->property->id, 'company' => ['name' => 'Acme', 'gstin' => '27aaaca1234a1z5']]);
    $field = MetaField::query()->firstOrCreate(['key' => 'invoice_settings_trackers'], ['key_label' => 'Trackers', 'status' => true]);
    $this->property->metaFieldValues()->create(['meta_fields_key_id' => $field->id, 'enabled' => true, 'data' => json_encode(['invoice' => ['prefix' => 'SB/', 'in_prefix' => '26-27/', 'postfix_digits' => 4, 'postfix' => '', 'auto_reset' => true]])]);
    app(PropertySettings::class)->forget();

    $first = Invoice::generateForBooking(privacyBooking($this->property, $guest), 'Staff');
    $second = Invoice::generateForBooking($secondBooking = privacyBooking($this->property, $guest), 'Staff');
    $again = Invoice::generateForBooking($secondBooking, 'Staff');

    expect($first->invoice_number)->toBe('SB/26-27/0001')
        ->and($second->invoice_number)->toBe('SB/26-27/0002')
        ->and($again->invoice_number)->toBe('SB/26-27/0002')
        ->and($first->party_gstin)->toBe('27AAACA1234A1Z5');
});

test('the GST check shows what a tax invoice is still missing', function () {
    $checks = fn (): array => collect(app(GstCompliance::class)->check($this->property->fresh()))->pluck('status', 'key')->all();

    // The factory's state isn't an Indian one.
    expect($checks())->toMatchArray(['gstin' => 'fail', 'state' => 'warn', 'tax' => 'fail', 'sac' => 'pass']);

    $state = State::query()->create(['name' => 'Maharashtra', 'country_id' => $this->property->country_id]);
    $this->property->update(['state_id' => $state->id, 'property_legal_name' => 'Sea Breeze Hotels Pvt Ltd']);
    $settings = MetaField::query()->firstOrCreate(['key' => 'invoice_settings_general'], ['key_label' => 'Invoice', 'status' => true]);
    $this->property->metaFieldValues()->create(['meta_fields_key_id' => $settings->id, 'enabled' => true, 'data' => json_encode(['statutory_ids' => [['id' => 1, 'display_name' => 'GSTIN', 'display_value' => '27AAACA1234A1Z5']]])]);
    $categoryField = MetaField::query()->firstOrCreate(['key' => 'tax_settings_tax_category'], ['key_label' => 'Tax Category', 'status' => true]);
    $ruleField = MetaField::query()->firstOrCreate(['key' => 'tax_settings_tax_rule'], ['key_label' => 'Tax Rule', 'status' => true]);
    $component = fn (string $name): array => ['component_choice' => $name, 'applied_on' => 'rack_rate', 'tax_value_type' => 'percentage', 'tax_value' => 6, 'define_tax_slab' => false];
    $this->property->metaFieldValues()->create(['meta_fields_key_id' => $categoryField->id, 'enabled' => true, 'data' => json_encode(['categories' => [['id' => 1, 'name' => 'Room']]])]);
    $this->property->metaFieldValues()->create(['meta_fields_key_id' => $ruleField->id, 'enabled' => true, 'data' => json_encode(['rules' => [
        ['tax_category_id' => 1, 'deleted' => false, 'start_date' => null, 'end_date' => null, 'components' => [$component('CGST'), $component('SGST')]],
    ]])]);
    app(PropertySettings::class)->forget();

    expect($checks())->toMatchArray(['gstin' => 'pass', 'state' => 'pass', 'legal_name' => 'pass', 'tax' => 'pass', 'number_length' => 'pass', 'serials' => 'pass']);

    $this->actingAs($this->admin)->get(route('admin.legalSettings.edit', $this->property))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('admin/legal-settings/Edit')->has('gstChecks', 7));
});
