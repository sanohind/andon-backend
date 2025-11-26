<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\InspectionTableController;
use App\Http\Controllers\TicketingProblemController;
use App\Http\Controllers\PartConfigurationController;
use App\Http\Controllers\DivisionLineController;

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
    Route::get('/analytics/duration', [AnalyticsController::class, 'getProblemDurationAnalytics']);
    Route::get('/analytics/detailed-forward', [AnalyticsController::class, 'getDetailedForwardAnalyticsData']);
    Route::get('/analytics/ticketing', [AnalyticsController::class, 'getTicketingAnalyticsData']);
    Route::get('/analytics', [AnalyticsController::class, 'getAnalyticsData']);
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
    Route::get('/ticketing/{id}', [TicketingProblemController::class, 'getTicketingById']);
    Route::put('/ticketing/{id}', [TicketingProblemController::class, 'updateTicketing']);
    Route::get('/ticketing/problem/{problemId}', [TicketingProblemController::class, 'getTicketingByProblem']);
    Route::get('/ticketing/technicians', [TicketingProblemController::class, 'getTechnicians']);
});

// Ticketing Problem Routes (non-dashboard prefix for analytics edit modal)
Route::prefix('ticketing')->group(function () {
    Route::get('/data', [TicketingProblemController::class, 'getTicketingData']);
    Route::post('/', [TicketingProblemController::class, 'createTicketing']);
    Route::get('/{id}', [TicketingProblemController::class, 'getTicketingById'])->whereNumber('id');
    Route::put('/{id}', [TicketingProblemController::class, 'updateTicketing'])->whereNumber('id');
    Route::get('/problem/{problemId}', [TicketingProblemController::class, 'getTicketingByProblem'])->whereNumber('problemId');
    Route::get('/technicians/list', [TicketingProblemController::class, 'getTechnicians']);
});

// Dashboard status route - accessible without Sanctum (uses custom auth)
Route::get('dashboard/status', [DashboardController::class, 'getStatusApi']);

// Problems active route - accessible without Sanctum (uses custom auth)
Route::get('problems/active', [DashboardController::class, 'getActiveProblemsApi']);

// Divisions and lines route - accessible without Sanctum (uses custom auth)
Route::get('divisions-lines', [DashboardController::class, 'getDivisionsAndLines']);

// Division and Line management routes - accessible without Sanctum (uses custom auth)
Route::prefix('division-lines')->group(function () {
    Route::get('/', [DivisionLineController::class, 'index']);
    Route::post('/divisions', [DivisionLineController::class, 'storeDivision']);
    Route::put('/divisions/{id}', [DivisionLineController::class, 'updateDivision']);
    Route::delete('/divisions/{id}', [DivisionLineController::class, 'destroyDivision']);
    Route::post('/lines', [DivisionLineController::class, 'storeLine']);
    Route::put('/lines/{id}', [DivisionLineController::class, 'updateLine']);
    Route::delete('/lines/{id}', [DivisionLineController::class, 'destroyLine']);
});

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
Route::get('/inspection-tables/metrics', [InspectionTableController::class, 'metrics']);
Route::post('/inspection-tables', [InspectionTableController::class, 'store']);
Route::get('/inspection-tables/{inspectionTable}', [InspectionTableController::class, 'show'])->whereNumber('inspectionTable');
Route::put('/inspection-tables/{inspectionTable}', [InspectionTableController::class, 'update']);
Route::put('/inspection-tables/address/{address}', [InspectionTableController::class, 'updateByAddress']);
Route::put('/inspection-tables/address/{address}/target', [InspectionTableController::class, 'setTarget']);
Route::put('/inspection-tables/address/{address}/cycle', [InspectionTableController::class, 'setCycle']);
Route::put('/inspection-tables/address/{address}/cycle-threshold', [InspectionTableController::class, 'setCycleThreshold']);
Route::delete('/inspection-tables/{inspectionTable}', [InspectionTableController::class, 'destroy'])->whereNumber('inspectionTable');
Route::get('/machine-status/{name}', [InspectionTableController::class, 'getMachineStatus']);

// Part configurations routes - accessible without Sanctum (uses custom auth)
Route::get('/part-configurations', [PartConfigurationController::class, 'index']);
Route::post('/part-configurations', [PartConfigurationController::class, 'store']);
Route::post('/part-configurations/bulk-import', [PartConfigurationController::class, 'bulkImport']);
Route::get('/part-configurations/{id}', [PartConfigurationController::class, 'show']);
Route::put('/part-configurations/{id}', [PartConfigurationController::class, 'update']);
Route::delete('/part-configurations/{id}', [PartConfigurationController::class, 'destroy']);

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