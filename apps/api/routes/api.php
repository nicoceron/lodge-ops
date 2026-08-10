<?php

use App\Http\Controllers\Api\V1\AuthController;
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
use App\Http\Controllers\Api\V1\ProposalController;
use App\Http\Controllers\Api\V1\ReservationController;
use App\Http\Controllers\Api\V1\ResourceController;
use App\Http\Controllers\Api\V1\ResourceSuggestionController;
use App\Http\Controllers\Api\V1\TaskController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/auth')->group(function (): void {
    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('me', [AuthController::class, 'me']);
        Route::post('logout', [AuthController::class, 'logout']);
    });
});

Route::prefix('v1/guest-portal')->group(function (): void {
    Route::post('exchange', [GuestPortalController::class, 'exchange'])->middleware('throttle:10,1');

    Route::middleware(['guest.portal', 'throttle:120,1'])->group(function (): void {
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

    Route::apiResource('guests', GuestController::class);
    Route::get('resources/suggestions', ResourceSuggestionController::class);
    Route::apiResource('resources', ResourceController::class);
    Route::apiResource('reservations', ReservationController::class)->except(['destroy', 'store']);
    Route::post('reservations', [ReservationController::class, 'store'])->middleware('idempotent');
    Route::post('reservations/{reservation}/confirm', [ReservationController::class, 'confirm'])->middleware('idempotent');
    Route::post('reservations/{reservation}/transition', [ReservationController::class, 'transition']);
    Route::get('reservations/{reservation}/folio', [FolioController::class, 'index']);
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
