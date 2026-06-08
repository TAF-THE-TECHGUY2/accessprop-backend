<?php

namespace App\Http\Controllers\Investor;

use App\Http\Controllers\Controller;
use App\Services\Integrations\StripeClient;
use App\Services\InvestorProcessingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InvestorFundingController extends Controller
{
    public function __construct(
        private readonly InvestorProcessingService $processing,
        private readonly StripeClient $stripe,
    ) {
    }

    /**
     * Return the Stripe PaymentIntent client_secret the React app needs to
     * launch the Financial Connections flow. Creates one on demand if the
     * investor doesn't have an active one yet.
     */
    public function paymentIntent(Request $request): JsonResponse
    {
        $investor = $request->user();

        $allowParallel = (bool) \App\Models\Setting::singleton()->allow_parallel_onboarding;
        $allowedStatuses = ['awaiting_funding', 'funds_sent', 'funds_confirmed', 'active'];

        if (! $allowParallel && ! in_array($investor->investment_status, $allowedStatuses, true)) {
            return response()->json([
                'message' => 'Funding is not yet released for this investor. Complete the previous steps first.',
            ], 409);
        }

        $instruction = $this->processing->activeFundingInstruction($investor);

        // No active Stripe instruction? Create one on demand.
        if (! $instruction || $instruction->provider !== 'stripe') {
            $this->processing->releaseFundingInstructions($investor);
            $instruction = $this->processing->activeFundingInstruction($investor->fresh());
        }

        if (! $instruction || ! $instruction->provider_client_secret) {
            return response()->json([
                'message' => 'Could not prepare a Payment Intent. Contact support.',
            ], 500);
        }

        // Self-heal: pull the latest status from Stripe (covers the case where
        // a webhook didn't fire — e.g. local dev without the Stripe CLI listener).
        $instruction = $this->processing->syncFundingInstructionFromStripe($instruction);

        return response()->json([
            'publishableKey' => $this->stripe->publishableKey(),
            'clientSecret' => $instruction->provider_client_secret,
            'paymentIntentId' => $instruction->provider_intent_id,
            'amount' => $instruction->amount_cents ? $instruction->amount_cents / 100 : null,
            'currency' => $instruction->currency,
            'status' => $instruction->status,
        ]);
    }

    /**
     * Returns the investor's current funding status (used by the dashboard
     * to poll after the Stripe modal closes).
     */
    public function status(Request $request): JsonResponse
    {
        $investor = $request->user();
        $instruction = $this->processing->activeFundingInstruction($investor);

        return response()->json([
            'investmentStatus' => $investor->investment_status,
            'walletStatus' => $investor->investment_wallet_status,
            'funding' => $instruction ? [
                'status' => $instruction->status,
                'lastEvent' => data_get($instruction->provider_payload, 'last_event'),
                'paymentIntentId' => $instruction->provider_intent_id,
            ] : null,
        ]);
    }
}
