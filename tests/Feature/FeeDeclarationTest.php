<?php

namespace Tests\Feature;

use App\Models\Fund;
use App\Models\FundFee;
use App\Models\FundFeeDeclaration;
use App\Models\FundHolding;
use App\Models\FundTransaction;
use App\Models\Investor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Declaring a quarterly fee and allocating it.
 *
 * The website does not calculate this fee. The accountant does, from the fund's
 * gross asset value after quarter end, and it is entered by hand. Everything
 * here is about that total surviving intact: allocated in full, never
 * recomputed, and never charged twice for the same quarter.
 */
class FeeDeclarationTest extends TestCase
{
    use RefreshDatabase;

    private const Q2 = ['2026-04-01', '2026-06-30'];

    public function test_a_declared_total_is_allocated_in_full_by_ownership(): void
    {
        $fund = $this->fund();
        // 70/30 by units, both held from before the quarter opened.
        $a = $this->investorHolding($fund, 'inv-9001', 'a@example.com', 70000.0);
        $b = $this->investorHolding($fund, 'inv-9002', 'b@example.com', 30000.0);

        Sanctum::actingAs(User::factory()->create());

        $this->postJson("/api/admin/funds/{$fund->code}/fees", [
            'feeType' => 'aum',
            'totalAmount' => 3587.50,
            'grossAssetValue' => 1435000.00,
            'periodStart' => self::Q2[0],
            'periodEnd' => self::Q2[1],
        ])
            ->assertCreated()
            ->assertJsonPath('reconciles', true)
            ->assertJsonPath('allocatedTotal', 3587.50)
            ->assertJsonPath('basis', 'time_weighted_units')
            ->assertJsonPath('count', 2);

        $this->assertSame(2511.25, (float) FundFee::where('investor_id', $a->id)->value('amount'));
        $this->assertSame(1076.25, (float) FundFee::where('investor_id', $b->id)->value('amount'));
        $this->assertSame(3587.50, round((float) FundFee::sum('amount'), 2));

        // The share is recorded so the investor can be told why.
        $this->assertSame(70.0, (float) FundFee::where('investor_id', $a->id)->value('ownership_pct'));
    }

    public function test_the_accountants_total_is_stored_exactly_and_never_recomputed(): void
    {
        $fund = $this->fund(aumRatePct: 1.0);
        $this->investorHolding($fund, 'inv-9001', 'a@example.com', 57500.0);

        Sanctum::actingAs(User::factory()->create());

        // A figure that deliberately does not equal any rate x anything: if the
        // website ever starts deriving the total, this stops matching.
        $this->postJson("/api/admin/funds/{$fund->code}/fees", [
            'feeType' => 'aum',
            'totalAmount' => 4123.77,
            'periodStart' => self::Q2[0],
            'periodEnd' => self::Q2[1],
        ])->assertCreated();

        $declaration = FundFeeDeclaration::firstOrFail();
        $this->assertSame('4123.77', $declaration->total_amount);
        $this->assertSame(4123.77, (float) FundFee::sum('amount'));
    }

    public function test_redeclaring_a_quarter_corrects_it_rather_than_charging_twice(): void
    {
        $fund = $this->fund();
        $this->investorHolding($fund, 'inv-9001', 'a@example.com', 57500.0);

        Sanctum::actingAs(User::factory()->create());

        $payload = [
            'feeType' => 'aum',
            'periodStart' => self::Q2[0],
            'periodEnd' => self::Q2[1],
        ];

        $this->postJson("/api/admin/funds/{$fund->code}/fees", $payload + ['totalAmount' => 3587.50])
            ->assertCreated();
        // The accountant sends a revised figure. A hand-entered number gets
        // corrected, and a correction must not double-charge the quarter.
        $this->postJson("/api/admin/funds/{$fund->code}/fees", $payload + ['totalAmount' => 3600.00])
            ->assertCreated();

        $this->assertSame(1, FundFeeDeclaration::count());
        $this->assertSame(1, FundFee::count());
        $this->assertSame(3600.00, (float) FundFee::sum('amount'));
    }

    public function test_an_investor_who_joined_late_in_the_quarter_bears_a_smaller_share(): void
    {
        $fund = $this->fund();
        $early = $this->investorHolding($fund, 'inv-9001', 'a@example.com', 10000.0, '2020-01-01');
        $late = $this->investorHolding($fund, 'inv-9002', 'b@example.com', 10000.0, '2026-06-30');

        Sanctum::actingAs(User::factory()->create());

        $this->postJson("/api/admin/funds/{$fund->code}/fees", [
            'feeType' => 'aum',
            'totalAmount' => 10000.00,
            'periodStart' => self::Q2[0],
            'periodEnd' => self::Q2[1],
        ])->assertCreated();

        // Equal units, but one held for a single day of ninety-one. Measuring
        // ownership at quarter end would have split this fee evenly.
        $this->assertSame(9891.30, (float) FundFee::where('investor_id', $early->id)->value('amount'));
        $this->assertSame(108.70, (float) FundFee::where('investor_id', $late->id)->value('amount'));
        $this->assertSame(10000.00, round((float) FundFee::sum('amount'), 2));
    }

