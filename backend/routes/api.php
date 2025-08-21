<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// GENESIS Orchestrator API Routes
Route::prefix('orchestration')->group(function () {
    Route::get('/health', function () {
        return response()->json(['status' => 'healthy', 'timestamp' => now()]);
    });
    
    Route::get('/version', function () {
        return response()->json([
            'version' => '1.0.0',
            'laravel' => app()->version(),
            'php' => PHP_VERSION
        ]);
    });
});