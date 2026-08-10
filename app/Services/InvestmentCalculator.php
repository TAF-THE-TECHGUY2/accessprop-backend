<?php

namespace App\Services;

use App\Models\FundTransaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Every figure the investor dashboard displays, computed to the fund manager's
 * workbook formulas.
 *
 * Kept free of Eloquent queries and HTTP concerns so it can be verified directly
 * against his spreadsheet, which is the only test that matters here.
 *
 * Three points where the obvious implementation is wrong:
 *
 *  1. Units purchased is the INPUT and unit price is DERIVED — price is
 *     contribution ÷ units. His unit counts are round (12,100 / 40,400 / 5,000)
 *     and the prices fall out uneven (10.00, 10.008152, 10.028164). They are not
 *     published book values.
 *
 *  2. The weighted average holding period is weighted by UNITS, not by
 *     contribution. In his sample that is 3.2770169 rather than 3.2769740 —
 *     small, but it propagates into the annualized return.
 *
 *  3. Holding periods are measured to the unit value's AS-OF DATE, never to
 *     now(), and divided by 365.25. Measuring to today makes every figure drift
 *     daily and stop reconciling.
 */
class InvestmentCalculator
{
    public const DAYS_PER_YEAR = 365.25;

    public function __construct(
        private readonly float $unitValue,
        private readonly Carbon $unitValueAsOf,
    ) {
    }

    /**
     * Per-investment rows plus totals.
     *
     * @param  Collection<int, FundTransaction>  $transactions
     */
    public function compute(Collection $transactions): array
    {
        $inflows = $transactions
            ->filter(fn (FundTransaction $t) => in_array($t->type, FundTransaction::INFLOW_TYPES, true))
            ->sortBy([['transaction_date', 'asc'], ['id', 'asc']])
            ->values();

        if ($inflows->isEmpty()) {
            return ['rows' => [], 'totals' => $this->emptyTotals()];
        }

        $totalContribution = (float) $inflows->sum('gross_amount');
        $totalUnits = (float) $inflows->sum('units');

        $rows = $inflows->map(function (FundTransaction $t) use ($totalContribution, $totalUnits) {
            $contribution = (float) $t->gross_amount;
            $units = (float) $t->units;

            $contributionPct = $totalContribution > 0 ? $contribution / $totalContribution : 0.0;
            $unitPrice = $units > 0 ? $contribution / $units : 0.0;
            $unitsValue = $this->unitValue * $units;
            $gain = $unitsValue - $contribution;
            $gainPct = $contribution > 0 ? $gain / $contribution : 0.0;
            $holdingYears = $this->holdingYears($t->transaction_date);

            return [
                'transactionId' => $t->id,
                'depositDate' => $t->transaction_date->toDateString(),
                'dateOaMipaSigned' => $t->date_oa_mipa_signed?->toDateString(),
                'type' => $t->type,
                'contribution' => round($contribution, 2),
                'contributionPct' => round($contributionPct * 100, 2),
                'units' => round($units, 6),
                'unitsPct' => $totalUnits > 0 ? round(($units / $totalUnits) * 100, 2) : 0.0,
                'unitPrice' => round($unitPrice, 6),
                'unitsValue' => round($unitsValue, 2),
                'gain' => round($gain, 2),
                'gainPct' => round($gainPct * 100, 2),
                'holdingYears' => round($holdingYears, 4),
                'annualizedReturnPct' => round($this->annualized($gainPct, $holdingYears) * 100, 2),
            ];
        })->values();

        // Units-weighted, per his L7. Weighting by contribution instead gives a
        // subtly different number that then skews the annualized return.
        $wahp = $totalUnits > 0
            ? (float) $inflows->reduce(
                fn ($carry, FundTransaction $t) => $carry
                    + (((float) $t->units / $totalUnits) * $this->holdingYears($t->transaction_date)),
                0.0
            )
            : 0.0;

        $totalUnitsValue = $this->unitValue * $totalUnits;
        $totalGain = $totalUnitsValue - $totalContribution;

        // Computed from the totals, not summed from the per-row percentages —
        // averaging percentages of different sizes would be wrong.
        $totalGainPct = $totalContribution > 0 ? $totalGain / $totalContribution : 0.0;

        return [
            'rows' => $rows->all(),
            'totals' => [
                'unitValue' => round($this->unitValue, 6),
                'unitValueAsOf' => $this->unitValueAsOf->toDateString(),
                'contribution' => round($totalContribution, 2),
                'units' => round($totalUnits, 6),
                // total contribution ÷ total units. His sheet's E7 instead sums
                // (share × price), which expands to
                // SUM(contribution² / (total_contribution × units)) — not a
                // weighted mean. It reads 10.008181572 against the correct
                // 10.008176696 on his sample. The divergence is negligible with
                // near-uniform contributions and grows as they spread out.
                'weightedAverageUnitPrice' => $totalUnits > 0
                    ? round($totalContribution / $totalUnits, 6)
                    : 0.0,
                'unitsValue' => round($totalUnitsValue, 2),
                'gain' => round($totalGain, 2),
                'gainPct' => round($totalGainPct * 100, 2),
                'weightedAverageHoldingPeriodYears' => round($wahp, 4),
                'annualizedReturnPct' => round($this->annualized($totalGainPct, $wahp) * 100, 2),
                'investmentCount' => $inflows->count(),
            ],
        ];
    }

    /**
     * Deposit date to the unit value's as-of date, in years of 365.25 days.
     */
    private function holdingYears(Carbon $depositDate): float
    {
        $days = $depositDate->diffInSeconds($this->unitValueAsOf, false) / 86400;

        return $days / self::DAYS_PER_YEAR;
    }

    /**
     * (1 + gain%)^(1/years) − 1.
     *
     * Undefined for a zero or negative holding period, and for a total loss,
     * where the root of a non-positive base has no real value.
     */
    private function annualized(float $gainPct, float $holdingYears): float
    {
        if ($holdingYears <= 0 || (1 + $gainPct) <= 0) {
            return 0.0;
        }

        return pow(1 + $gainPct, 1 / $holdingYears) - 1;
    }

    private function emptyTotals(): array
    {
        return [
            'unitValue' => round($this->unitValue, 6),
            'unitValueAsOf' => $this->unitValueAsOf->toDateString(),
            'contribution' => 0.0,
            'units' => 0.0,
            'weightedAverageUnitPrice' => 0.0,
            'unitsValue' => 0.0,
            'gain' => 0.0,
            'gainPct' => 0.0,
            'weightedAverageHoldingPeriodYears' => 0.0,
            'annualizedReturnPct' => 0.0,
            'investmentCount' => 0,
        ];
    }
}
