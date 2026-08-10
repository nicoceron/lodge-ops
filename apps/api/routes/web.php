<?php

use App\Http\Controllers\Api\V1\AuthController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::post('/api/v1/auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
Route::post('/api/v1/auth/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:5,1');
Route::post('/api/v1/auth/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:5,1');
