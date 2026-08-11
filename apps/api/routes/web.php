<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Web\GuestPortalController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/manage');

Route::post('/api/v1/auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
Route::post('/api/v1/auth/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:5,1');
Route::post('/api/v1/auth/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:5,1');

Route::prefix('guest')->name('guest.portal.')->group(function (): void {
    Route::get('access/{token}', [GuestPortalController::class, 'access'])
        ->middleware('throttle:10,1')
        ->name('access');
    Route::get('unavailable', [GuestPortalController::class, 'unavailable'])->name('unavailable');

    Route::prefix('stay')->middleware(['guest.portal', 'throttle:120,1'])->group(function (): void {
        Route::get('/', [GuestPortalController::class, 'home'])->name('home');
        Route::get('pre-arrival', [GuestPortalController::class, 'preArrival'])->name('pre-arrival');
        Route::put('pre-arrival', [GuestPortalController::class, 'updatePreArrival'])->name('pre-arrival.update');
        Route::get('documents', [GuestPortalController::class, 'documents'])->name('documents');
        Route::post('documents', [GuestPortalController::class, 'acknowledge'])->name('documents.acknowledge');
        Route::get('payments', [GuestPortalController::class, 'payments'])->name('payments');
        Route::post('payments', [GuestPortalController::class, 'storePaymentEvidence'])->name('payments.store');
        Route::get('folio', [GuestPortalController::class, 'folio'])->name('folio');
        Route::get('survey', [GuestPortalController::class, 'survey'])->name('survey');
        Route::post('survey', [GuestPortalController::class, 'storeSurvey'])->name('survey.store');
        Route::post('logout', [GuestPortalController::class, 'logout'])->name('logout');
    });
});
