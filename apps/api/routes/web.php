<?php

use App\Http\Controllers\GuestGeneratedDocumentDownloadController;
use App\Http\Controllers\PaymentLinkController;
use App\Http\Controllers\Web\GuestPortalController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/manage');

Route::prefix('pay')->middleware('throttle:payment-request-link')->group(function (): void {
    Route::get('{token}', [PaymentLinkController::class, 'show'])->where('token', '[A-Za-z0-9]{64}')->name('payment-link.show');
    Route::post('{token}/checkout', [PaymentLinkController::class, 'checkout'])->where('token', '[A-Za-z0-9]{64}')->name('payment-link.checkout');
    Route::get('return/{externalReference}', [PaymentLinkController::class, 'returned'])->whereUuid('externalReference')->name('payment-link.return');
});

Route::prefix('guest')->name('guest.portal.')->group(function (): void {
    Route::get('access/{token}', [GuestPortalController::class, 'access'])
        ->middleware('throttle:guest-link')
        ->name('access');
    Route::get('unavailable', [GuestPortalController::class, 'unavailable'])->name('unavailable');

    Route::prefix('stay')->middleware(['guest.portal', 'throttle:guest-web'])->group(function (): void {
        Route::get('/', [GuestPortalController::class, 'home'])->name('home');
        Route::get('pre-arrival', [GuestPortalController::class, 'preArrival'])->name('pre-arrival');
        Route::put('pre-arrival', [GuestPortalController::class, 'updatePreArrival'])->name('pre-arrival.update');
        Route::get('documents', [GuestPortalController::class, 'documents'])->name('documents');
        Route::post('documents', [GuestPortalController::class, 'acknowledge'])->name('documents.acknowledge');
        Route::get('generated-documents/{generatedDocument}/download', GuestGeneratedDocumentDownloadController::class)->name('generated-documents.download');
        Route::get('payments', [GuestPortalController::class, 'payments'])->name('payments');
        Route::post('payments', [GuestPortalController::class, 'storePaymentEvidence'])->name('payments.store');
        Route::get('folio', [GuestPortalController::class, 'folio'])->name('folio');
        Route::get('survey', [GuestPortalController::class, 'survey'])->name('survey');
        Route::post('survey', [GuestPortalController::class, 'storeSurvey'])->name('survey.store');
        Route::post('logout', [GuestPortalController::class, 'logout'])->name('logout');
    });
});
