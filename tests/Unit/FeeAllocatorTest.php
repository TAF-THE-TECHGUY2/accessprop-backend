<?php

namespace Tests\Unit;

use App\Models\FundTransaction;
use App\Services\FeeAllocator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Splitting the accountant's quarterly figure across investors.
 *
 * The property that matters most is that the allocations sum to exactly the
 * entered total. A set of investor fees that does not add up to the
 * accountant's number is a discrepancy someone has to reconcile by hand, and
 * every test here that checks a sum is checking that.
 *
 * No database: unsaved models, arithmetic only.
 */
class FeeAllocatorTest extends TestCase
{
    private const Q2_START = '2026-04-01';

    private const Q2_END = '2026-06-30';

    #[Test]
    public function a_holding_predating_the_quarter_counts_for_the_whole_quarter(): void
    {
        $weights = $this->allocator()->timeWeightedUnits(
            $this->transactions([[1, '2023-01-30', 12100.0]]),
            Carbon::parse(self::Q2_START),
            Carbon::parse(self::Q2_END),
        );

        $this->assertEqualsWithDelta(12100.0, $weights[1], 1e-9);
    }

    #[Test]
    public function a_holding_opened_inside_the_quarter_is_weighted_by_the_days_it_existed(): void
    {
        // Q2 is 91 days. A deposit on 1 June is held for 30 of them.
        $weights = $this->allocator()->timeWeightedUnits(
            $this->transactions([[1, '2026-06-01', 9100.0]]),
            Carbon::parse(self::Q2_START),
            Carbon::parse(self::Q2_END),
        );

        $this->assertEqualsWithDelta(9100.0 * 30 / 91, $weights[1], 1e-9);
        $this->assertEqualsWithDelta(3000.0, $weights[1], 0.5);
    }

    #[Test]
    public function investing_on_the_last_day_of_the_quarter_does_not_incur_a_full_quarter_fee(): void
    {
        // The whole reason for time-weighting. Measured at quarter end these
        // two would be equal holders and would split the fee evenly.
        $weights = $this->allocator()->timeWeightedUnits(
            $this->transactions([
                [1, '2020-01-01', 10000.0],   // held throughout
                [2, self::Q2_END, 10000.0],   // arrived on the closing day
            ]),
            Carbon::parse(self::Q2_START),
            Carbon::parse(self::Q2_END),
        );

        $fees = $this->allocator()->allocate(10000.0, $weights);

        // One day of 91 against a full quarter: 10,000 x 1/91 = 109.89 units
        // of weight against 10,000, so 1.09% of the fee rather than 50%.
        $this->assertSame(9891.30, $fees[1]);
        $this->assertSame(108.70, $fees[2]);
        $this->assertSame(10000.0, array_sum($fees));
    }

    #[Test]
    public function a_transaction_after_the_quarter_closed_carries_no_weight(): void
    {
        $weights = $this->allocator()->timeWeightedUnits(
            $this->transactions([
                [1, '2020-01-01', 10000.0],
                [2, '2026-07-15', 50000.0],   // next quarter's problem
            ]),
            Carbon::parse(self::Q2_START),
            Carbon::parse(self::Q2_END),
        );

        $this->assertArrayHasKey(1, $weights);
        $this->assertArrayNotHasKey(2, $weights);
    }

    #[Test]
    public function a_redemption_reduces_the_weight_from_the_day_it_settles(): void
    {
        $weights = $this->allocator()->timeWeightedUnits(
            $this->transactions([
                [1, '2020-01-01', 10000.0],
                [1, '2026-06-01', -10000.0],  // out with 30 days left
            ]),
            Carbon::parse(self::Q2_START),
            Carbon::parse(self::Q2_END),
        );

        // Held all 91 days, minus 10,000 units for the final 30.
        $this->assertEqualsWithDelta(10000.0 - (10000.0 * 30 / 91), $weights[1], 1e-9);
    }

    #[Test]
    public function a_position_fully_exited_mid_quarter_still_bears_its_share(): void
    {
        // Exiting is not an exemption: the fund managed their money for part of
        // the quarter and the fee covers that part.
        $weights = $this->allocator()->timeWeightedUnits(
            $this->transactions([
                [1, '2020-01-01', 10000.0],
                [2, '2020-01-01', 10000.0],
                [2, '2026-05-01', -10000.0],  // gone with 61 days left
            ]),
            Carbon::parse(self::Q2_START),
            Carbon::parse(self::Q2_END),
        );

        $this->assertArrayHasKey(2, $weights);
        $this->assertEqualsWithDelta(10000.0 - (10000.0 * 61 / 91), $weights[2], 1e-9);

        $fees = $this->allocator()->allocate(1000.0, $weights);
        $this->assertGreaterThan(0, $fees[2]);
        $this->assertSame(1000.0, array_sum($fees));
    }

