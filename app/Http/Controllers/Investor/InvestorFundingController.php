<?php

namespace App\Http\Controllers\Investor;

use App\Http\Controllers\Controller;
use App\Models\Fund;
use App\Models\Investor;
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

        $data = $request->validate([
            'amount' => ['nullable', 'numeric', 'min:0.01'],
        ]);

        $allowParallel = (bool) \App\Models\Setting::singleton()->allow_parallel_onboarding;
        $allowedStatuses = ['awaiting_funding', 'funds_sent', 'funds_confirmed', 'active'];

        if (! $allowParallel && ! in_array($investor->investment_status, $allowedStatuses, true)) {
            return response()->json([
                'message' => 'Funding is not yet released for this investor. Complete the previous steps first.',
            ], 409);
        }

        // Demo mode answers before any Stripe call, so the flow works on an
        // environment with no payment credentials configured at all.
        if ($this->demoPaymentsEnabled()) {
            $amount = $this->resolveAmount($investor, $data['amount'] ?? null);

            if ($amount === null) {
                return response()->json([
                    'message' => 'No amount to fund. Contact support.',
                ], 422);
            }

            return response()->json([
                'mode' => 'demo',
                'amount' => $amount,
                'currency' => 'usd',
                'status' => 'released',
            ]);
        }

        // An explicit amount means an additional subscription on top of an
        // existing position. That path must never reuse the onboarding
        // instruction, so it is handled separately.
        if (($data['amount'] ?? null) !== null) {
            return $this->additionalSubscriptionIntent($investor, (float) $data['amount']);
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
     * Settle a payment without Stripe. Only reachable while demo mode is on.
     */
    public function simulate(Request $request): JsonResponse
    {
        $investor = $request->user();

        if (! $this->demoPaymentsEnabled()) {
            return response()->json([
                'message' => 'Demo payments are not enabled.',
            ], 403);
        }

        $data = $request->validate([
            'amount' => ['nullable', 'numeric', 'min:0.01'],
        ]);

        $amount = $this->resolveAmount($investor, $data['amount'] ?? null);

        if ($amount === null) {
            return response()->json(['message' => 'No amount to fund.'], 422);
        }

        // The fund minimum still applies — a demo that skips validation would
        // not demonstrate the real constraints.
        if (($data['amount'] ?? null) !== null) {
            $fund = $investor->fund_id ? Fund::find($investor->fund_id) : null;
            $minimum = $fund?->minimum_investment !== null ? (float) $fund->minimum_investment : null;

            if ($minimum !== null && $amount < $minimum) {
                return response()->json([
                    'message' => sprintf('The minimum additional investment for this fund is $%s.', number_format($minimum, 2)),
                ], 422);
            }
        }

        try {
            $this->processing->simulateFundingPayment($investor, $amount, 'Investor completed a demo payment.');
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'mode' => 'demo',
            'status' => 'succeeded',
            'amount' => $amount,
        ]);
    }

    /**
     * Amount for this funding action: an explicit top-up amount, otherwise the
     * investor's original commitment.
     */
    private function resolveAmount(Investor $investor, $requested): ?float
    {
        $amount = $requested !== null
            ? (float) $requested
            : (float) $investor->investment_commitment;

        return $amount > 0 ? $amount : null;
    }

    private function demoPaymentsEnabled(): bool
    {
        return (bool) \App\Models\Setting::singleton()->demo_payments_enabled;
    }

    /**
     * Prepare a PaymentIntent for capital added to an existing position.
     *
     * The onboarding path above returns whatever instruction the investor
     * already has, which is the correct protection against paying the same
     * subscription twice. That same reuse made a second subscription
     * impossible: it handed back the completed intent, and Stripe rejects a
     * confirm on an intent that already succeeded. Here a fresh intent is
     * minted instead, and only a still-unpaid one of the same amount is reused.
     */
    private function additionalSubscriptionIntent(Investor $investor, float $amount): JsonResponse
    {
        if ($investor->investment_status !== 'active') {
            return response()->json([
                'message' => 'Additional subscriptions are only available once your initial investment is active.',
            ], 409);
        }

        $fund = $investor->fund_id ? Fund::find($investor->fund_id) : null;

        if (! $fund) {
            return response()->json([
                'message' => 'Your account is not linked to a fund. Contact support.',
            ], 422);
        }

        // Enforced here rather than in the validator because the floor belongs
        // to the fund, not the request.
        $minimum = $fund->minimum_investment !== null ? (float) $fund->minimum_investment : null;

        if ($minimum !== null && $amount < $minimum) {
            return response()->json([
                'message' => sprintf('The minimum additional investment for this fund is $%s.', number_format($minimum, 2)),
            ], 422);
        }

        $latest = $this->processing->activeFundingInstruction($investor);

        // One ACH at a time. Allowing a second while the first is still
        // settling would let the investor commit money twice over.
        if ($latest && $latest->status === 'processing') {
            return response()->json([
                'message' => 'A previous payment is still settling. You can invest again once it clears.',
            ], 409);
        }

        $amountCents = (int) round($amount * 100);
        $reusable = $latest
            && $latest->status === 'released'
            && $latest->provider_client_secret
            && (int) $latest->amount_cents === $amountCents;

        if (! $reusable) {
            $this->processing->releaseFundingInstructions($investor, $amount);
            $latest = $this->processing->activeFundingInstruction($investor->fresh());
        }

        if (! $latest || ! $latest->provider_client_secret) {
            return response()->json([
                'message' => 'Could not prepare a Payment Intent. Contact support.',
            ], 500);
        }

        return response()->json([
            'publishableKey' => $this->stripe->publishableKey(),
            'clientSecret' => $latest->provider_client_secret,
            'paymentIntentId' => $latest->provider_intent_id,
            'amount' => $latest->amount_cents ? $latest->amount_cents / 100 : null,
            'currency' => $latest->currency,
            'status' => $latest->status,
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
