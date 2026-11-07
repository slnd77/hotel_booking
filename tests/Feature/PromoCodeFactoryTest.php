<?php

use App\Models\Currency;
use App\Models\Language;
use App\Models\Property;
use App\Services\PropertySettings;
use Database\Factories\DealsPromotionFactory;
use Database\Factories\PromoCodeFactory;
use Database\Factories\PublicPromoCodeFactory;
use Database\Factories\SecretPromoCodeFactory;

beforeEach(function () {
    Currency::firstOrCreate(['code' => 'USD'], ['name' => 'USD']);
    Language::firstOrCreate(['code' => 'en'], ['name' => 'English']);
});

test('public promo code factory generates valid public promo structures', function () {
    $promo = PublicPromoCodeFactory::make();

    expect($promo)->toHaveKeys(['id', 'code', 'discount_type', 'discount_value', 'channels', 'booking_amount_range', 'status'])
        ->and($promo['channels'])->toContain('booking_engine')
        ->and($promo['status'])->toBeTrue()
        ->and($promo['deleted'])->toBeFalse();
});

test('secret promo code factory generates valid secret promo structures', function () {
    $promo = SecretPromoCodeFactory::make();

    expect($promo)->toHaveKeys(['id', 'code', 'discount_type', 'discount_value', 'channels', 'booking_amount_range', 'status'])
        ->and($promo['channels'])->toContain('booking_engine')
        ->and($promo['code'])->toBe('VIPSECRET')
        ->and($promo['discount_value'])->toBe(25);
});

test('promo code factory can create custom and multiple promos', function () {
    $custom = PromoCodeFactory::public([
        'code' => 'CUSTOM50',
        'discount_value' => 50,
        'booking_amount_range' => ['enabled' => true, 'min' => 10000, 'max' => 50000],
    ]);

    expect($custom['code'])->toBe('CUSTOM50')
        ->and($custom['discount_value'])->toBe(50)
        ->and($custom['booking_amount_range']['min'])->toBe(10000);

    $multipleSecret = SecretPromoCodeFactory::makeMany(3);
    expect($multipleSecret)->toHaveCount(3);
});

test('deals promotion factory attaches public and secret promos, and the page shows only the public ones', function () {
    $property = Property::factory()->create();
    DealsPromotionFactory::applyTo($property);

    $response = $this->get("/properties/{$property->ulid}/booking-engine/web");
    $response->assertOk();

    $props = $response->viewData('page')['props'];
    expect($props['dealsPromotion'])->not->toBeNull()
        ->and($props['dealsPromotion']['public_promo_codes'])->toBeArray()
        ->and(count($props['dealsPromotion']['public_promo_codes']))->toBeGreaterThan(0)
        ->and($props['dealsPromotion'])->not->toHaveKey('secret_promo_codes')
        ->and(app(PropertySettings::class)->json($property, 'rate_settings_deals_promotion')['secret_promo_codes'])->not->toBeEmpty();
});