    #[Test]
    public function the_allocations_always_sum_to_the_entered_total(): void
    {
        // Three equal holders and a total that does not divide by three is the
        // classic case where independent rounding loses a cent.
        $weights = [1 => 100.0, 2 => 100.0, 3 => 100.0];
        $fees = $this->allocator()->allocate(100.0, $weights);

        $this->assertSame(100.0, array_sum($fees));
        $this->assertSame([33.34, 33.33, 33.33], array_values($fees));
    }

    #[Test]
    public function it_reconciles_across_many_awkward_totals_and_splits(): void
    {
        $allocator = $this->allocator();
        $weights = [1 => 12100.0, 2 => 40400.0, 3 => 5000.0, 4 => 1.0, 5 => 0.000001];

        foreach ([0.01, 0.03, 1.0, 99.99, 1234.56, 14395.11, 999999.99] as $total) {
            $fees = $allocator->allocate($total, $weights);
            $this->assertSame(
                $total,
                round(array_sum($fees), 2),
                sprintf('allocations must sum to %s', $total),
            );
        }
    }

    #[Test]
    public function the_ownership_percentages_sum_to_one_hundred(): void
    {
        $pct = $this->allocator()->ownershipPercentages([
            1 => 12100.0,
            2 => 40400.0,
            3 => 5000.0,
        ]);

        $this->assertEqualsWithDelta(100.0, array_sum($pct), 1e-6);
        $this->assertEqualsWithDelta(21.043478, $pct[1], 1e-6);
        $this->assertEqualsWithDelta(70.260870, $pct[2], 1e-6);
        $this->assertEqualsWithDelta(8.695652, $pct[3], 1e-6);
    }

    #[Test]
    public function the_acceptance_investor_bears_the_whole_fee_when_they_are_the_only_holder(): void
    {
        // $1,435,000 gross asset value x 1% / 4 = $3,587.50 for the quarter.
        $weights = $this->allocator()->timeWeightedUnits(
            $this->transactions([
                [1, '2023-01-30', 12100.0],
                [1, '2023-03-31', 40400.0],
                [1, '2023-05-01', 5000.0],
            ]),
            Carbon::parse(self::Q2_START),
            Carbon::parse(self::Q2_END),
        );

        $fees = $this->allocator()->allocate(3587.50, $weights);

        $this->assertEqualsWithDelta(57500.0, $weights[1], 1e-9);
        $this->assertSame([1 => 3587.50], $fees);
    }

    #[Test]
    public function nothing_is_allocated_when_nobody_held_the_fund(): void
    {
        $this->assertSame([], $this->allocator()->allocate(5000.0, []));
        $this->assertSame([], $this->allocator()->ownershipPercentages([]));
        $this->assertSame([], $this->allocator()->timeWeightedUnits(
            new Collection(),
            Carbon::parse(self::Q2_START),
            Carbon::parse(self::Q2_END),
        ));
    }

    #[Test]
    public function nothing_is_allocated_when_every_weight_is_zero(): void
    {
        // Would otherwise divide by zero and put a NaN into a fee record.
        $fees = $this->allocator()->allocate(5000.0, [1 => 0.0, 2 => 0.0]);

        $this->assertSame([], $fees);
    }

    #[Test]
    public function it_never_multiplies_a_rate_by_anything(): void
    {
        // The accountant's figure is an input. If this class ever starts
        // deriving the total, it has taken over a job that is deliberately not
        // the website's, and the number shown would stop matching the books.
        $source = file_get_contents(base_path('app/Services/FeeAllocator.php'));

        $this->assertStringNotContainsString('aum_fee_annual_pct', $source);
        $this->assertStringNotContainsString('0.01', $source);
        $this->assertStringNotContainsString('/ 4', $source);
    }

    private function allocator(): FeeAllocator
    {
        return new FeeAllocator();
    }

    /** @param  array<int, array{0: int, 1: string, 2: float}>  $rows */
    private function transactions(array $rows): Collection
    {
        return new Collection(array_map(function (array $r, int $i) {
            $t = new FundTransaction();
            $t->id = $i + 1;
            $t->investor_id = $r[0];
            $t->transaction_date = Carbon::parse($r[1]);
            $t->units = $r[2];
            $t->type = $r[2] >= 0
                ? FundTransaction::TYPE_SUBSCRIPTION
                : FundTransaction::TYPE_REDEMPTION;

            return $t;
        }, $rows, array_keys($rows)));
    }
}
