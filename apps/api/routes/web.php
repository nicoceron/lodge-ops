<?php

use App\Http\Controllers\GuestGeneratedDocumentDownloadController;
use App\Http\Controllers\PaymentLinkController;
use App\Http\Controllers\Web\DirectBookingWebController;
use App\Http\Controllers\Web\GuestPortalController;
use App\Http\Middleware\AddDirectBookingSecurityHeaders;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/manage');

Route::prefix('book/{propertySlug}')
    ->where(['propertySlug' => '[a-z0-9]+(?:-[a-z0-9]+)*'])
    ->middleware([AddDirectBookingSecurityHeaders::class, 'throttle:direct-booking-read'])
    ->name('direct-booking.')
    ->group(function (): void {
        Route::get('/', [DirectBookingWebController::class, 'show'])->name('show');
        Route::post('quote', [DirectBookingWebController::class, 'quote'])
            ->middleware('throttle:direct-booking-mutation')->name('quote');
        Route::get('unavailable', [DirectBookingWebController::class, 'unavailable'])->name('unavailable');
        Route::get('orders/{orderReference}/review', [DirectBookingWebController::class, 'review'])
            ->where('orderReference', '[0-9A-HJKMNP-TV-Z]{26}')->name('review');
        Route::post('orders/{orderReference}/hold', [DirectBookingWebController::class, 'hold'])
            ->where('orderReference', '[0-9A-HJKMNP-TV-Z]{26}')
            ->middleware(['throttle:direct-booking-mutation', 'throttle:direct-booking-hold'])->name('hold');
        Route::get('orders/{orderReference}/status', [DirectBookingWebController::class, 'status'])
            ->where('orderReference', '[0-9A-HJKMNP-TV-Z]{26}')->name('status');
        Route::get('orders/{orderReference}/poll', [DirectBookingWebController::class, 'poll'])
            ->where('orderReference', '[0-9A-HJKMNP-TV-Z]{26}')->name('poll');
        Route::get('orders/{orderReference}/documents/{documentReference}', [DirectBookingWebController::class, 'document'])
            ->where([
                'orderReference' => '[0-9A-HJKMNP-TV-Z]{26}',
                'documentReference' => '[a-f0-9]{64}',
            ])->name('document');
        Route::post('orders/{orderReference}/checkout', [DirectBookingWebController::class, 'checkout'])
            ->where('orderReference', '[0-9A-HJKMNP-TV-Z]{26}')
            ->middleware('throttle:direct-booking-mutation')->name('checkout');
        Route::post('orders/{orderReference}/payments/retry', [DirectBookingWebController::class, 'retryPayment'])
            ->where('orderReference', '[0-9A-HJKMNP-TV-Z]{26}')
            ->middleware('throttle:direct-booking-mutation')->name('retry-payment');
        Route::post('orders/{orderReference}/manual-payment-evidence', [DirectBookingWebController::class, 'evidence'])
            ->where('orderReference', '[0-9A-HJKMNP-TV-Z]{26}')
            ->middleware('throttle:direct-booking-mutation')->name('evidence');
        Route::post('orders/{orderReference}/recover', [DirectBookingWebController::class, 'recover'])
            ->where('orderReference', '[0-9A-HJKMNP-TV-Z]{26}')
            ->middleware('throttle:direct-booking-mutation')->name('recover');
        Route::post('analytics', [DirectBookingWebController::class, 'analytics'])
            ->middleware('throttle:60,1')->name('analytics');
    });

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
