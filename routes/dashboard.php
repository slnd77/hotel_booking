<?php

use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
