<?php

use App\Http\Controllers\Admin\AgreementController;
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\EmailLogController;
use App\Http\Controllers\Admin\InvestorController;
use App\Http\Controllers\Admin\InvestorProcessingController;
use App\Http\Controllers\Admin\KycController;
use App\Http\Controllers\Admin\ReportsController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Investor\InvestorAuthController;
use App\Http\Controllers\Investor\InvestorPersonaController;
use App\Http\Controllers\Public\InvestorRegistrationController;
use App\Http\Controllers\Webhooks\DocuSignWebhookController;
use App\Http\Controllers\Webhooks\PersonaWebhookController;
use Illuminate\Support\Facades\Route;

Route::prefix('investors')->middleware('throttle:10,1')->group(function () {
    Route::post('/register', [InvestorRegistrationController::class, 'store']);
});

Route::post('/webhooks/persona', PersonaWebhookController::class);
Route::post('/webhooks/docusign', DocuSignWebhookController::class);

Route::prefix('investor')->group(function () {
    Route::post('/login', [InvestorAuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [InvestorAuthController::class, 'logout']);
        Route::get('/me', [InvestorAuthController::class, 'me']);
        Route::post('/persona/start', [InvestorPersonaController::class, 'start']);
        Route::post('/persona/complete', [InvestorPersonaController::class, 'complete']);
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
        Route::post('/investors/{code}/persona/complete', [InvestorProcessingController::class, 'recordPersonaCompletion']);
        Route::post('/investors/{code}/processing/{action}', [InvestorProcessingController::class, 'handle']);

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
