<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Services\Integrations\StripeClient;
use App\Services\InvestorProcessingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use UnexpectedValueException;

class StripeWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        StripeClient $stripe,
        InvestorProcessingService $processing,
    ): JsonResponse {
        $rawBody = $request->getContent();
        $signature = $request->header('Stripe-Signature', '');

        try {
            $event = $stripe->constructWebhookEvent($rawBody, $signature);
        } catch (UnexpectedValueException $e) {
            Log::warning('Stripe webhook signature invalid', ['error' => $e->getMessage()]);

            return response()->json(['message' => 'Invalid signature'], 401);
        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            Log::warning('Stripe webhook signature verification failed');

            return response()->json(['message' => 'Invalid signature'], 401);
        }

        // We only handle Payment Intent events for funding.
        if (! str_starts_with($event->type, 'payment_intent.')) {
            return response()->json(['message' => 'Ignored: '.$event->type]);
        }

        $intent = $event->data->object ?? null;
        if (! $intent || ! isset($intent->id)) {
            return response()->json(['message' => 'No payment_intent in payload'], 422);
        }

        try {
            $processing->applyStripePaymentIntentEvent(
                $intent->id,
                $event->type,
                $intent->toArray(),
            );
        } catch (\Throwable $e) {
            Log::error('Stripe webhook handler failed', [
                'event' => $event->type,
                'intent' => $intent->id,
                'error' => $e->getMessage(),
            ]);
            // Return 200 anyway so Stripe doesn't retry forever on bad state;
            // the failure is logged for investigation.
            return response()->json(['message' => 'Handler error, logged']);
        }

        return response()->json(['message' => 'ok']);
    }
}
