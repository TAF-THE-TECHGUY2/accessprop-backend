<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FundTransaction;
use App\Models\Investor;
use Illuminate\Http\JsonResponse;

class ReportsController extends Controller
{
    public function index(): JsonResponse
    {
        $newInvestors = Investor::where('joined_at', '>=', now()->subDays(30))->count();
        $totalInvestors = Investor::count();
        $kycApproved = Investor::where('kyc_status', 'approved')->count();

        // Capital actually contributed, per investor, from the ledger.
        // investors.investment_amount holds the figure named at signup and is
        // never revised, so reporting off it counts a top-up investor for their
        // first deposit alone.
        $contributed = FundTransaction::query()
            ->whereIn('type', FundTransaction::INFLOW_TYPES)
            ->groupBy('investor_id')
            ->selectRaw('investor_id, SUM(gross_amount) as total')
            ->pluck('total', 'investor_id')
            ->map(fn ($total) => (float) $total);

        $totalInvested = (float) $contributed->sum();

        $conversionRate = $totalInvestors > 0
            ? round(($kycApproved / $totalInvestors) * 100).'%'
            : '0%';

        $buckets = [
            ['range' => '$0-$99k',      'min' => 0,        'max' => 99999.99],
            ['range' => '$100k-$249k',  'min' => 100000,   'max' => 249999.99],
            ['range' => '$250k-$499k',  'min' => 250000,   'max' => 499999.99],
            ['range' => '$500k+',       'min' => 500000,   'max' => null],
        ];

        // Bucketed in PHP rather than with four aggregate queries: the figure
        // being bucketed is itself an aggregate, so there is no column to
        // filter on, and this keeps the query portable across MySQL and SQLite.
        $investmentAmountDistribution = collect($buckets)->map(fn ($bucket) => [
            'range' => $bucket['range'],
            'amount' => round($contributed
                ->filter(fn ($amount) => $amount >= $bucket['min']
                    && ($bucket['max'] === null || $amount <= $bucket['max']))
                ->sum(), 2),
        ])->values();

        $accredited = Investor::where('accreditation_status', 'accredited')->count();
        $nonAccredited = Investor::where('accreditation_status', 'non_accredited')->count();

        return response()->json([
            'stats' => [
                'newInvestors' => $newInvestors,
                'kycApproved' => $kycApproved,
                'totalInvested' => $totalInvested,
                'conversionRate' => $conversionRate,
            ],
            'investmentAmountDistribution' => $investmentAmountDistribution,
            'accreditationStatusChart' => [
                ['name' => 'Accredited', 'value' => $accredited],
                ['name' => 'Non Accredited', 'value' => $nonAccredited],
            ],
        ]);
    }
}
