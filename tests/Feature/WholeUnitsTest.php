<?php

namespace Tests\Feature;

use App\Models\Fund;
use App\Models\FundingInstruction;
use App\Models\FundTransaction;
use App\Models\FundUnitPrice;
use App\Models\Investor;
use App\Models\InvestorActivityLog;
use App\Models\Setting;
use App\Models\SigningEnvelope;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use App\Services\DocuSign\EnvelopeBuilder;
use App\Services\DocuSign\EnvelopeStatusHandler;
use App\Services\Integrations\StripeClient;
use App\Services\InvestorProcessingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Mockery;
use Stripe\PaymentIntent;
use Tests\TestCase;

/**
 * PRICING_WHOLE_UNITS: Units rounded down to a whole number, the contribution
 * recomputed from them, the commitment following once signed, and a payment of
 * the original amount still buying the signed Units with the excess flagged.
 */
class WholeUnitsTest extends TestCase
{
    use RefreshDatabase;

    private Fund $fund;

    private Investor $investor;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();

        config(['docusign.whole_units' => true]);

        // 2% premium on the policy's Base NAV: $14.3765 per Unit, as on the
        // agreement sent to inv-1008.
        $this->fund = Fund::create(['code' => 'aref-i', 'name' => 'Access Real Estate Fund I', 'status' => 'active', 'current_premium_pct' => 2]);
        FundUnitPrice::create(['fund_id' => $this->fund->id, 'as_of_date' => now()->subDays(10)->toDateString(), 'price' => '14.0945809850']);

