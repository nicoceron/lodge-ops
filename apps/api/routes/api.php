<?php

use App\Http\Controllers\Api\V1\AllocationController;
use App\Http\Controllers\Api\V1\BookingQuoteController;
use App\Http\Controllers\Api\V1\CalendarController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\DepositController;
use App\Http\Controllers\Api\V1\ExtendedOperationsController;
use App\Http\Controllers\Api\V1\FinanceProjectionController;
use App\Http\Controllers\Api\V1\FolioController;
use App\Http\Controllers\Api\V1\GuestController;
use App\Http\Controllers\Api\V1\GuestPortalController;
use App\Http\Controllers\Api\V1\OperationsProjectionController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\ProgramController;
use App\Http\Controllers\Api\V1\PropertyController;
use App\Http\Controllers\Api\V1\ProposalController;
use App\Http\Controllers\Api\V1\ReservationChangeController;
use App\Http\Controllers\Api\V1\ReservationController;
use App\Http\Controllers\Api\V1\ReservationNoteController;
use App\Http\Controllers\Api\V1\ResourceBlockController;
use App\Http\Controllers\Api\V1\ResourceController;
use App\Http\Controllers\Api\V1\ResourceSuggestionController;
use App\Http\Controllers\Api\V1\ServiceOccurrenceController;
use App\Http\Controllers\Api\V1\TaskController;
use App\Http\Controllers\CalendarFeedController;
use Illuminate\Support\Facades\Route;

Route::get('v1/calendar-feeds/{token}.ics', CalendarFeedController::class)
    ->where('token', '[A-Za-z0-9]{64}')
    ->middleware('throttle:60,1')
    ->name('calendar-feeds.show');

Route::prefix('v1/guest-portal')->group(function (): void {
    Route::post('exchange', [GuestPortalController::class, 'exchange'])->middleware('throttle:guest-exchange');

    Route::middleware(['guest.portal', 'throttle:guest-api'])->group(function (): void {
        Route::get('reservation', [GuestPortalController::class, 'show']);
        Route::put('pre-arrival', [GuestPortalController::class, 'updatePreArrival']);
        Route::post('waiver', [GuestPortalController::class, 'acknowledge']);
        Route::post('payment-evidence', [GuestPortalController::class, 'storePaymentEvidence']);
        Route::get('folio', [GuestPortalController::class, 'folio']);
        Route::post('survey', [GuestPortalController::class, 'storeSurvey']);
    });
});

