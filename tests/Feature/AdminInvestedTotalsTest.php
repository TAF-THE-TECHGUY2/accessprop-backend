<?php

namespace Tests\Feature;

use App\Models\Fund;
use App\Models\FundTransaction;
use App\Models\Investor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The admin's invested figures come from the fund_transactions ledger.
 *
 * They used to be read off investors.investment_amount, which is the amount the
 * investor named when they signed up and is never revised. An investor who
 * deposited three times therefore counted for their first deposit alone.
 */
class AdminInvestedTotalsTest extends TestCase
{
    use RefreshDatabase;

    private function fund(): Fund
    {
        return Fund::create([
            'code' => 'apf-1',
            'name' => 'Access Properties Fund I',
            'status' => 'active',
        ]);
    }

    private function investor(string $code, float $statedAtSignup): Investor
    {
        return Investor::create([
            'code' => $code,
            'name' => 'Sample Investor',
            'email' => $code.'@example.com',
            'password' => 'password123',
            'country' => 'United States',
            'joined_at' => now(),
            'accreditation_status' => 'accredited',
            'kyc_status' => 'approved',
            'investment_status' => 'active',
            'dashboard_status' => 'active',
            'address_line1' => '1 Beacon St',
            'address_city' => 'Boston',
            'address_state' => 'MA',
            'address_postal_code' => '02108',
            'address_country' => 'United States',
            'personal_investor_type' => 'individual',
            'personal_residency' => 'us',
            'investment_fund_name' => 'Access Properties Fund I',
            'investment_wallet_status' => 'unfunded',
            'investment_expected_yield' => '8%',
            'investment_amount' => $statedAtSignup,
            'investment_commitment' => $statedAtSignup,
        ]);
    }

    private function deposit(Investor $investor, Fund $fund, float $amount, string $date): void
    {
        FundTransaction::create([
            'investor_id' => $investor->id,
            'fund_id' => $fund->id,
            'transaction_date' => $date,
            'type' => FundTransaction::TYPE_SUBSCRIPTION,
            'units' => $amount / 10,
            'book_value_at_purchase' => 10,
            'price_per_unit' => 10,
            'gross_amount' => $amount,
        ]);
    }

    public function test_an_investor_who_tops_up_counts_for_every_deposit(): void
    {
        $fund = $this->fund();
        $investor = $this->investor('inv-2001', 121000);

        $this->deposit($investor, $fund, 121000, '2023-01-30');
        $this->deposit($investor, $fund, 404329.34, '2023-03-31');
        $this->deposit($investor, $fund, 50140.82, '2023-05-01');

        $this->assertSame(575470.16, round($investor->contributedCapital(), 2));

        Sanctum::actingAs(User::factory()->create());

        $body = $this->getJson('/api/admin/investors')->assertOk();

        $this->assertSame(575470.16, (float) $body->json('data.0.investmentAmount'));
        // What they said at signup is still available, just not as the total.
        $this->assertSame(121000.0, (float) $body->json('data.0.statedAmount'));
    }

    public function test_the_dashboard_total_sums_the_ledger_across_investors(): void
    {
        $fund = $this->fund();

        $a = $this->investor('inv-2002', 25000);
        $this->deposit($a, $fund, 25000, '2024-01-10');
        $this->deposit($a, $fund, 5000, '2024-06-10');

        $b = $this->investor('inv-2003', 100000);
        $this->deposit($b, $fund, 100000, '2024-02-10');

        // Registered but never funded: contributes nothing, and must not be
        // counted for the amount they named on the signup form.
        $this->investor('inv-2004', 250000);

        Sanctum::actingAs(User::factory()->create());

        $this->assertSame(130000.0, (float) $this->getJson('/api/admin/dashboard')
            ->assertOk()->json('metrics.totalInvested'));

        $this->assertSame(130000.0, (float) $this->getJson('/api/admin/reports')
            ->assertOk()->json('stats.totalInvested'));
    }

    public function test_redemptions_do_not_reduce_capital_contributed(): void
    {
        $fund = $this->fund();
        $investor = $this->investor('inv-2005', 50000);
        $this->deposit($investor, $fund, 50000, '2024-01-10');

        FundTransaction::create([
            'investor_id' => $investor->id,
            'fund_id' => $fund->id,
            'transaction_date' => '2024-09-10',
            'type' => FundTransaction::TYPE_REDEMPTION,
            'units' => -1000,
            'book_value_at_purchase' => 10,
            'price_per_unit' => 10,
            'gross_amount' => -10000,
        ]);

        // Contributed capital, not a net balance — same rule FundHolding uses.
        $this->assertSame(50000.0, round($investor->contributedCapital(), 2));
    }

    public function test_the_distribution_buckets_use_the_ledger_total(): void
    {
        $fund = $this->fund();

        // Named $121k at signup, actually deposited $575k. The old code put this
        // investor in the $100k-$249k bucket.
        $investor = $this->investor('inv-2006', 121000);
        $this->deposit($investor, $fund, 121000, '2023-01-30');
        $this->deposit($investor, $fund, 454470.16, '2023-03-31');

        Sanctum::actingAs(User::factory()->create());

        $response = $this->getJson('/api/admin/reports')->assertOk();
        $buckets = collect($response->json('investmentAmountDistribution'))
            ->pluck('amount', 'range');

        $this->assertSame(575470.16, (float) $buckets['$500k+']);
        $this->assertSame(0.0, (float) $buckets['$100k-$249k']);
    }

    public function test_a_list_of_investors_does_not_query_per_row(): void
    {
        $fund = $this->fund();
        foreach (range(1, 8) as $n) {
            $investor = $this->investor('inv-30'.$n, 10000);
            $this->deposit($investor, $fund, 10000, '2024-03-01');
        }

        Sanctum::actingAs(User::factory()->create());

        \DB::enableQueryLog();
        $this->getJson('/api/admin/investors')->assertOk();
        $queries = count(\DB::getQueryLog());
        \DB::disableQueryLog();

        // The aggregate rides along on the investors query rather than firing
        // one per row; a regression here would show as 8+ extra queries.
        $this->assertLessThan(10, $queries, "Expected the totals to be eager-loaded, saw {$queries} queries.");
    }
}
