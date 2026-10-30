<?php

namespace Tests\Feature;

use App\Models\Fund;
use App\Models\FundingInstruction;
use App\Models\FundTransaction;
use App\Models\FundUnitPrice;
use App\Models\Investor;
use App\Models\InvestorActivityLog;
use App\Models\SigningEnvelope;
use App\Services\DocuSign\EnvelopeBuilder;
use App\Services\InvestorProcessingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Units are issued at the price and count the investor signed for, even when
 * the NAV is republished between signing and the money clearing.
 */
class UnitsIssuedAtSignedTermsTest extends TestCase
{
    use RefreshDatabase;

    private Fund $fund;

    private Investor $investor;

    private SigningEnvelope $envelope;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fund = Fund::create(['code' => 'aref-i', 'name' => 'Access Real Estate Fund I', 'status' => 'active']);
        FundUnitPrice::create(['fund_id' => $this->fund->id, 'as_of_date' => now()->subDays(10)->toDateString(), 'price' => '14.0945809850']);

        $this->investor = Investor::create([
            'code' => 'inv-7201',
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => 'password123',
            'phone' => '+1 617 555 0100',
            'country' => 'United States',
            'joined_at' => now(),
            'fund_id' => $this->fund->id,
            'accreditation_status' => 'accredited',
            'kyc_status' => 'approved',
            // Already active, so activation is not part of these tests.
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

        // Signed at the policy's example terms: $10,000 → 709.4925 Units.
        $this->envelope = SigningEnvelope::create([
            'investor_id' => $this->investor->id,
            'docusign_envelope_id' => 'env-signed',
            'template_id' => 'tpl-w9',
            'pricing' => app(EnvelopeBuilder::class)->quote($this->investor),
            'type' => 'subscription_agreement',
            'status' => SigningEnvelope::STATUS_COMPLETED,
            'investor_email' => $this->investor->email,
            'investor_name' => $this->investor->name,
            'completed_at' => now()->subDays(2),
        ]);

        // The NAV is republished after signing, before the money clears.
        FundUnitPrice::create(['fund_id' => $this->fund->id, 'as_of_date' => now()->subDay()->toDateString(), 'price' => '15.00']);
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

    public function test_the_signed_units_are_issued_not_a_reprice_at_the_new_nav(): void
    {
        $this->pay('pi_1', 1_000_000);

        $tx = FundTransaction::where('investor_id', $this->investor->id)->sole();
        $this->assertEqualsWithDelta(709.492535, (float) $tx->units, 0.000001);
        $this->assertEqualsWithDelta(14.09458099, (float) $tx->price_per_unit, 0.00000001);
        $this->assertEqualsWithDelta(14.09458099, (float) $tx->book_value_at_purchase, 0.00000001);
        $this->assertNotNull($this->envelope->fresh()->pricing['applied_at']);
        $this->assertSame($tx->id, $this->envelope->fresh()->pricing['fund_transaction_id']);
    }

    public function test_a_later_top_up_does_not_reuse_the_signed_terms(): void
    {
        $this->pay('pi_1', 1_000_000);
        $this->pay('pi_2', 1_000_000);

        $topUp = FundTransaction::where('investor_id', $this->investor->id)->latest('id')->first();
        $this->assertEqualsWithDelta(15.0, (float) $topUp->price_per_unit, 0.00000001);
        $this->assertEqualsWithDelta(666.666667, (float) $topUp->units, 0.000001);
    }

    public function test_a_payment_for_a_different_amount_is_priced_now_and_flagged(): void
    {
        $this->pay('pi_1', 800_000);

        $tx = FundTransaction::where('investor_id', $this->investor->id)->sole();
        $this->assertEqualsWithDelta(15.0, (float) $tx->price_per_unit, 0.00000001);
        $this->assertNull($this->envelope->fresh()->pricing['applied_at'] ?? null);
        $this->assertTrue(InvestorActivityLog::where('action', 'payment_differs_from_signed_terms')->exists());
    }
}
