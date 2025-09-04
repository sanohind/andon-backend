<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\InspectionTableController;

// Auth Routes - TANPA PREFIX AUTH
Route::post('/login', [AuthController::class, 'login']);
Route::post('/validate-token', [AuthController::class, 'validateToken']);
Route::post('/logout', [AuthController::class, 'logout']);
Route::get('/user', [AuthController::class, 'user']);
Route::delete('/cleanup-sessions', [AuthController::class, 'cleanupExpiredSessions']);

// Dashboard Routes
Route::prefix('dashboard')->group(function () {
    Route::get('/problem/{id}', [DashboardController::class, 'getProblemDetail']);
    Route::patch('/problem/{id}/status', [DashboardController::class, 'updateProblemStatus']);
    Route::get('/stats', [DashboardController::class, 'getDashboardStats']);
    Route::get('/analytics', [AnalyticsController::class, 'getAnalyticsData']);
    Route::get('/plc-status', [DashboardController::class, 'getPlcStatus']);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/users', [UserController::class, 'index']);
    Route::post('/users', [UserController::class, 'store']);
    Route::apiResource('/inspection-tables', InspectionTableController::class);
    Route::get('/machine-status/{name}', [InspectionTableController::class, 'getMachineStatus']);
    Route::get('dashboard/status', [DashboardController::class, 'getStatusApi']);
});