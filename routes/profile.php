<?php

use App\Http\Controllers\DeveloperController;
use App\Http\Controllers\PasskeyRegistrationController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\TwoFactorMethodController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->prefix('profile')->name('profile.')->group(function (): void {
    Route::get('/', [ProfileController::class, 'edit'])->name('edit');
    Route::get('two-factor', [ProfileController::class, 'twoFactor'])->middleware('password.confirm')->name('twoFactor');

    // Two-factor methods besides the authenticator app (Fortify's own routes).
    Route::middleware('password.confirm')->group(function (): void {
        Route::middleware('throttle:passkey-registration')->group(function (): void {
            Route::get('passkeys/options', [PasskeyRegistrationController::class, 'index'])->name('passkeys.options');
            Route::post('passkeys', [PasskeyRegistrationController::class, 'store'])->name('passkeys.store');
        });
        Route::delete('passkeys/{passkey}', [PasskeyRegistrationController::class, 'destroy'])->name('passkeys.destroy');

        Route::post('two-factor/channels/{method}/code', [TwoFactorMethodController::class, 'sendCode'])->middleware('throttle:6,1')->name('twoFactor.channels.code');
        Route::post('two-factor/channels/{method}', [TwoFactorMethodController::class, 'enable'])->middleware('throttle:6,1')->name('twoFactor.channels.enable');
        Route::delete('two-factor/channels/{method}', [TwoFactorMethodController::class, 'disable'])->name('twoFactor.channels.disable');

        Route::get('two-factor/recovery-codes', [TwoFactorMethodController::class, 'recoveryCodes'])->name('twoFactor.recoveryCodes');
        Route::post('two-factor/recovery-codes', [TwoFactorMethodController::class, 'regenerateRecoveryCodes'])->name('twoFactor.recoveryCodes.regenerate');

        // API keys for third-party apps, and the mobile devices signed in.
        Route::get('developer', [DeveloperController::class, 'index'])->name('developer.index');
        Route::post('developer/keys', [DeveloperController::class, 'store'])->middleware('throttle:10,1')->name('developer.keys.store');
        Route::delete('developer/keys/{token}', [DeveloperController::class, 'destroyKey'])->name('developer.keys.destroy');
        Route::delete('developer/devices/{token}', [DeveloperController::class, 'destroyDevice'])->name('developer.devices.destroy');
    });
});
