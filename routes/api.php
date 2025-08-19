<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;

// Auth Routes - TANPA PREFIX AUTH
Route::post('/login', [AuthController::class, 'login']);
Route::post('/validate-token', [AuthController::class, 'validateToken']);
Route::post('/logout', [AuthController::class, 'logout']);
Route::get('/user', [AuthController::class, 'user']);
Route::delete('/cleanup-sessions', [AuthController::class, 'cleanupExpiredSessions']);

// Dashboard Routes
Route::prefix('dashboard')->group(function () {
    Route::get('/status', [DashboardController::class, 'getStatusApi']);
    Route::get('/problem/{id}', [DashboardController::class, 'getProblemDetail']);
    Route::patch('/problem/{id}/status', [DashboardController::class, 'updateProblemStatus']);
    Route::get('/stats', [DashboardController::class, 'getDashboardStats']);
    Route::get('/analytics', [AnalyticsController::class, 'getAnalyticsData']);
});