<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CalendarController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\GuestController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\ReservationController;
use App\Http\Controllers\Api\V1\ResourceController;
use App\Http\Controllers\Api\V1\TaskController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/auth')->group(function (): void {
    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('me', [AuthController::class, 'me']);
        Route::post('logout', [AuthController::class, 'logout']);
    });
});

Route::prefix('v1')->middleware(['auth:sanctum', 'tenant', 'throttle:120,1'])->group(function (): void {
    Route::get('dashboard', DashboardController::class);
    Route::get('calendar', CalendarController::class);

    Route::apiResource('guests', GuestController::class);
    Route::apiResource('resources', ResourceController::class);
    Route::apiResource('reservations', ReservationController::class)->except(['destroy', 'store']);
    Route::post('reservations', [ReservationController::class, 'store'])->middleware('idempotent');
    Route::post('reservations/{reservation}/confirm', [ReservationController::class, 'confirm'])->middleware('idempotent');
    Route::post('reservations/{reservation}/transition', [ReservationController::class, 'transition']);
    Route::apiResource('tasks', TaskController::class)->parameters(['tasks' => 'task']);
    Route::apiResource('payments', PaymentController::class)->only(['index', 'show']);
    Route::post('payments', [PaymentController::class, 'store'])->middleware('idempotent');
});
