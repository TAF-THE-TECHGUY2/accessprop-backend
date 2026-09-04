<?php

namespace Tests\Unit;

use App\Models\FundTransaction;
use App\Services\InvestmentCalculator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The fund manager's workbook, asserted directly.
 *
 * This class produces every figure an investor sees, and its formulas have been
 * wrong twice — once minting units at the inception price, once reporting cost
 * basis as market value. Until now the only thing pinning them was a Playwright
 * suite needing a browser, a database and twenty-five seconds. These run in
 * milliseconds against unsaved models, so the arithmetic is checked on every
 * commit rather than only when someone runs the browser tests.
 *
 * The expected values are the fund manager's sample, verified independently
 * before being written down here.
 *
 * No RefreshDatabase and no queries: the models are unsaved and the calculator
 * only reads their attributes. The framework is booted purely so Eloquent's
 * casts work.
 */
class InvestmentCalculatorTest extends TestCase
{
    private const UNIT_VALUE = 13.25;

    private const AS_OF = '2026-06-30';

    /** His three investments: units are the input, prices fall out uneven. */
    private const SAMPLE = [
        ['2023-01-30', 121000.00, 12100.0],
        ['2023-03-31', 404329.34, 40400.0],
        ['2023-05-01', 50140.82, 5000.0],
    ];

    #[Test]
    public function it_reproduces_every_row_of_the_workbook(): void
    {
        $rows = $this->calculator()->compute($this->sample())['rows'];

        $expected = [
            [
                'depositDate' => '2023-01-30',
                'contribution' => 121000.00,
                'contributionPct' => 21.03,
                'units' => 12100.0,
                'unitPrice' => 10.0,
                'unitsValue' => 160325.00,
                'gain' => 39325.00,
                'gainPct' => 32.50,
                'holdingYears' => 3.4141,
                'annualizedReturnPct' => 8.59,
            ],
            [
                'depositDate' => '2023-03-31',
                'contribution' => 404329.34,
                'contributionPct' => 70.26,
                'units' => 40400.0,
                'unitPrice' => 10.008152,
                'unitsValue' => 535300.00,
                'gain' => 130970.66,
                'gainPct' => 32.39,
                'holdingYears' => 3.2498,
                'annualizedReturnPct' => 9.02,
            ],
            [
                'depositDate' => '2023-05-01',
                'contribution' => 50140.82,
                'contributionPct' => 8.71,
                'units' => 5000.0,
                'unitPrice' => 10.028164,
                'unitsValue' => 66250.00,
                'gain' => 16109.18,
                'gainPct' => 32.13,
                'holdingYears' => 3.1650,
                'annualizedReturnPct' => 9.20,
            ],
        ];

        $this->assertCount(3, $rows);

        foreach ($expected as $i => $fields) {
            foreach ($fields as $key => $value) {
                $this->assertSame(
                    $value,
                    $rows[$i][$key],
                    sprintf('row %d, %s', $i + 1, $key),
                );
            }
        }
    }

    #[Test]
    public function it_reproduces_the_workbook_totals(): void
    {
        $totals = $this->calculator()->compute($this->sample())['totals'];

        $this->assertSame(575470.16, $totals['contribution']);
        $this->assertSame(57500.0, $totals['units']);
        $this->assertSame(761875.00, $totals['unitsValue']);
        $this->assertSame(186404.84, $totals['gain']);
        $this->assertSame(32.39, $totals['gainPct']);
        $this->assertSame(3.2770, $totals['weightedAverageHoldingPeriodYears']);
        $this->assertSame(8.94, $totals['annualizedReturnPct']);
        $this->assertSame(3, $totals['investmentCount']);
        $this->assertSame(self::AS_OF, $totals['unitValueAsOf']);
    }

    #[Test]
    public function the_weighted_average_unit_price_is_total_contribution_over_total_units(): void
    {
        $totals = $this->calculator()->compute($this->sample())['totals'];

        $this->assertSame(10.008177, $totals['weightedAverageUnitPrice']);

        // His sheet's E7 sums (share x price), which is not a weighted mean —
        // it expands to SUM(contribution^2 / (total_contribution x units)) and
        // reads 10.008182 on this sample. The gap is small here because the
        // contributions are near-uniform in price, and it grows as they spread.
        // Asserting the difference keeps the deviation deliberate: if someone
        // "fixes" this to match his sheet, this test says why not to.
        $his = 0.0;
        foreach (self::SAMPLE as [$date, $contribution, $units]) {
            $his += ($contribution / 575470.16) * ($contribution / $units);
        }
        $this->assertSame(10.008182, round($his, 6));
        $this->assertNotSame(round($his, 6), $totals['weightedAverageUnitPrice']);
    }