    public function test_a_quarter_nobody_held_is_refused_rather_than_allocated_to_nobody(): void
    {
        $fund = $this->fund();
        $this->investorHolding($fund, 'inv-9001', 'a@example.com', 10000.0, '2026-08-01');

        Sanctum::actingAs(User::factory()->create());

        $this->postJson("/api/admin/funds/{$fund->code}/fees", [
            'feeType' => 'aum',
            'totalAmount' => 3587.50,
            'periodStart' => self::Q2[0],
            'periodEnd' => self::Q2[1],
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', "Nobody held {$fund->code} between 2026-04-01 and 2026-06-30, so there is nothing to allocate this fee to.");

        $this->assertSame(0, FundFeeDeclaration::count());
        $this->assertSame(0, FundFee::count());
    }

    public function test_the_portal_reports_the_fee_as_informational_with_its_fund_total(): void
    {
        $fund = $this->fund();
        $investor = $this->investorHolding($fund, 'inv-9001', 'a@example.com', 57500.0);

        Sanctum::actingAs(User::factory()->create());
        $this->postJson("/api/admin/funds/{$fund->code}/fees", [
            'feeType' => 'aum',
            'totalAmount' => 3587.50,
            'grossAssetValue' => 1435000.00,
            'periodStart' => self::Q2[0],
            'periodEnd' => self::Q2[1],
        ])->assertCreated();

        Sanctum::actingAs($investor);

        $this->getJson("/api/investor/portal/holdings/{$fund->code}/fees")
            ->assertOk()
            // The published unit price is already net of fees, so the portal
            // must not deduct these again.
            ->assertJsonPath('alreadyNetOfFees', true)
            ->assertJsonPath('totalAum', 3587.50)
            ->assertJsonPath('aum.0.amount', 3587.50);

        // Compared numerically: json_encode renders a whole float as an
        // integer, so an identity assertion against 100.0 fails on 100.
        $fees = $this->getJson("/api/investor/portal/holdings/{$fund->code}/fees")->json();
        $this->assertEqualsWithDelta(100.0, $fees['aum'][0]['ownershipPct'], 1e-6);
        $this->assertEqualsWithDelta(3587.50, $fees['aum'][0]['fundTotal'], 1e-6);
        $this->assertEqualsWithDelta(1435000.0, $fees['aum'][0]['fundGrossAssetValue'], 1e-6);
    }

    public function test_an_investor_cannot_see_another_investors_fee_allocation(): void
    {
        $fund = $this->fund();
        $mine = $this->investorHolding($fund, 'inv-9001', 'a@example.com', 70000.0);
        $this->investorHolding($fund, 'inv-9002', 'b@example.com', 30000.0);

        Sanctum::actingAs(User::factory()->create());
        $this->postJson("/api/admin/funds/{$fund->code}/fees", [
            'feeType' => 'aum',
            'totalAmount' => 1000.00,
            'periodStart' => self::Q2[0],
            'periodEnd' => self::Q2[1],
        ])->assertCreated();

        Sanctum::actingAs($mine);

        // Their own 70% share, not the fund's $1,000 as a charge to them.
        $this->getJson("/api/investor/portal/holdings/{$fund->code}/fees")
            ->assertOk()
            ->assertJsonCount(1, 'aum')
            ->assertJsonPath('totalAum', 700);
    }

    private function fund(float $aumRatePct = 1.0): Fund
    {
        return Fund::create([
            'code' => 'AREF-I',
            'name' => 'Access Real Estate Fund I',
            'status' => 'active',
            'aum_fee_annual_pct' => $aumRatePct,
        ]);
    }

    private function investorHolding(
        Fund $fund,
        string $code,
        string $email,
        float $units,
        string $date = '2023-01-30',
    ): Investor {
        $investor = Investor::create([
            'code' => $code,
            'name' => 'Fee Investor '.$code,
            'email' => $email,
            'password' => 'secret-password',
            'country' => 'United States',
            'joined_at' => now(),
            'accreditation_status' => 'accredited',
            'kyc_status' => 'approved',
            'investment_status' => 'active',
            'dashboard_status' => 'active',
            'address_line1' => '1 Main Road',
            'address_city' => 'Boston',
            'address_state' => 'MA',
            'address_postal_code' => '02101',
            'address_country' => 'United States',
            'personal_investor_type' => 'Individual',
            'personal_residency' => 'U.S. Person',
            'investment_fund_name' => $fund->name,
            'investment_wallet_status' => 'Active',
            'investment_expected_yield' => '8%',
            'fund_id' => $fund->id,
        ]);

        FundTransaction::create([
            'investor_id' => $investor->id,
            'fund_id' => $fund->id,
            'type' => FundTransaction::TYPE_SUBSCRIPTION,
            'transaction_date' => $date,
            'units' => $units,
            'price_per_unit' => 10.0,
            'gross_amount' => $units * 10.0,
        ]);

        FundHolding::create([
            'investor_id' => $investor->id,
            'fund_id' => $fund->id,
            'units' => $units,
            'amount_invested' => $units * 10.0,
            'average_unit_price' => 10.0,
            'first_invested_at' => $date,
        ]);

        return $investor->refresh();
    }
}