Route::prefix('v1')->middleware(['auth:sanctum', 'tenant', 'throttle:120,1'])->group(function (): void {
    Route::get('dashboard', DashboardController::class);
    Route::get('calendar', CalendarController::class);
    Route::get('operations', OperationsProjectionController::class);
    Route::get('finance', FinanceProjectionController::class);

    Route::get('properties', [PropertyController::class, 'index']);
    Route::apiResource('programs', ProgramController::class)->only(['index', 'show']);
    Route::get('guests/{guest}/history', [GuestController::class, 'history']);
    Route::apiResource('guests', GuestController::class);
    Route::get('resources/suggestions', ResourceSuggestionController::class);
    Route::apiResource('resources', ResourceController::class);
    Route::post('booking-quotes', [BookingQuoteController::class, 'store'])->middleware('idempotent');
    Route::patch('resources/{resource}/housekeeping', [ResourceController::class, 'updateHousekeeping']);
    Route::apiResource('reservations', ReservationController::class)->except(['destroy', 'store']);
    Route::post('reservations', [ReservationController::class, 'store'])->middleware('idempotent');
    Route::post('reservations/{reservation}/confirm', [ReservationController::class, 'confirm'])->middleware('idempotent');
    Route::post('reservations/{reservation}/transition', [ReservationController::class, 'transition']);
    Route::get('reservations/{reservation}/changes', [ReservationChangeController::class, 'index']);
    Route::post('reservations/{reservation}/amend', [ReservationChangeController::class, 'amend'])->middleware('idempotent');
    Route::post('reservations/{reservation}/reallocate', [ReservationChangeController::class, 'reallocate'])->middleware('idempotent');
    Route::post('reservations/{reservation}/cancel', [ReservationChangeController::class, 'cancel'])->middleware('idempotent');
    Route::post('reservations/{reservation}/no-show', [ReservationChangeController::class, 'noShow'])->middleware('idempotent');
    Route::post('reservations/{reservation}/refunds', [ReservationChangeController::class, 'requestRefund'])->middleware('idempotent');
    Route::post('reservations/{reservation}/refunds/{refund}/complete', [ReservationChangeController::class, 'completeRefund'])->middleware('idempotent');
    Route::post('reservations/{reservation}/refunds/{refund}/fail', [ReservationChangeController::class, 'failRefund'])->middleware('idempotent');
    Route::get('reservations/{reservation}/notes', [ReservationNoteController::class, 'index']);
    Route::post('reservations/{reservation}/notes', [ReservationNoteController::class, 'store'])->middleware('idempotent');
    Route::post('reservations/{reservation}/allocations', [AllocationController::class, 'store'])->middleware('idempotent');
    Route::put('reservations/{reservation}/allocations/{allocation}', [AllocationController::class, 'update']);
    Route::delete('reservations/{reservation}/allocations/{allocation}', [AllocationController::class, 'destroy']);
    Route::apiResource('service-occurrences', ServiceOccurrenceController::class)->except(['store']);
    Route::post('service-occurrences', [ServiceOccurrenceController::class, 'store'])->middleware('idempotent');
    Route::apiResource('resource-blocks', ResourceBlockController::class)->except(['store']);
    Route::post('resource-blocks', [ResourceBlockController::class, 'store'])->middleware('idempotent');
    Route::get('reservations/{reservation}/folio', [FolioController::class, 'index']);
    Route::post('reservations/{reservation}/folio/close', [FolioController::class, 'close'])->middleware('idempotent');
    Route::post('reservations/{reservation}/folio/reopen', [FolioController::class, 'reopen'])->middleware('idempotent');
    Route::post('reservations/{reservation}/folio-lines', [FolioController::class, 'store'])->middleware('idempotent');
    Route::post('folio-lines/{folioLine}/reverse', [FolioController::class, 'reverse'])->middleware('idempotent');
    Route::apiResource('proposals', ProposalController::class)->except(['destroy']);
    Route::post('proposals/{proposal}/send', [ProposalController::class, 'send'])->middleware('idempotent');
    Route::post('proposals/{proposal}/revise', [ProposalController::class, 'revise'])->middleware('idempotent');
    Route::post('proposals/{proposal}/convert', [ProposalController::class, 'convert'])->middleware('idempotent');
    Route::apiResource('tasks', TaskController::class)->parameters(['tasks' => 'task']);
    Route::apiResource('payments', PaymentController::class)->only(['index', 'show']);
    Route::post('payments', [PaymentController::class, 'store'])->middleware('idempotent');
    Route::post('payments/{payment}/reconcile', [PaymentController::class, 'reconcile'])->middleware('idempotent');
    Route::post('payments/{payment}/reverse', [PaymentController::class, 'reverse'])->middleware('idempotent');
    Route::apiResource('deposits', DepositController::class)->only(['index', 'store', 'show']);
    Route::post('deposits/{deposit}/waive', [DepositController::class, 'waive'])->middleware('idempotent');

    Route::get('catalog', [ExtendedOperationsController::class, 'catalog']);
    Route::post('catalog', [ExtendedOperationsController::class, 'storeCatalog'])->middleware('idempotent');
    Route::post('stock-receipts', [ExtendedOperationsController::class, 'receiveStock'])->middleware('idempotent');
    Route::post('retail-sales', [ExtendedOperationsController::class, 'postSale'])->middleware('idempotent');
    Route::get('financial-summary', [ExtendedOperationsController::class, 'finance']);
    Route::post('costs', [ExtendedOperationsController::class, 'storeCost'])->middleware('idempotent');
    Route::get('integrations', [ExtendedOperationsController::class, 'integrations']);
    Route::put('integrations', [ExtendedOperationsController::class, 'configureIntegration']);
    Route::get('organizations', [ExtendedOperationsController::class, 'organizations']);
    Route::post('organizations', [ExtendedOperationsController::class, 'storeOrganization'])->middleware('idempotent');
    Route::get('opportunities', [ExtendedOperationsController::class, 'opportunities']);
    Route::post('opportunities', [ExtendedOperationsController::class, 'storeOpportunity'])->middleware('idempotent');
    Route::post('opportunities/{opportunity}/transition', [ExtendedOperationsController::class, 'transitionOpportunity'])->middleware('idempotent');
    Route::post('guests/{guest}/merge', [ExtendedOperationsController::class, 'mergeGuest'])->middleware('idempotent');
});
