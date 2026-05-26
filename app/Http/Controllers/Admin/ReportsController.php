<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Investor;
use Illuminate\Http\JsonResponse;

class ReportsController extends Controller
{
    public function index(): JsonResponse
    {
        $newInvestors = Investor::where('joined_at', '>=', now()->subDays(30))->count();
        $totalInvestors = Investor::count();
        $kycApproved = Investor::where('kyc_status', 'approved')->count();
        $totalInvested = (float) Investor::sum('investment_amount');

        $conversionRate = $totalInvestors > 0
            ? round(($kycApproved / $totalInvestors) * 100).'%'
            : '0%';

        $buckets = [
            ['range' => '$0-$99k',      'min' => 0,        'max' => 99999.99],
            ['range' => '$100k-$249k',  'min' => 100000,   'max' => 249999.99],
            ['range' => '$250k-$499k',  'min' => 250000,   'max' => 499999.99],
            ['range' => '$500k+',       'min' => 500000,   'max' => null],
        ];

        $investmentAmountDistribution = collect($buckets)->map(function ($bucket) {
            $query = Investor::where('investment_amount', '>=', $bucket['min']);
            if ($bucket['max'] !== null) {
                $query->where('investment_amount', '<=', $bucket['max']);
            }

            return [
                'range' => $bucket['range'],
                'amount' => (float) $query->sum('investment_amount'),
            ];
        })->values();

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
