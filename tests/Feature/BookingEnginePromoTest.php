<?php

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Currency;
use App\Models\Language;
use App\Models\MetaField;
use App\Models\Property;
use App\Models\RoomCategory;
use App\Services\PropertySettings;

beforeEach(function () {
    Currency::create(['code' => 'USD', 'name' => 'USD']);
    Language::create(['code' => 'en', 'name' => 'English']);
    config(['services.test_gateway.enabled' => true]);

    $this->property = Property::factory()->create();
    $this->category = RoomCategory::factory()->create(['property_id' => $this->property->id, 'meal_plans' => ['EP']]);
    sellOnBookingEngine($this->property, $this->category, 1000);
    $this->checkIn = now()->addDays(10)->toDateString();
    $this->checkOut = now()->addDays(12)->toDateString();
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function promoCode(string $code, array $overrides = []): array
{
    return array_replace_recursive([
        'id' => crc32($code),
        'deal_type' => 'basic',
        'code' => $code,
        'tag_line' => '',
        'discount_type' => 'percentage',
        'discount_value' => 10,
        'channels' => ['booking_engine'],
        'usage_limit' => ['enabled' => false, 'value' => null],
        'max_total_budget' => ['enabled' => false, 'value' => null],
        'length_of_stay' => ['enabled' => false, 'value' => null],
        'status' => true,
        'deleted' => false,
    ], $overrides);
}

/**
 * @param  list<array<string, mixed>>  $public
 * @param  list<array<string, mixed>>  $secret
 */
function savePromoCodes(Property $property, array $public, array $secret = []): void
{
    $field = MetaField::query()->firstOrCreate(['key' => 'rate_settings_deals_promotion'], ['key_label' => 'Deals & Promotion', 'status' => true]);

    $property->metaFieldValues()->updateOrCreate(
        ['meta_fields_key_id' => $field->id],
        ['enabled' => true, 'data' => json_encode(['auto_apply_public_promo' => true, 'public_promo_codes' => $public, 'secret_promo_codes' => $secret])],
    );
    app(PropertySettings::class)->forgetMetaFields();
}

/**
 * Two nights in one room at 1000 a night: a room total of 2000.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function promoSelection(RoomCategory $category, string $checkIn, string $checkOut, array $overrides = []): array
{
    return [
        'check_in' => $checkIn,
        'check_out' => $checkOut,
        'rooms' => [['category_id' => $category->id, 'meal_plan' => 'EP', 'adults' => 1]],
        ...$overrides,
    ];
}

test('the booking engine page lists public promo codes without their limits and never the secret ones', function () {
    savePromoCodes(
        $this->property,
        [promoCode('SUMMER', ['usage_limit' => ['enabled' => true, 'value' => 5]]), promoCode('OLD', ['deleted' => true])],
        [promoCode('VIPONLY')],
    );

    $props = $this->get("/properties/{$this->property->ulid}/booking-engine/web")->assertOk()->viewData('page')['props'];

    expect(collect($props['dealsPromotion']['public_promo_codes'])->pluck('code')->all())->toBe(['SUMMER'])
        ->and($props['dealsPromotion']['public_promo_codes'][0])->not->toHaveKey('usage_limit')
        ->and($props['dealsPromotion'])->not->toHaveKey('secret_promo_codes')
        ->and(json_encode($props))->not->toContain('VIPONLY');
});

test('a secret promo code typed by the guest is checked and priced on the server', function () {
    savePromoCodes($this->property, [], [promoCode('VIPONLY', ['discount_type' => 'fixed', 'discount_value' => 300, 'length_of_stay' => ['enabled' => true, 'value' => 3]])]);
    $url = "/properties/{$this->property->ulid}/booking-engine/quote";

    $this->postJson($url, promoSelection($this->category, $this->checkIn, $this->checkOut, ['promo_code' => 'nope']))
        ->assertJsonValidationErrors(['promo_code' => 'Invalid promo code.']);

    $this->postJson($url, promoSelection($this->category, $this->checkIn, $this->checkOut, ['promo_code' => 'viponly']))
        ->assertJsonValidationErrors(['promo_code' => 'Minimum stay of 3 night(s) required.']);

    $this->postJson($url, promoSelection($this->category, $this->checkIn, now()->addDays(13)->toDateString(), ['promo_code' => 'viponly']))
        ->assertOk()
        ->assertJsonPath('promo.code', 'VIPONLY')
        ->assertJsonPath('quote.room_total', 3000)
        ->assertJsonPath('quote.discount', 300)
        ->assertJsonPath('quote.grand_total', 2700);
});

test('checkout keeps the promo code and the discount priced on the server', function () {
    savePromoCodes($this->property, [promoCode('SUMMER')]);

    $response = bookThroughEngine($this, $this->property, promoSelection($this->category, $this->checkIn, $this->checkOut, [
        'promo_code' => 'summer',
        // A discount the page claims is ignored.
        'discount' => 1500,
    ]))->assertOk();

    $booking = Booking::query()->where('booking_ref_id', $response->json('booking.booking_ref_id'))->sole();

    expect($booking->promo_code)->toBe('SUMMER')
        ->and((float) $booking->room_total)->toBe(2000.0)
        ->and((float) $booking->discount)->toBe(200.0)
        ->and((float) $booking->total)->toBe(1800.0)
        ->and((float) $booking->outstanding)->toBe(0.0);
});

test('a promo code stops at checkout once its usage limit is reached', function () {
    savePromoCodes($this->property, [promoCode('SUMMER', ['usage_limit' => ['enabled' => true, 'value' => 1]])]);
    $payload = [
        ...promoSelection($this->category, $this->checkIn, $this->checkOut, ['promo_code' => 'SUMMER']),
        'payment_gateway' => 'test',
        'consent' => ['terms' => true], 'guest' => ['name' => 'Asha Rao', 'phone' => '+91 9000000001', 'email' => 'asha@example.com'],
    ];
    $initiateUrl = "/properties/{$this->property->ulid}/booking-engine/checkout/initiate";

    $this->postJson($initiateUrl, $payload)->assertOk();

    $redeemed = Booking::create([
        'property_id' => $this->property->id,
        'booking_ref_id' => 'BK-REDEEMED',
        'source' => 'booking_engine',
        'check_in' => now()->addDays(30)->toDateString(),
        'check_out' => now()->addDays(31)->toDateString(),
        'status' => BookingStatus::Confirmed,
        'discount' => 200,
        'promo_code' => 'SUMMER',
        'total' => 1800,
        'outstanding' => 0,
    ]);

    $this->postJson($initiateUrl, $payload)
        ->assertJsonValidationErrors(['promo_code' => 'This promo code has been fully redeemed.']);

    // A cancelled booking gives its use back.
    $redeemed->update(['status' => BookingStatus::Cancelled]);

    $this->postJson($initiateUrl, $payload)->assertOk();
});
