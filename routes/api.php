<?php

use App\Http\Controllers\Admin\AdminCommunicationController;
use App\Http\Controllers\Admin\AdminFundController;
use App\Http\Controllers\Admin\AgreementController;
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\EmailLogController;
use App\Http\Controllers\Admin\InvestorController;
use App\Http\Controllers\Admin\InvestorIntegrationController;
use App\Http\Controllers\Admin\InvestorOverrideController;
use App\Http\Controllers\Admin\InvestorProcessingController;
use App\Http\Controllers\Admin\KycController;
use App\Http\Controllers\Admin\ReportsController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Investor\InvestorAuthController;
use App\Http\Controllers\Investor\InvestorFundingController;
use App\Http\Controllers\Investor\InvestorPersonaController;
use App\Http\Controllers\Investor\InvestorPortalCommunicationsController;
use App\Http\Controllers\Investor\InvestorPortalDocumentsController;
use App\Http\Controllers\Investor\InvestorPortalInvestmentController;
use App\Http\Controllers\Investor\InvestorPortalProfileController;
use App\Http\Controllers\Investor\InvestReadyController;
use App\Http\Controllers\Public\InvestorRegistrationController;
use App\Http\Controllers\Webhooks\DocuSignWebhookController;
use App\Http\Controllers\Webhooks\InvestReadyWebhookController;
use App\Http\Controllers\Webhooks\PersonaWebhookController;
use App\Http\Controllers\Webhooks\StripeWebhookController;
use Illuminate\Support\Facades\Route;

Route::prefix('investors')->middleware('throttle:10,1')->group(function () {
    Route::post('/register', [InvestorRegistrationController::class, 'store']);
});

Route::post('/webhooks/persona', PersonaWebhookController::class);
Route::post('/webhooks/docusign', DocuSignWebhookController::class);
Route::post('/webhooks/investready', InvestReadyWebhookController::class);
Route::post('/webhooks/stripe', StripeWebhookController::class);

Route::prefix('investor')->group(function () {
    Route::post('/login', [InvestorAuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [InvestorAuthController::class, 'logout']);
        Route::get('/me', [InvestorAuthController::class, 'me']);
        Route::post('/persona/start', [InvestorPersonaController::class, 'start']);
        Route::post('/persona/complete', [InvestorPersonaController::class, 'complete']);
        Route::post('/investready/start', [InvestReadyController::class, 'start']);
        Route::post('/investready/exchange', [InvestReadyController::class, 'exchange']);
        Route::post('/funding/payment-intent', [InvestorFundingController::class, 'paymentIntent']);
        Route::get('/funding/status', [InvestorFundingController::class, 'status']);

        Route::get('/portal/profile', [InvestorPortalProfileController::class, 'show']);
        Route::patch('/portal/profile', [InvestorPortalProfileController::class, 'update']);

        Route::get('/portal/portfolio', [InvestorPortalInvestmentController::class, 'portfolio']);
        Route::get('/portal/holdings', [InvestorPortalInvestmentController::class, 'holdings']);
        Route::get('/portal/holdings/{fundCode}/performance', [InvestorPortalInvestmentController::class, 'performance']);
        Route::get('/portal/holdings/{fundCode}/price-history', [InvestorPortalInvestmentController::class, 'priceHistory']);
        Route::get('/portal/holdings/{fundCode}/distributions', [InvestorPortalInvestmentController::class, 'distributions']);
        Route::get('/portal/holdings/{fundCode}/fees', [InvestorPortalInvestmentController::class, 'fees']);

        Route::get('/portal/documents', [InvestorPortalDocumentsController::class, 'index']);
        Route::get('/portal/documents/{id}/download', [InvestorPortalDocumentsController::class, 'download']);

        Route::get('/portal/communications', [InvestorPortalCommunicationsController::class, 'index']);
        Route::get('/portal/communications/{id}', [InvestorPortalCommunicationsController::class, 'show']);
    });
});

Route::prefix('admin')->group(function () {
    Route::get('/ping', fn () => response()->json([
        'message' => 'pong',
        'timestamp' => now()->toIso8601String(),
    ]));

    Route::post('/login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);

        Route::get('/dashboard', [DashboardController::class, 'index']);
        Route::get('/reports', [ReportsController::class, 'index']);

        Route::get('/investors', [InvestorController::class, 'index']);
        Route::get('/investors/{code}', [InvestorController::class, 'show']);
        Route::patch('/investors/{code}/statuses', [InvestorController::class, 'updateStatuses']);
        Route::get('/investors/{code}/investready', [InvestorIntegrationController::class, 'showInvestReady']);
        Route::post('/investors/{code}/investready/resync', [InvestorIntegrationController::class, 'resyncInvestReady']);
        Route::get('/investors/{code}/stripe', [InvestorIntegrationController::class, 'showStripe']);

        // Fund management (Phase 5A)
        Route::get('/funds', [AdminFundController::class, 'index']);
        Route::post('/funds', [AdminFundController::class, 'store']);
        Route::get('/funds/{code}', [AdminFundController::class, 'show']);
        Route::patch('/funds/{code}', [AdminFundController::class, 'update']);
        Route::post('/funds/{code}/unit-prices', [AdminFundController::class, 'storeUnitPrice']);
        Route::delete('/unit-prices/{id}', [AdminFundController::class, 'destroyUnitPrice']);
        Route::post('/funds/{code}/distributions', [AdminFundController::class, 'declareDistribution']);
        Route::delete('/distributions/{id}', [AdminFundController::class, 'destroyDistribution']);
        Route::post('/funds/{code}/fees', [AdminFundController::class, 'declareFee']);
        Route::delete('/fees/{id}', [AdminFundController::class, 'destroyFee']);

        // Communications (Phase 4 admin)
        Route::get('/communications', [AdminCommunicationController::class, 'index']);
        Route::post('/communications', [AdminCommunicationController::class, 'store']);
        Route::patch('/communications/{id}', [AdminCommunicationController::class, 'update']);
        Route::delete('/communications/{id}', [AdminCommunicationController::class, 'destroy']);
        Route::post('/investors/{code}/persona/complete', [InvestorProcessingController::class, 'recordPersonaCompletion']);
        Route::post('/investors/{code}/processing/{action}', [InvestorProcessingController::class, 'handle']);
        Route::post('/investors/{code}/override/{action}', [InvestorOverrideController::class, 'handle']);

        Route::get('/kyc-verification', [KycController::class, 'index']);
        Route::post('/kyc-verification/{code}/review', [KycController::class, 'review']);

        Route::get('/email-logs', [EmailLogController::class, 'index']);

        Route::get('/settings', [SettingsController::class, 'show']);
        Route::put('/settings', [SettingsController::class, 'update']);

        Route::get('/investors/{code}/agreements', [AgreementController::class, 'index']);
        Route::post('/investors/{code}/agreements/send', [AgreementController::class, 'send']);
        Route::post('/agreements/{envelopeId}/resend', [AgreementController::class, 'resend']);
        Route::post('/agreements/{envelopeId}/void', [AgreementController::class, 'void']);
        Route::get('/agreements/{envelopeId}/download', [AgreementController::class, 'download']);
    });
});