        $this->investor = Investor::create([
            'code' => 'inv-7301',
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => 'password123',
            'phone' => '+1 617 555 0100',
            'country' => 'United States',
            'joined_at' => now(),
            'fund_id' => $this->fund->id,
            'accreditation_status' => 'accredited',
            'kyc_status' => 'approved',
            'investment_status' => 'active',
            'dashboard_status' => 'active',
            'address_line1' => '1 Beacon St',
            'address_city' => 'Boston',
            'address_state' => 'MA',
            'address_postal_code' => '02108',
            'address_country' => 'United States',
            'personal_investor_type' => 'Individual',
            'personal_residency' => Investor::US_PERSON,
            'investment_fund_name' => 'Access Real Estate Fund I',
            'investment_amount' => 10000,
            'investment_commitment' => 10000,
            'investment_wallet_status' => 'unfunded',
            'investment_expected_yield' => '8%',
        ]);
    }

    private function signedEnvelope(): SigningEnvelope
    {
        $envelope = SigningEnvelope::create([
            'investor_id' => $this->investor->id,
            'docusign_envelope_id' => 'env-whole',
            'template_id' => 'tpl-w9',
            'pricing' => app(EnvelopeBuilder::class)->quote($this->investor),
            'type' => 'subscription_agreement',
            'status' => SigningEnvelope::STATUS_SENT,
            'investor_email' => $this->investor->email,
            'investor_name' => $this->investor->name,
        ]);

        app(EnvelopeStatusHandler::class)->handle(['envelopeId' => 'env-whole', 'status' => 'completed'], followUp: false);

        return $envelope->fresh();
    }

    private function pay(string $intent, int $cents): void
    {
        FundingInstruction::create([
            'investor_profile_id' => $this->investor->id,
            'status' => 'processing',
            'provider' => 'stripe',
            'provider_intent_id' => $intent,
            'amount_cents' => $cents,
            'currency' => 'usd',
        ]);

        app(InvestorProcessingService::class)
            ->applyStripePaymentIntentEvent($intent, 'payment_intent.succeeded', ['status' => 'succeeded']);
    }

    public function test_the_agreement_offers_whole_units_for_slightly_less(): void
    {
        $quote = app(EnvelopeBuilder::class)->quote($this->investor);

        $this->assertSame(695.0, $quote['units']);
        $this->assertSame(14.3765, $quote['price_per_unit']);
        $this->assertSame(9991.67, $quote['amount']);
        $this->assertSame(10000.0, $quote['requested_amount']);
        $this->assertSame('9,991.67', $quote['display']['total_purchase_price']);
        $this->assertSame('14.3765', $quote['display']['price_per_unit']);
        $this->assertSame('695', $quote['display']['units']);
        $this->assertSame('2.00', $quote['display']['subscription_premium_pct']);
    }

    public function test_with_the_setting_off_nothing_changes(): void
    {
        config(['docusign.whole_units' => false]);

        $quote = app(EnvelopeBuilder::class)->quote($this->investor);

        $this->assertSame(10000.0, $quote['amount']);
        $this->assertSame('695.5809', $quote['display']['units']);
        $this->assertFalse($quote['whole_units']);
    }

    public function test_less_than_one_unit_is_refused(): void
    {
        $this->investor->update(['investment_commitment' => 10]);

        $this->expectExceptionMessage('does not buy one whole Unit');
        app(EnvelopeBuilder::class)->quote($this->investor);
    }

    public function test_signing_moves_the_commitment_to_the_signed_amount_once(): void
    {
        $envelope = $this->signedEnvelope();

        $this->assertSame('9991.67', (string) $this->investor->fresh()->investment_commitment);
        $this->assertSame(1, InvestorActivityLog::where('action', 'commitment_adjusted_to_whole_units')->count());

        // A repeated completion event changes nothing further.
        app(EnvelopeStatusHandler::class)->handle(['envelopeId' => $envelope->docusign_envelope_id, 'status' => 'completed'], followUp: false);
        $this->assertSame(1, InvestorActivityLog::where('action', 'commitment_adjusted_to_whole_units')->count());
    }

    public function test_an_admin_changed_commitment_is_left_alone(): void
    {
        $quote = app(EnvelopeBuilder::class)->quote($this->investor);
        $this->investor->update(['investment_commitment' => 25000]);

        SigningEnvelope::create([
            'investor_id' => $this->investor->id,
            'docusign_envelope_id' => 'env-whole',
            'template_id' => 'tpl-w9',
            'pricing' => $quote,
            'type' => 'subscription_agreement',
            'status' => SigningEnvelope::STATUS_SENT,
            'investor_email' => $this->investor->email,
            'investor_name' => $this->investor->name,
        ]);
        app(EnvelopeStatusHandler::class)->handle(['envelopeId' => 'env-whole', 'status' => 'completed'], followUp: false);

        $this->assertSame('25000.00', (string) $this->investor->fresh()->investment_commitment);
    }

    public function test_paying_the_signed_amount_issues_the_whole_units(): void
    {
        $this->signedEnvelope();
        $this->pay('pi_1', 999_167);

        $tx = FundTransaction::where('investor_id', $this->investor->id)->sole();
        $this->assertSame(695.0, (float) $tx->units);
        $this->assertSame(14.3765, (float) $tx->price_per_unit);
        $this->assertSame(9991.67, (float) $tx->gross_amount);
        $this->assertSame(0, InvestorActivityLog::where('action', 'whole_unit_refund_due')->count());
    }

    public function test_paying_the_original_amount_flags_the_excess_for_refund(): void
    {
        $this->signedEnvelope();
        $this->pay('pi_1', 1_000_000);

        $tx = FundTransaction::where('investor_id', $this->investor->id)->sole();
        $this->assertSame(695.0, (float) $tx->units);
        $this->assertSame(9991.67, (float) $tx->gross_amount);

        $refund = InvestorActivityLog::where('action', 'whole_unit_refund_due')->sole();
        $this->assertSame('Refund due: $8.33', $refund->title);
        $this->assertSame(8.33, $refund->metadata['refund']);
    }

    public function test_paying_a_unit_or_more_over_is_not_treated_as_signed(): void
    {
        $this->signedEnvelope();
        $this->pay('pi_1', 1_010_000);

        $tx = FundTransaction::where('investor_id', $this->investor->id)->sole();
        $this->assertNotSame(695.0, (float) $tx->units);
        $this->assertSame(1, InvestorActivityLog::where('action', 'payment_differs_from_signed_terms')->count());
    }

    private function stripeExpectingReplacement(bool $cancelFails = false): StripeClient
    {
        $stripe = Mockery::mock(StripeClient::class);
        $stripe->shouldReceive('isConfigured')->andReturn(true);
        $stripe->shouldReceive('dashboardUrlForIntent')->andReturn('https://dashboard.stripe.test');

        $cancel = $stripe->shouldReceive('cancelPaymentIntent')->with('pi_old');
        $cancelFails ? $cancel->andThrow(new \RuntimeException('processing')) : $cancel->andReturn(PaymentIntent::constructFrom(['id' => 'pi_old']));

        $stripe->shouldReceive('createAchPaymentIntent')
            ->times($cancelFails ? 0 : 1)
            ->withArgs(fn ($inv, $cents) => $cents === 999_167)
            ->andReturn(PaymentIntent::constructFrom(['id' => 'pi_new', 'client_secret' => 'secret_new', 'status' => 'requires_payment_method', 'created' => time()]));

        return $stripe;
    }

    private function oldRequest(string $status = 'released'): FundingInstruction
    {
        return FundingInstruction::create([
            'investor_profile_id' => $this->investor->id,
            'status' => $status,
            'provider' => 'stripe',
            'provider_intent_id' => 'pi_old',
            'amount_cents' => 1_000_000,
            'currency' => 'usd',
        ]);
    }

    public function test_a_10k_payment_request_made_before_signing_is_replaced_with_the_signed_amount(): void
    {
        $old = $this->oldRequest();
        $this->signedEnvelope();

        $service = new InvestorProcessingService(stripe: $this->stripeExpectingReplacement());
        $new = $service->refreshStaleFundingInstruction($this->investor->fresh(), $old);

        $this->assertSame('pi_new', $new->provider_intent_id);
        $this->assertSame(999_167, (int) $new->amount_cents);
        $this->assertSame('superseded', $old->fresh()->status);
        $this->assertSame(1, InvestorActivityLog::where('action', 'funding_request_replaced')->count());

        // Stripe's cancellation of the old request is not a failed payment.
        $service->applyStripePaymentIntentEvent('pi_old', 'payment_intent.canceled', ['status' => 'canceled']);
        $this->assertSame('superseded', $old->fresh()->status);
        $this->assertSame(0, InvestorActivityLog::where('action', 'stripe_payment_failed')->count());
    }

    public function test_a_request_already_being_paid_is_left_alone(): void
    {
        $old = $this->oldRequest('processing');
        $this->signedEnvelope();

        $stripe = Mockery::mock(StripeClient::class);
        $stripe->shouldNotReceive('cancelPaymentIntent');
        $result = (new InvestorProcessingService(stripe: $stripe))->refreshStaleFundingInstruction($this->investor->fresh(), $old);

        $this->assertSame($old->id, $result->id);
    }

    public function test_a_request_stripe_will_not_cancel_is_kept(): void
    {
        $old = $this->oldRequest();
        $this->signedEnvelope();

        $result = (new InvestorProcessingService(stripe: $this->stripeExpectingReplacement(cancelFails: true)))
            ->refreshStaleFundingInstruction($this->investor->fresh(), $old);

        $this->assertSame($old->id, $result->id);
        $this->assertSame('released', $old->fresh()->status);
    }

    public function test_the_admin_settings_switch_overrides_the_env_setting(): void
    {
        config(['docusign.whole_units' => false]);
        Sanctum::actingAs(User::factory()->create());

        // Until saved, Settings shows what .env says.
        $this->getJson('/api/admin/settings')->assertOk()->assertJsonPath('wholeUnitsOnly', false);

        $this->putJson('/api/admin/settings', ['wholeUnitsOnly' => true])
            ->assertOk()->assertJsonPath('wholeUnitsOnly', true);
        $this->assertSame('695', app(EnvelopeBuilder::class)->quote($this->investor)['display']['units']);

        // And off again, even with .env saying on.
        config(['docusign.whole_units' => true]);
        $this->putJson('/api/admin/settings', ['wholeUnitsOnly' => false])->assertOk();
        $this->assertFalse(Setting::wholeUnitsOnly());
        $this->assertSame('695.5809', app(EnvelopeBuilder::class)->quote($this->investor)['display']['units']);
    }
}
