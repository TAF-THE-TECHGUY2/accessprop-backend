<?php

namespace App\Http\Controllers\Investor;

use App\Http\Controllers\Controller;
use App\Models\Fund;
use App\Models\FundHolding;
use App\Models\FundTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class InvestorPortalInvestmentController extends Controller
{
    public function portfolio(Request $request): JsonResponse
    {
        $investor = $request->user();

        $holdings = $investor->holdings()
            ->with(['fund.unitPrices'])
            ->get();

        $totalInvested = 0;
        $totalCurrentValue = 0;
        $totalDistributions = 0;

        foreach ($holdings as $holding) {
            $latestPrice = $holding->fund->unitPrices->sortByDesc('as_of_date')->first();
            $price = (float) ($latestPrice->price ?? $holding->average_unit_price);
            $value = (float) $holding->units * $price;

            $totalInvested += (float) $holding->amount_invested;
            $totalCurrentValue += $value;
            $totalDistributions += $holding->totalDistributions();
        }

        $totalGainLoss = $totalCurrentValue - $totalInvested;
        $totalGainLossPct = $totalInvested > 0 ? ($totalGainLoss / $totalInvested) * 100 : 0;
        $totalReturnPct = $totalInvested > 0
            ? (($totalCurrentValue + $totalDistributions - $totalInvested) / $totalInvested) * 100
            : 0;

        return response()->json([
            'totalInvested' => round($totalInvested, 2),
            'totalCurrentValue' => round($totalCurrentValue, 2),
            'totalDistributions' => round($totalDistributions, 2),
            'totalGainLoss' => round($totalGainLoss, 2),
            'totalGainLossPct' => round($totalGainLossPct, 2),
            'totalReturnPct' => round($totalReturnPct, 2),
            'holdingsCount' => $holdings->count(),
        ]);
    }

    public function holdings(Request $request): JsonResponse
    {
        $investor = $request->user();

        $holdings = $investor->holdings()
            ->with(['fund.unitPrices'])
            ->get();

        $totalCurrentValue = 0;
        foreach ($holdings as $h) {
            $price = (float) ($h->fund->unitPrices->sortByDesc('as_of_date')->first()->price ?? $h->average_unit_price);
            $totalCurrentValue += (float) $h->units * $price;
        }

        $result = $holdings->map(function (FundHolding $holding) use ($totalCurrentValue) {
            $currentPrice = (float) ($holding->fund->unitPrices->sortByDesc('as_of_date')->first()->price ?? $holding->average_unit_price);
            $currentValue = (float) $holding->units * $currentPrice;
            $invested = (float) $holding->amount_invested;
            $distributions = $holding->totalDistributions();
            $gainLoss = $currentValue - $invested;
            $gainLossPct = $invested > 0 ? ($gainLoss / $invested) * 100 : 0;
            $totalReturnPct = $invested > 0
                ? (($currentValue + $distributions - $invested) / $invested) * 100
                : 0;

            $years = $holding->first_invested_at
                ? max(0.01, $holding->first_invested_at->floatDiffInYears(now()))
                : 1;
            $annualisedFactor = $invested > 0
                ? ($currentValue + $distributions) / $invested
                : 1;
            $annualizedReturnPct = $annualisedFactor > 0
                ? (pow($annualisedFactor, 1 / $years) - 1) * 100
                : 0;

            $entry = $this->entrySummary($holding);

            return [
                'fundCode' => $holding->fund->code,
                'fundName' => $holding->fund->name,
                'fundType' => $holding->fund->fund_type,
                'targetYield' => $holding->fund->target_yield,
                'amountInvested' => round($invested, 2),
                // Ledger-derived entry data. Weighted across every subscription,
                // so a second investment at a different premium is reflected.
                'entryPrice' => $entry['entryPrice'],
                'entryBookValue' => $entry['entryBookValue'],
                'premiumPct' => $entry['premiumPct'],
                'premiumPaid' => $entry['premiumPaid'],
                'firstTransactionDate' => $entry['firstTransactionDate'],
                'transactionCount' => $entry['transactionCount'],
                'currentUnitPrice' => round($currentPrice, 4),
                'totalUnits' => round((float) $holding->units, 6),
                'percentOfPortfolio' => $totalCurrentValue > 0 ? round(($currentValue / $totalCurrentValue) * 100, 2) : 0,
                'currentValue' => round($currentValue, 2),
                'totalDistributions' => round($distributions, 2),
                'gainLoss' => round($gainLoss, 2),
                'gainLossPct' => round($gainLossPct, 2),
                'totalReturnPct' => round($totalReturnPct, 2),
                'annualizedReturnPct' => round($annualizedReturnPct, 2),
                'aumFees' => round($holding->totalAumFees(), 2),
                'performanceFees' => round($holding->totalPerformanceFees(), 2),
                'firstInvestedAt' => optional($holding->first_invested_at)->toIso8601String(),
            ];
        });

        return response()->json(['data' => $result]);
    }

    /**
     * How the investor entered this position, read from the ledger.
     *
     * Weighted across every inflow so a second subscription at a different
     * premium is reflected rather than hidden behind an average.
     *
     * premiumPaid is the dollar gap between what was paid and what those units
     * were worth at book value on the day — which is exactly the day-one paper
     * loss the portal has to explain.
     */
    private function entrySummary(FundHolding $holding): array
    {
        $inflows = FundTransaction::query()
            ->where('investor_id', $holding->investor_id)
            ->where('fund_id', $holding->fund_id)
            ->whereIn('type', FundTransaction::INFLOW_TYPES)
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->get();

        if ($inflows->isEmpty()) {
            return [
                'entryPrice' => null,
                'entryBookValue' => null,
                'premiumPct' => null,
                'premiumPaid' => null,
                'firstTransactionDate' => null,
                'transactionCount' => 0,
            ];
        }

        $units = (float) $inflows->sum('units');
        $paid = (float) $inflows->sum('gross_amount');

        // Value of those units at the book value ruling when each was bought.
        $bookCost = (float) $inflows->reduce(
            fn ($carry, FundTransaction $t) => $carry + ((float) $t->units * (float) $t->book_value_at_purchase),
            0.0
        );

        return [
            'entryPrice' => $units > 0 ? round($paid / $units, 4) : null,
            'entryBookValue' => $units > 0 ? round($bookCost / $units, 4) : null,
            'premiumPct' => $bookCost > 0 ? round((($paid - $bookCost) / $bookCost) * 100, 3) : 0.0,
            'premiumPaid' => round($paid - $bookCost, 2),
            'firstTransactionDate' => $inflows->first()->transaction_date->toDateString(),
            'transactionCount' => $inflows->count(),
        ];
    }

    public function performance(Request $request, string $fundCode): JsonResponse
    {
        $range = $request->query('range', '1Y');
        $investor = $request->user();

        $holding = $investor->holdings()
            ->whereHas('fund', fn ($q) => $q->where('code', $fundCode))
            ->with('fund.unitPrices')
            ->firstOrFail();

        $cutoff = match ($range) {
            '1M' => now()->subMonth(),
            '3M' => now()->subMonths(3),
            '6M' => now()->subMonths(6),
            '1Y' => now()->subYear(),
            default => Carbon::parse('1900-01-01'),
        };

        // Units held at each price date, from the ledger — not the current total.
        // Using today's unit count for every historical point back-dates units the
        // investor did not own yet, inflating the whole early series. It happens
        // to look right for a single subscription and breaks on the second.
        $ledger = FundTransaction::query()
            ->where('investor_id', $holding->investor_id)
            ->where('fund_id', $holding->fund_id)
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->get(['transaction_date', 'units']);

        // No position, no series — plotting prices against zero units is noise.
        if ($ledger->isEmpty()) {
            return response()->json(['range' => $range, 'points' => []]);
        }

        $entryDate = $ledger->first()->transaction_date;

        $points = $holding->fund->unitPrices
            ->where('as_of_date', '>=', $cutoff)
            // Nothing before the investor entered. They held no units then, so a
            // value for that date would be fabricated.
            ->filter(fn ($p) => $p->as_of_date->gte($entryDate))
            ->sortBy('as_of_date')
            ->values()
            ->map(function ($p) use ($ledger) {
                $unitsHeld = (float) $ledger
                    ->filter(fn ($t) => $t->transaction_date->lte($p->as_of_date))
                    ->sum('units');

                return [
                    'date' => $p->as_of_date->toDateString(),
                    'quarter' => $p->quarter_label,
                    'price' => (float) $p->price,
                    'units' => round($unitsHeld, 6),
                    'value' => round($unitsHeld * (float) $p->price, 2),
                ];
            });

        return response()->json([
            'range' => $range,
            'entryDate' => $entryDate->toDateString(),
            'points' => $points->values(),
        ]);
    }

    public function priceHistory(Request $request, string $fundCode): JsonResponse
    {
        $fund = Fund::where('code', $fundCode)->firstOrFail();

        $prices = $fund->unitPrices()
            ->orderByDesc('as_of_date')
            ->get(['as_of_date', 'price', 'quarter_label'])
            ->map(fn ($p) => [
                'date' => $p->as_of_date->toDateString(),
                'quarter' => $p->quarter_label,
                'price' => (float) $p->price,
            ]);

        return response()->json(['data' => $prices]);
    }

    public function distributions(Request $request, string $fundCode): JsonResponse
    {
        $investor = $request->user();
        $holding = $investor->holdings()
            ->whereHas('fund', fn ($q) => $q->where('code', $fundCode))
            ->firstOrFail();

        $distributions = $holding->distributions()
            ->get()
            ->map(fn ($d) => [
                'date' => optional($d->paid_at)->toIso8601String(),
                'amount' => (float) $d->amount,
                'type' => $d->distribution_type,
                'notes' => $d->notes,
            ]);

        return response()->json([
            'data' => $distributions,
            'total' => round($distributions->sum('amount'), 2),
        ]);
    }

    public function fees(Request $request, string $fundCode): JsonResponse
    {
        $investor = $request->user();
        $holding = $investor->holdings()
            ->whereHas('fund', fn ($q) => $q->where('code', $fundCode))
            ->firstOrFail();

        $fees = $holding->fees()->get();

        // Disclosure requires three figures, not one: the rate, what was charged
        // for the most recent period, and the cumulative total to date.
        $aumFees = $fees->where('fee_type', 'aum')->sortByDesc('period_end');
        $latestAum = $aumFees->first();

        return response()->json([
            'aumRatePct' => (float) $holding->fund->aum_fee_annual_pct,
            'aumCurrentPeriod' => $latestAum ? [
                'amount' => (float) $latestAum->amount,
                'periodStart' => $latestAum->period_start->toDateString(),
                'periodEnd' => $latestAum->period_end->toDateString(),
            ] : null,
            'aum' => $fees->where('fee_type', 'aum')->map(fn ($f) => [
                'amount' => (float) $f->amount,
                'periodStart' => $f->period_start->toDateString(),
                'periodEnd' => $f->period_end->toDateString(),
                'description' => $f->description,
            ])->values(),
            'performance' => $fees->where('fee_type', 'performance')->map(fn ($f) => [
                'amount' => (float) $f->amount,
                'periodStart' => $f->period_start->toDateString(),
                'periodEnd' => $f->period_end->toDateString(),
                'description' => $f->description,
            ])->values(),
            'totalAum' => round($fees->where('fee_type', 'aum')->sum('amount'), 2),
            'totalPerformance' => round($fees->where('fee_type', 'performance')->sum('amount'), 2),
        ]);
    }
}
