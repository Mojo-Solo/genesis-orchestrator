<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\HealthController;

/*
|--------------------------------------------------------------------------
| Health Check Routes
|--------------------------------------------------------------------------
|
| GENESIS Orchestrator health endpoints for monitoring and readiness
|
*/

Route::prefix('health')->group(function () {
    // Readiness probe - checks dependencies
    Route::get('/ready', [HealthController::class, 'ready'])
        ->name('health.ready');
    
    // Liveness probe - checks responsiveness
    Route::get('/live', [HealthController::class, 'live'])
        ->name('health.live');
    
    // Metrics endpoint
    Route::get('/metrics', [HealthController::class, 'metrics'])
        ->name('health.metrics');
});