<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\InspectionTableController;
use App\Http\Controllers\TicketingProblemController;

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
    Route::get('/analytics/duration', [AnalyticsController::class, 'getProblemDurationAnalytics']);
    Route::get('/analytics/detailed-forward', [AnalyticsController::class, 'getDetailedForwardAnalyticsData']);
    Route::get('/analytics/ticketing', [AnalyticsController::class, 'getTicketingAnalyticsData']);
    Route::get('/plc-status', [DashboardController::class, 'getPlcStatus']);
    
    // Forward Problem Routes
    Route::post('/problem/{id}/forward', [DashboardController::class, 'forwardProblem']);
    Route::post('/problem/{id}/receive', [DashboardController::class, 'receiveProblem']);
    Route::post('/problem/{id}/feedback-resolved', [DashboardController::class, 'feedbackResolved']);
    Route::post('/problem/{id}/final-resolved', [DashboardController::class, 'finalResolved']);
    Route::get('/forward-logs', [DashboardController::class, 'getForwardLogs']);
    Route::get('/forward-logs/{problemId}', [DashboardController::class, 'getForwardLogs']);
    
    // Ticketing Problem Routes
    Route::get('/ticketing/data', [TicketingProblemController::class, 'getTicketingData']);
    Route::post('/ticketing', [TicketingProblemController::class, 'createTicketing']);
    Route::put('/ticketing/{id}', [TicketingProblemController::class, 'updateTicketing']);
    Route::get('/ticketing/problem/{problemId}', [TicketingProblemController::class, 'getTicketingByProblem']);
    Route::get('/ticketing/technicians', [TicketingProblemController::class, 'getTechnicians']);
});

// Dashboard status route - accessible without Sanctum (uses custom auth)
Route::get('dashboard/status', [DashboardController::class, 'getStatusApi']);

// Problems active route - accessible without Sanctum (uses custom auth)
Route::get('problems/active', [DashboardController::class, 'getActiveProblemsApi']);

// Add new problem route - accessible without Sanctum (uses custom auth)
Route::post('problems/add', [DashboardController::class, 'addProblem']);

// Manager notifications route - accessible without Sanctum (uses custom auth)
Route::get('problems/unresolved-manager', [DashboardController::class, 'getManagerUnresolvedProblems']);


// User management routes - accessible without Sanctum (uses custom auth)
Route::get('/users', [UserController::class, 'index']);
Route::post('/users', [UserController::class, 'store']);
Route::put('/users/{user}', [UserController::class, 'update']);
Route::delete('/users/{user}', [UserController::class, 'destroy']);

// Inspection tables routes - accessible without Sanctum (uses custom auth)
Route::get('/inspection-tables', [InspectionTableController::class, 'index']);
Route::post('/inspection-tables', [InspectionTableController::class, 'store']);
Route::get('/inspection-tables/{inspectionTable}', [InspectionTableController::class, 'show']);
Route::put('/inspection-tables/{inspectionTable}', [InspectionTableController::class, 'update']);
Route::put('/inspection-tables/address/{address}', [InspectionTableController::class, 'updateByAddress']);
Route::delete('/inspection-tables/{inspectionTable}', [InspectionTableController::class, 'destroy']);
Route::get('/machine-status/{name}', [InspectionTableController::class, 'getMachineStatus']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/sanctum-user', [AuthController::class, 'sanctumUser']);
});

// PLC Status routes - accessible without Sanctum (uses custom auth)
Route::get('/plc-status', [DashboardController::class, 'getPlcStatusFromDatabase']);
Route::post('/plc-status', [DashboardController::class, 'createPlcDevice']);
Route::put('/plc-status/{id}', [DashboardController::class, 'updatePlcDevice']);
Route::delete('/plc-status/{id}', [DashboardController::class, 'deletePlcDevice']);

// Inspection tables route for PLC monitoring - REMOVED DUPLICATE ROUTE
// Route::get('/inspection-tables', [DashboardController::class, 'getInspectionTables']);

Route::get('/health-check', function () {
    return response()->json(['status' => 'ok']);
});