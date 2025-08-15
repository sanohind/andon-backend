<?php
// File: routes/api.php

use App\Http\Controllers\DashboardController;

Route::prefix('dashboard')->group(function () {
    Route::get('/status', [DashboardController::class, 'getStatusApi']);
    Route::get('/problem/{id}', [DashboardController::class, 'getProblemDetail']);
    Route::patch('/problem/{id}/status', [DashboardController::class, 'updateProblemStatus']);
    Route::get('/stats', [DashboardController::class, 'getDashboardStats']);
});