<?php

namespace Tests\Feature;

use App\Models\Fund;
use App\Models\FundingInstruction;
use App\Models\FundTransaction;
use App\Models\FundUnitPrice;
use App\Models\Investor;
use App\Models\PaymentConfirmation;
use App\Services\InvestorProcessingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * One ACH payment is one ledger entry, however many times Stripe — or the
 * funding self-heal — reports it.
 */
class StripePaymentIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private Investor $investor;

    protected function setUp(): void
    {
        parent::setUp();

        $fund = Fund::create(['code' => 'aref-i', 'name' => 'Access Real Estate Fund I', 'status' => 'active']);
        FundUnitPrice::create(['fund_id' => $fund->id, 'as_of_date' => now()->subMonth()->toDateString(), 'price' => 10]);

        $this->investor = Investor::create([
            'code' => 'inv-9101',
            'name' => 'Sample Investor',
            'email' => 'sample@example.com',
            'password' => 'password123',
            'phone' => '+1 617 555 0100',
            'country' => 'United States',
            'joined_at' => now(),
            'fund_id' => $fund->id,
            'accreditation_status' => 'accredited',
            'kyc_status' => 'approved',
            // A top-up: already active, so activation is not part of this.
            'investment_status' => 'active',
            'dashboard_status' => 'active',
            'address_line1' => '1 Beacon St',
            'address_city' => 'Boston',
            'address_state' => 'MA',
            'address_postal_code' => '02108',
            'address_country' => 'United States',
            'personal_investor_type' => 'individual',
            'personal_residency' => 'us',
            'investment_fund_name' => 'Access Real Estate Fund I',
            'investment_wallet_status' => 'unfunded',
            'investment_expected_yield' => '8%',
        ]);

        FundingInstruction::create([
            'investor_profile_id' => $this->investor->id,
            'status' => 'requires_payment_method',
            'provider' => 'stripe',
            'provider_intent_id' => 'pi_test_1',
            'amount_cents' => 2_500_000,
            'currency' => 'usd',
        ]);
    }

    private function event(string $type, string $status): void
    {
        app(InvestorProcessingService::class)->applyStripePaymentIntentEvent('pi_test_1', $type, ['status' => $status]);
    }

    public function test_a_repeated_succeeded_event_records_the_payment_once(): void
    {
        $this->event('payment_intent.succeeded', 'succeeded');
        $this->event('payment_intent.succeeded', 'succeeded');

        $this->assertSame(1, FundTransaction::where('investor_id', $this->investor->id)->count());
        $this->assertEquals(25000, (float) $this->investor->fresh()->contributedCapital());
    }

    public function test_a_late_processing_event_does_not_reopen_a_settled_payment(): void
    {
        $this->event('payment_intent.succeeded', 'succeeded');
        $this->event('payment_intent.processing', 'processing');
        $this->event('payment_intent.succeeded', 'succeeded');

        $this->assertSame('succeeded', FundingInstruction::where('provider_intent_id', 'pi_test_1')->value('status'));
        $this->assertSame(1, FundTransaction::where('investor_id', $this->investor->id)->count());
    }

    public function test_a_repeated_processing_event_records_one_confirmation(): void
    {
        $this->event('payment_intent.processing', 'processing');
        $this->event('payment_intent.processing', 'processing');

        $this->assertSame(1, PaymentConfirmation::where('reference', 'pi_test_1')->count());
    }
}