    #[Test]
    public function the_holding_period_is_weighted_by_units_not_by_contribution(): void
    {
        // On his sample both weightings round to 3.2770, so that sample cannot
        // tell them apart — a test built on it passes either way. This one uses
        // disproportionate prices, a large cheap early block and a smaller
        // expensive late one, where the two weightings are 2.8934 and 1.6931.
        $disproportionate = $this->transactions([
            ['2020-01-01', 100000.0, 20000.0],  // $5.00 a unit
            ['2026-01-01', 400000.0, 30000.0],  // $13.33 a unit
        ]);

        $totals = (new InvestmentCalculator(self::UNIT_VALUE, Carbon::parse(self::AS_OF)))
            ->compute($disproportionate)['totals'];

        $this->assertSame(2.8934, $totals['weightedAverageHoldingPeriodYears']);

        // And his sample still holds, so the ordinary case stays pinned too.
        $this->assertSame(
            3.2770,
            $this->calculator()->compute($this->sample())['totals']['weightedAverageHoldingPeriodYears'],
        );
    }

    #[Test]
    public function the_unit_price_is_derived_from_the_units_and_never_the_reverse(): void
    {
        $rows = $this->calculator()->compute($this->sample())['rows'];

        $this->assertSame(40400.0, $rows[1]['units']);
        $this->assertSame(10.008152, $rows[1]['unitPrice']);
        $this->assertSame(404329.34, $rows[1]['contribution']);

        // Round numbers round-trip, so they cannot detect a units count that
        // was recomputed from the price. 1,234.567891 units for $10,000 gives a
        // price of $8.100000 at six decimals, and dividing back gives
        // 1,234.567901 — the units count drifts by a ten-millionth. That is the
        // shape of a real defect: 40,400 supplied units once came back as
        // 40,400.000001.
        $awkward = (new InvestmentCalculator(self::UNIT_VALUE, Carbon::parse(self::AS_OF)))
            ->compute($this->transactions([['2023-01-30', 10000.0, 1234.567891]]))['rows'];

        $this->assertSame(1234.567891, $awkward[0]['units']);
        $this->assertNotSame(1234.567901, $awkward[0]['units']);
    }

    #[Test]
    public function holding_periods_are_measured_to_the_as_of_date_not_to_today(): void
    {
        // Two calculators over the same transactions, one valued a year later.
        // Measuring to now() would make both agree and every figure drift daily.
        $early = (new InvestmentCalculator(self::UNIT_VALUE, Carbon::parse('2025-06-30')))
            ->compute($this->sample())['rows'][0]['holdingYears'];
        $late = (new InvestmentCalculator(self::UNIT_VALUE, Carbon::parse('2026-06-30')))
            ->compute($this->sample())['rows'][0]['holdingYears'];

        $this->assertSame(2.4148, $early);
        $this->assertSame(3.4141, $late);
        $this->assertEqualsWithDelta(1.0, $late - $early, 0.002);
    }

    #[Test]
    public function a_year_is_365_point_25_days(): void
    {
        $this->assertSame(365.25, InvestmentCalculator::DAYS_PER_YEAR);

        // 1,461 days is exactly four such years. On a 365-day year it would be
        // 4.0027, and the annualized figures would not reconcile.
        $asOf = Carbon::parse('2027-01-01');
        $rows = (new InvestmentCalculator(self::UNIT_VALUE, $asOf))
            ->compute($this->transactions([['2023-01-01', 1000.0, 100.0]]))['rows'];

        $this->assertSame(4.0, $rows[0]['holdingYears']);
    }

    #[Test]
    public function redemptions_and_adjustments_are_excluded_from_the_figures(): void
    {
        $withNoise = $this->sample()->push(
            $this->transaction('2024-01-01', -50000.0, -5000.0, FundTransaction::TYPE_REDEMPTION),
            $this->transaction('2024-06-01', 9999.0, 999.0, FundTransaction::TYPE_ADJUSTMENT),
        );

        $totals = (new InvestmentCalculator(self::UNIT_VALUE, Carbon::parse(self::AS_OF)))
            ->compute($withNoise)['totals'];

        // A redemption must not reduce cost basis and an adjustment must not
        // inflate it — both would silently change every percentage.
        $this->assertSame(575470.16, $totals['contribution']);
        $this->assertSame(57500.0, $totals['units']);
        $this->assertSame(3, $totals['investmentCount']);
    }

    #[Test]
    public function rows_are_ordered_by_deposit_date_regardless_of_input_order(): void
    {
        $shuffled = $this->transactions([
            ['2023-05-01', 50140.82, 5000.0],
            ['2023-01-30', 121000.00, 12100.0],
            ['2023-03-31', 404329.34, 40400.0],
        ]);

        $rows = (new InvestmentCalculator(self::UNIT_VALUE, Carbon::parse(self::AS_OF)))
            ->compute($shuffled)['rows'];

        $this->assertSame(
            ['2023-01-30', '2023-03-31', '2023-05-01'],
            array_column($rows, 'depositDate'),
        );
    }

    #[Test]
    public function an_empty_ledger_reports_zeros_rather_than_dividing_by_zero(): void
    {
        $result = $this->calculator()->compute(new Collection());

        $this->assertSame([], $result['rows']);
        $this->assertSame(0.0, $result['totals']['contribution']);
        $this->assertSame(0.0, $result['totals']['gainPct']);
        $this->assertSame(0, $result['totals']['investmentCount']);
        // The valuation is still reported: the fund has a price even where this
        // investor has no position.
        $this->assertSame(13.25, $result['totals']['unitValue']);
        $this->assertSame(self::AS_OF, $result['totals']['unitValueAsOf']);
    }

