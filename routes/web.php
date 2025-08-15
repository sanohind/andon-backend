<?php
// File: routes/web.php

use App\Http\Controllers\DashboardController;

// Dashboard utama (hanya yang butuh view)
Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');