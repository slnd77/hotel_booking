<?php

use App\Http\Controllers\Admin\BookingController as AdminBookingController;
use App\Http\Controllers\Admin\ChartNoteController;
use App\Http\Controllers\Admin\GuestController;
use App\Http\Controllers\Admin\HousekeepingController;
use App\Http\Controllers\Admin\MoveBookingController;
use App\Http\Controllers\Admin\RoomBlockController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BookingChannelController;
use App\Http\Controllers\Api\V1\BookingController;
use App\Http\Controllers\Api\V1\GuestController as ApiGuestController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\PosController;
use App\Http\Controllers\Api\V1\PropertyController;
use App\Http\Controllers\Api\V1\SmartChartController;
use App\Http\Controllers\Api\V1\TwoFactorController;
use App\Http\Controllers\ChannexWebhookController;
use App\Http\Middleware\AuthorizeAdminRoute;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\PreventChangesToClosedDates;
use App\Http\Middleware\ServePanelOverApi;
use Illuminate\Support\Facades\Route;

/*
 * The JSON API for the mobile app and third-party integrations. Every authenticated route
 * names its scope (`api.scope:…`); see App\Services\ApiScopes.
 */
Route::prefix('v1')->name('api.v1.')->group(function (): void {
    Route::middleware('throttle:api-auth')->prefix('auth')->name('auth.')->group(function (): void {
        Route::post('login', [AuthController::class, 'login'])->name('login');

        Route::prefix('two-factor')->name('twoFactor.')->group(function (): void {
            Route::post('code', [TwoFactorController::class, 'sendCode'])->name('code.send');
            Route::post('code/verify', [TwoFactorController::class, 'verifyCode'])->name('code.verify');
            Route::post('totp', [TwoFactorController::class, 'totp'])->name('totp');
            Route::post('recovery', [TwoFactorController::class, 'recovery'])->name('recovery');
            Route::post('passkey/options', [TwoFactorController::class, 'passkeyOptions'])->name('passkey.options');
            Route::post('passkey', [TwoFactorController::class, 'verifyPasskey'])->name('passkey.verify');
        });
    });

    Route::middleware(['auth:sanctum', EnsureUserIsActive::class, 'throttle:api'])->group(function (): void {
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');

        // The whole admin panel for the staff app: the same routes, permissions and actions as
        // /admin, each page answering its props as JSON (see ServePanelOverApi).
        Route::prefix('panel')->name('panel.')
            ->middleware(['api.scope:panel', ServePanelOverApi::class, AuthorizeAdminRoute::class, PreventChangesToClosedDates::class])
            ->group(require __DIR__.'/admin-panel.php');

        Route::middleware('api.scope:account:read')->group(function (): void {
            Route::get('me', [AuthController::class, 'me'])->name('me');
            Route::get('properties', [PropertyController::class, 'index'])->name('properties.index');
        });

        Route::middleware('api.scope:channels:manage')->group(function (): void {
            Route::get('booking-channels', [BookingChannelController::class, 'index'])->name('bookingChannels.index');
            Route::post('booking-channels', [BookingChannelController::class, 'store'])->name('bookingChannels.store');
            Route::patch('booking-channels/{bookingChannel}', [BookingChannelController::class, 'update'])->name('bookingChannels.update');
        });

        // The smart booking chart: the same actions as the web chart (each checks the role itself).
        Route::prefix('properties/{property}')->middleware(PreventChangesToClosedDates::class)->group(function (): void {
            Route::get('chart', [SmartChartController::class, 'show'])->middleware('api.scope:chart:read')->name('chart.show');

            Route::middleware('api.scope:chart:notes')->group(function (): void {
                Route::post('chart-notes', [ChartNoteController::class, 'store'])->name('chartNotes.store');
                Route::put('chart-notes/{chartNote}', [ChartNoteController::class, 'update'])->name('chartNotes.update');
            });

            Route::patch('rooms/{room}/housekeeping', HousekeepingController::class)->middleware('api.scope:chart:housekeeping')->name('rooms.housekeeping');

            Route::middleware('api.scope:chart:blocks')->group(function (): void {
                Route::post('room-blocks', [RoomBlockController::class, 'store'])->name('roomBlocks.store');
                Route::post('room-blocks/unblock', [RoomBlockController::class, 'unblock'])->name('roomBlocks.unblock');
                Route::patch('room-blocks/{roomBlock}/move', [RoomBlockController::class, 'move'])->name('roomBlocks.move');
            });

            Route::middleware('api.scope:bookings:read')->group(function (): void {
                Route::get('bookings', [BookingController::class, 'index'])->name('bookings.index');
                Route::get('enquiries', [BookingController::class, 'enquiries'])->name('enquiries.index');
                Route::get('bookings/search', [BookingController::class, 'search'])->name('bookings.search');
                Route::get('bookings/options', [BookingController::class, 'options'])->name('bookings.options');
                Route::get('bookings/{booking}/summary', [AdminBookingController::class, 'summary'])->name('bookings.summary');
                Route::get('bookings/{booking}', [BookingController::class, 'show'])->name('bookings.show');
                Route::get('guests/search', [GuestController::class, 'search'])->name('guests.search');
            });

            Route::middleware('api.scope:bookings:write')->group(function (): void {
                Route::post('bookings', [AdminBookingController::class, 'store'])->name('bookings.store');
                Route::put('bookings/{booking}', [AdminBookingController::class, 'update'])->name('bookings.update');
                Route::post('enquiries', [BookingController::class, 'storeEnquiry'])->name('enquiries.store');
                Route::patch('bookings/{booking}/status', [AdminBookingController::class, 'updateStatus'])->name('bookings.updateStatus');
                Route::patch('bookings/{booking}/move', MoveBookingController::class)->name('bookings.move');
                Route::post('guests', [GuestController::class, 'store'])->name('guests.store');
            });

            Route::middleware('api.scope:guests:read')->group(function (): void {
                Route::get('guests', [ApiGuestController::class, 'index'])->name('guests.index');
                Route::get('guests/{guest}', [ApiGuestController::class, 'show'])->whereNumber('guest')->name('guests.show');
            });

            Route::middleware('api.scope:guests:write')->group(function (): void {
                Route::put('guests/{guest}', [ApiGuestController::class, 'update'])->name('guests.update');
            });

            Route::middleware('api.scope:payments:read')->group(function (): void {
                Route::get('payments', [PaymentController::class, 'index'])->name('payments.index');
                Route::get('payments/{payment}', [PaymentController::class, 'show'])->name('payments.show');
            });

            Route::middleware('api.scope:payments:write')->group(function (): void {
                Route::post('payments', [PaymentController::class, 'store'])->name('payments.store');
                Route::post('payments/{payment}/cancel', [PaymentController::class, 'cancel'])->name('payments.cancel');
            });

            // POS: outlet bills, posted to a room's folio or settled at the outlet.
            Route::scopeBindings()->group(function (): void {
                Route::middleware('api.scope:pos:read')->group(function (): void {
                    Route::get('outlets', [PosController::class, 'outlets'])->name('outlets.index');
                    Route::get('outlet-bills', [PosController::class, 'index'])->name('outletBills.index');
                    Route::get('outlet-bills/{outletBill}', [PosController::class, 'show'])->name('outletBills.show');
                    Route::get('outlet-bills/{outletBill}/print', [PosController::class, 'printBill'])->name('outletBills.print');
                    Route::get('kots/{outletKot}/print', [PosController::class, 'printKot'])->name('kots.print');
                    Route::get('pos/summary', [PosController::class, 'summary'])->name('pos.summary');
                });

                Route::middleware('api.scope:pos:write')->group(function (): void {
                    Route::post('outlets/{outlet}/bills', [PosController::class, 'store'])->name('outletBills.store');
                    Route::put('outlet-bills/{outletBill}', [PosController::class, 'update'])->name('outletBills.update');
                    Route::post('outlet-bills/{outletBill}/post-to-room', [PosController::class, 'postToRoom'])->name('outletBills.postToRoom');
                    Route::post('outlet-bills/{outletBill}/settle', [PosController::class, 'settle'])->name('outletBills.settle');
                    Route::post('outlet-bills/{outletBill}/void', [PosController::class, 'void'])->name('outletBills.void');
                    Route::post('outlet-bills/{outletBill}/kots', [PosController::class, 'sendKot'])->name('outletBills.kots.store');
                    Route::post('outlet-bills/{outletBill}/print', [PosController::class, 'sendBillToPrinter'])->name('outletBills.sendToPrinter');
                    Route::post('kots/{outletKot}/print', [PosController::class, 'sendKotToPrinter'])->name('kots.sendToPrinter');
                    Route::post('outlets/{outlet}/drawer', [PosController::class, 'openDrawer'])->name('outlets.drawer');
                });
            });
        });
    });
});

// Channel manager: Channex calls this on booking changes (the token names the connection).
Route::post('channex/webhook/{token}', ChannexWebhookController::class)->middleware('throttle:120,1')->name('api.channex.webhook');
