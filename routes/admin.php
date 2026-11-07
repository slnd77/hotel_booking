<?php

use App\Http\Middleware\AuthorizeAdminRoute;
use App\Http\Middleware\PreventChangesToClosedDates;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', AuthorizeAdminRoute::class, PreventChangesToClosedDates::class])->prefix('admin')->name('admin.')->group(require __DIR__.'/admin-panel.php');
