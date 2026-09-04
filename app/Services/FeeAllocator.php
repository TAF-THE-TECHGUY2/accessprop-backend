<?php

namespace App\Services;

use App\Models\FundTransaction;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Splits a quarterly fee the accountant has already calculated across the
 * investors who held the fund during that quarter.
 *
 * The fund manager's instruction is explicit: the accountant computes the fee
 * from the fund's gross asset value after quarter end, that figure is entered
 * by hand, and the website's only job is to divide it pro rata by ownership for
 * the quarter. It must not recompute or override the total. That is why nothing
 * here multiplies a rate by anything — the total is an input.
 *
 * Two decisions worth stating.
 *
 * Ownership is time-weighted across the quarter, not measured at quarter end.
 * "Ownership of the fund for that quarter" reads as ownership during it, and
 * the ledger has exact deposit dates, so this is computable rather than
 * estimated. Measuring at quarter end would charge a full quarter's fee to
 * someone who invested on its final day.
 *
 * The allocations sum to exactly the entered total. Rounding each share to
 * cents independently leaves a few cents unaccounted for, and a set of investor
 * fees that does not add up to the accountant's figure is the kind of
 * discrepancy that costs more to explain than it does to prevent.
 */
class FeeAllocator
{
    /**
     * Time-weighted average units held per investor over [start, end].
     *
     * A holding that predates the quarter counts for the whole quarter; one
     * opened inside it counts from its deposit date. Redemptions carry negative
     * units and so reduce the weight from the day they settle.
     *
     * @param  Collection<int, FundTransaction>  $transactions  every transaction for the fund
     * @return array<int, float>  investor id => time-weighted average units
     */
    public function timeWeightedUnits(Collection $transactions, CarbonInterface $start, CarbonInterface $end): array
    {
        $periodDays = $start->diffInDays($end) + 1;

        if ($periodDays <= 0) {
            return [];
        }

        $weights = [];

        foreach ($transactions as $t) {
            $date = $t->transaction_date;

            // Settled after the quarter closed: not this quarter's fee.
            if ($date->greaterThan($end)) {
                continue;
            }

            $heldFrom = $date->lessThan($start) ? $start : $date;
            $daysHeld = $heldFrom->diffInDays($end) + 1;

            $investorId = (int) $t->investor_id;
            $weights[$investorId] = ($weights[$investorId] ?? 0.0)
                + ((float) $t->units * $daysHeld / $periodDays);
        }

        // A position redeemed to nothing mid-quarter still has weight for the
        // part of the quarter it existed, so only a non-positive result is
        // dropped — never a small one.
        return array_filter($weights, fn (float $w) => $w > 0);
    }

    /**
     * Divides a total across weights, to the cent, summing to exactly the total.
     *
     * @param  array<int, float>  $weights
     * @return array<int, float>  investor id => fee, in dollars
     */
    public function allocate(float $total, array $weights): array
    {
        $totalWeight = array_sum($weights);

        if ($totalWeight <= 0 || $weights === []) {
            return [];
        }

        // Work in cents: dollars-as-float cannot represent a cent exactly, and
        // a reconciliation that has to land on zero cannot be done in a type
        // that drifts.
        $totalCents = (int) round($total * 100);
        $allocated = [];
        $sum = 0;

        foreach ($weights as $investorId => $weight) {
            $cents = (int) round($totalCents * ($weight / $totalWeight));
            $allocated[$investorId] = $cents;
            $sum += $cents;
        }

        // Hand the rounding remainder — at most a cent per investor — to the
        // largest holder, who is least distorted by it in percentage terms.
        $remainder = $totalCents - $sum;

        if ($remainder !== 0) {
            $largest = array_keys($weights, max($weights))[0];
            $allocated[$largest] += $remainder;
        }

        return array_map(fn (int $cents) => $cents / 100, $allocated);
    }

    /**
     * Ownership share per investor, as a percentage, for display.
     *
     * @param  array<int, float>  $weights
     * @return array<int, float>
     */
    public function ownershipPercentages(array $weights): array
    {
        $totalWeight = array_sum($weights);

        if ($totalWeight <= 0) {
            return [];
        }

        return array_map(
            fn (float $w) => round(($w / $totalWeight) * 100, 6),
            $weights,
        );
    }
}