    #[Test]
    public function a_deposit_on_the_as_of_date_reports_no_annualized_return(): void
    {
        // A zero holding period makes the root undefined; 0 is the only honest
        // answer, and it must not be an Inf or a NaN reaching the portal.
        $rows = (new InvestmentCalculator(self::UNIT_VALUE, Carbon::parse('2026-06-30')))
            ->compute($this->transactions([['2026-06-30', 13250.0, 1000.0]]))['rows'];

        $this->assertSame(0.0, $rows[0]['holdingYears']);
        $this->assertSame(0.0, $rows[0]['annualizedReturnPct']);
        $this->assertSame(0.0, $rows[0]['gainPct']);
    }

    #[Test]
    public function a_total_loss_reports_no_annualized_return_rather_than_a_nan(): void
    {
        // (1 + gain%) is zero, and its root has no real value.
        $rows = (new InvestmentCalculator(0.0, Carbon::parse(self::AS_OF)))
            ->compute($this->transactions([['2023-01-30', 121000.0, 12100.0]]))['rows'];

        $this->assertSame(-100.0, $rows[0]['gainPct']);
        $this->assertSame(0.0, $rows[0]['annualizedReturnPct']);
        $this->assertFalse(is_nan($rows[0]['annualizedReturnPct']));
    }

    #[Test]
    public function a_loss_still_annualizes(): void
    {
        // Distinct from a total loss: this one has a real root and must not be
        // flattened to zero along with it.
        $rows = (new InvestmentCalculator(9.0, Carbon::parse(self::AS_OF)))
            ->compute($this->transactions([['2023-01-30', 121000.0, 12100.0]]))['rows'];

        $this->assertSame(-10.0, $rows[0]['gainPct']);
        $this->assertSame(-3.04, $rows[0]['annualizedReturnPct']);
    }

    #[Test]
    public function the_total_percentage_is_computed_from_the_totals_not_averaged_from_the_rows(): void
    {
        $result = $this->calculator()->compute($this->sample());

        // The row percentages are 32.50 / 32.39 / 32.13. Their mean is 32.34,
        // which belongs to no position; the correct total is 32.39.
        $mean = array_sum(array_column($result['rows'], 'gainPct')) / 3;
        $this->assertSame(32.34, round($mean, 2));
        $this->assertSame(32.39, $result['totals']['gainPct']);
    }

    #[Test]
    public function a_reinvestment_currently_counts_towards_contributed_capital(): void
    {
        // Pinning present behaviour, not endorsing it. reinvestment is in
        // INFLOW_TYPES, so a reinvested distribution is summed into
        // contribution — which treats the fund's own payout as capital the
        // investor put in, and so understates their return.
        //
        // A $124,200 distribution reinvested at $13.25 takes the denominator
        // from $575,470 to $699,670 and the reported gain from +32.39% to
        // +26.64%, on an investor who contributed no new money.
        //
        // Whether that is right depends on whether distributions are paid in
        // cash or reinvested — an open question with the fund manager. This
        // test exists so that when it is answered, the consequence is visible
        // rather than discovered later in a portal figure.
        $withReinvestment = $this->sample()->push(
            $this->transaction('2026-04-30', 124200.0, 9373.584906, FundTransaction::TYPE_REINVESTMENT),
        );

        $totals = (new InvestmentCalculator(self::UNIT_VALUE, Carbon::parse(self::AS_OF)))
            ->compute($withReinvestment)['totals'];

        $this->assertSame(699670.16, $totals['contribution']);
        $this->assertSame(66873.584906, $totals['units']);
        $this->assertSame(26.64, $totals['gainPct']);
        $this->assertSame(4, $totals['investmentCount']);
    }

    private function calculator(): InvestmentCalculator
    {
        return new InvestmentCalculator(self::UNIT_VALUE, Carbon::parse(self::AS_OF));
    }

    private function sample(): Collection
    {
        return $this->transactions(self::SAMPLE);
    }

    /** @param  array<int, array{0: string, 1: float, 2: float}>  $rows */
    private function transactions(array $rows): Collection
    {
        return new Collection(array_map(
            fn (array $r, int $i) => $this->transaction($r[0], $r[1], $r[2], id: $i + 1),
            $rows,
            array_keys($rows),
        ));
    }

    /**
     * An unsaved model — never persisted, never queried. The calculator only
     * reads attributes, which is the point: this suite is arithmetic, not
     * integration.
     */
    private function transaction(
        string $date,
        float $grossAmount,
        float $units,
        string $type = FundTransaction::TYPE_SUBSCRIPTION,
        int $id = 1,
    ): FundTransaction {
        $t = new FundTransaction();
        $t->id = $id;
        $t->type = $type;
        $t->transaction_date = Carbon::parse($date);
        $t->gross_amount = $grossAmount;
        $t->units = $units;

        return $t;
    }
}
