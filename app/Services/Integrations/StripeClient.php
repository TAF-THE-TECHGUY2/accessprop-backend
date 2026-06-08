<?php

namespace App\Services\Integrations;

use App\Models\Investor;
use Stripe\PaymentIntent;
use Stripe\Stripe;
use Stripe\Webhook;
use UnexpectedValueException;

/**
 * Thin wrapper around stripe/stripe-php that:
 *   - Creates ACH Payment Intents with Financial Connections enabled
 *   - Verifies webhook signatures
 *
 * Test cards: see https://stripe.com/docs/testing
 * For Financial Connections in test mode use the "00000000" / "000000000"
 * test bank in the hosted bank picker.
 */
class StripeClient
{
    public function __construct(
        private readonly ?string $secretKey,
        private readonly ?string $webhookSecret,
        private readonly string $publishableKey,
        private readonly string $dashboardUrl,
        private readonly bool $sandbox,
    ) {
        if ($this->secretKey) {
            Stripe::setApiKey($this->secretKey);
            Stripe::setApiVersion('2026-05-27.dahlia');
        }
    }

    public static function fromConfig(): self
    {
        return new self(
            secretKey: config('services.stripe.secret_key'),
            webhookSecret: config('services.stripe.webhook_secret'),
            publishableKey: (string) config('services.stripe.publishable_key'),
            dashboardUrl: rtrim((string) config('services.stripe.dashboard_url'), '/'),
            sandbox: (bool) config('services.stripe.sandbox', true),
        );
    }

    public function isConfigured(): bool
    {
        return ! empty($this->secretKey) && ! empty($this->publishableKey);
    }

    public function publishableKey(): string
    {
        return $this->publishableKey;
    }

    public function dashboardUrlForIntent(string $intentId): string
    {
        return $this->dashboardUrl.'/payments/'.$intentId;
    }

    /**
     * Create a PaymentIntent for an ACH debit via Stripe Financial Connections.
     * The investor will link their bank account through Stripe's hosted UI,
     * then the PI is confirmed and settles via ACH (3-5 business days).
     */
    public function createAchPaymentIntent(Investor $investor, int $amountCents): PaymentIntent
    {
        return PaymentIntent::create([
            'amount' => $amountCents,
            'currency' => 'usd',
            'payment_method_types' => ['us_bank_account'],
            'payment_method_options' => [
                'us_bank_account' => [
                    'financial_connections' => [
                        'permissions' => ['payment_method', 'balances'],
                    ],
                    'verification_method' => 'instant',
                ],
            ],
            'description' => sprintf(
                'Access Properties — %s subscription (%s)',
                $investor->investment_fund_name ?? 'Fund I',
                $investor->code,
            ),
            'metadata' => [
                'investor_code' => $investor->code,
                'investor_id' => (string) $investor->id,
                'fund_name' => $investor->investment_fund_name ?? 'Fund I',
            ],
            'receipt_email' => $investor->email,
        ]);
    }

    public function retrievePaymentIntent(string $intentId): PaymentIntent
    {
        return PaymentIntent::retrieve($intentId);
    }

    /**
     * Verify a webhook payload and return the constructed Event.
     * Throws UnexpectedValueException on bad signature or missing secret.
     */
    public function constructWebhookEvent(string $rawBody, string $signatureHeader): \Stripe\Event
    {
        if (empty($this->webhookSecret)) {
            throw new UnexpectedValueException('Stripe webhook secret is not configured.');
        }

        return Webhook::constructEvent($rawBody, $signatureHeader, $this->webhookSecret);
    }
}
