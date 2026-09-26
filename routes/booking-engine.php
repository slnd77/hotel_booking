<?php

use App\Http\Controllers\BookingEngineController;
use App\Http\Controllers\LegalPageController;
use App\Http\Controllers\OutletMenuController;
use App\Http\Controllers\PaymentLinkPageController;
use App\Http\Controllers\TableOrderController;
use App\Http\Controllers\WebCheckinPageController;
use Illuminate\Support\Facades\Route;

// "Collect Online" payment links: the URL carries only the link's random token.
Route::get('pay/{paymentLink:token}', [PaymentLinkPageController::class, 'show'])->name('payment-links.show');
Route::middleware('throttle:20,1')->group(function () {
    Route::post('pay/{paymentLink:token}/initiate', [PaymentLinkPageController::class, 'initiate'])->name('payment-links.initiate');
    Route::post('pay/{paymentLink:token}/confirm', [PaymentLinkPageController::class, 'confirm'])->name('payment-links.confirm');
});

// Web check-in: the guest's pre-arrival form, by the link's random token.
Route::get('checkin/{webCheckin:token}', [WebCheckinPageController::class, 'show'])->middleware('throttle:60,1')->name('web-checkin.show');
Route::post('checkin/{webCheckin:token}', [WebCheckinPageController::class, 'submit'])->middleware('throttle:10,1')->name('web-checkin.submit');

// Ordering from a table's QR code (the outlet must take QR orders; staff confirm on the POS).
Route::get('order/{outletTable:qr_token}', [TableOrderController::class, 'show'])->middleware('throttle:60,1')->name('table-order.show');
Route::post('order/{outletTable:qr_token}', [TableOrderController::class, 'store'])->middleware('throttle:10,1')->name('table-order.store');

// An outlet's public menu, opened from its QR code.
Route::get('menu/{menuToken}', [OutletMenuController::class, 'show'])->middleware('throttle:60,1')->name('outlet-menu.show');

// The property's privacy policy and terms of booking, linked from checkout and web check-in.
Route::get('properties/{property:ulid}/privacy', [LegalPageController::class, 'privacy'])->middleware('throttle:60,1')->name('legal.privacy');
Route::get('properties/{property:ulid}/terms', [LegalPageController::class, 'terms'])->middleware('throttle:60,1')->name('legal.terms');

Route::get('properties/{property:ulid}/booking-engine/web', [BookingEngineController::class, 'web'])->name('booking-engine.web');
Route::get('properties/{property:ulid}/booking-engine/calendar', [BookingEngineController::class, 'calendar'])->name('booking-engine.calendar');
Route::post('properties/{property:ulid}/booking-engine/quote', [BookingEngineController::class, 'quote'])->middleware('throttle:booking-engine-quote')->name('booking-engine.quote');
// Sold out: the guest joins the waitlist for the dates.
Route::post('properties/{property:ulid}/booking-engine/waitlist', [BookingEngineController::class, 'joinWaitlist'])->middleware('throttle:10,1')->name('booking-engine.waitlist');
Route::middleware('throttle:booking-engine-checkout')->group(function (): void {
    Route::post('properties/{property:ulid}/booking-engine/checkout/initiate', [BookingEngineController::class, 'initiateCheckout'])->name('booking-engine.checkout.initiate');
    Route::post('properties/{property:ulid}/booking-engine/checkout/confirm', [BookingEngineController::class, 'confirmCheckout'])->name('booking-engine.checkout.confirm');
});
