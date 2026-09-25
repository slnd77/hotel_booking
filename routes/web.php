<?php

use App\Http\Controllers\Auth\GoogleSignInController;
use App\Http\Controllers\Auth\TwoFactorChallengeController;
use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return auth()->check() ? redirect()->route('dashboard') : redirect()->route('login');
})->name('home');

// The second login step by WhatsApp / SMS / e-mail code or passkey (Fortify serves the page,
// the authenticator app and recovery codes).
Route::middleware(['guest', 'throttle:two-factor'])->prefix('two-factor-challenge')->name('two-factor.')->group(function (): void {
    Route::post('code', [TwoFactorChallengeController::class, 'sendCode'])->name('code.send');
    Route::post('code/verify', [TwoFactorChallengeController::class, 'verifyCode'])->name('code.verify');
    Route::get('passkey/options', [TwoFactorChallengeController::class, 'passkeyOptions'])->name('passkey.options');
    Route::post('passkey', [TwoFactorChallengeController::class, 'verifyPasskey'])->name('passkey.verify');
});

// "Continue with Google" on the log-in and create-account pages.
Route::middleware(['guest', 'throttle:20,1'])->prefix('auth/google')->name('auth.google.')->group(function (): void {
    Route::get('redirect/{intent?}', [GoogleSignInController::class, 'redirect'])->whereIn('intent', ['login', 'register'])->name('redirect');
    Route::get('callback', [GoogleSignInController::class, 'callback'])->name('callback');
});

// For an uptime monitor: 200 while the site, its queue worker and scheduler work, else 503.
Route::get('health', HealthController::class)->middleware('throttle:60,1')->name('health');

require __DIR__.'/dashboard.php';
require __DIR__.'/profile.php';
require __DIR__.'/admin.php';
require __DIR__.'/booking-engine.php';
