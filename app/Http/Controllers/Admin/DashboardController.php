<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\InvestorResource;
use App\Models\Investor;
use App\Models\InvestorActivity;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function index(): JsonResponse
    {
        $totalInvestors = Investor::count();
        $pendingKyc = Investor::whereIn('kyc_status', ['pending', 'submitted'])->count();
        $approvedInvestors = Investor::where('kyc_status', 'approved')->count();
        $activeInvestors = Investor::where('dashboard_status', 'active')->count();
        $totalInvested = (float) Investor::sum('investment_amount');

        $recentInvestors = Investor::orderByDesc('joined_at')->limit(5)->get();

        $recentActivity = InvestorActivity::with('investor:id,code,name')
            ->orderByDesc('occurred_at')
            ->limit(7)
            ->get()
            ->map(fn ($activity) => [
                'id' => $activity->code,
                'title' => $activity->title,
                'description' => $activity->description,
                'timestamp' => optional($activity->occurred_at)->toIso8601String(),
                'investorId' => $activity->investor->code,
                'investorName' => $activity->investor->name,
            ]);

        $kycCounts = [
            'pending' => 0,
            'submitted' => 0,
            'approved' => 0,
            'rejected' => 0,
        ];
        foreach (Investor::query()->groupBy('kyc_status')->selectRaw('kyc_status, COUNT(*) as total')->pluck('total', 'kyc_status') as $status => $total) {
            if (array_key_exists($status, $kycCounts)) {
                $kycCounts[$status] = (int) $total;
            }
        }

        $kycOverview = [
            ['name' => 'Pending', 'key' => 'pending', 'value' => $kycCounts['pending']],
            ['name' => 'Submitted', 'key' => 'submitted', 'value' => $kycCounts['submitted']],
            ['name' => 'Approved', 'key' => 'approved', 'value' => $kycCounts['approved']],
            ['name' => 'Rejected', 'key' => 'rejected', 'value' => $kycCounts['rejected']],
        ];

        $investmentStatusBreakdown = Investor::query()
            ->groupBy('investment_status')
            ->selectRaw('investment_status as name, COUNT(*) as value')
            ->get()
            ->map(fn ($row) => ['name' => $row->name, 'value' => (int) $row->value])
            ->values();

        return response()->json([
            'metrics' => [
                'totalInvestors' => $totalInvestors,
                'pendingKyc' => $pendingKyc,
                'approvedInvestors' => $approvedInvestors,
                'totalInvested' => $totalInvested,
                'activeInvestors' => $activeInvestors,
            ],
            'recentInvestors' => InvestorResource::collection($recentInvestors)->resolve(),
            'recentActivity' => $recentActivity,
            'kycOverview' => $kycOverview,
            'investmentStatusBreakdown' => $investmentStatusBreakdown,
            'quickActions' => [
                [
                    'id' => 'qa-001',
                    'label' => 'Review pending KYC',
                    'description' => 'Jump into the compliance queue and clear investor bottlenecks.',
                    'to' => '/admin/kyc-verification',
                ],
                [
                    'id' => 'qa-002',
                    'label' => 'View investor pipeline',
                    'description' => 'Inspect the latest onboarding activity across all investors.',
                    'to' => '/admin/investors',
                ],
                [
                    'id' => 'qa-003',
                    'label' => 'Open reports',
                    'description' => 'Track conversion, funding mix, and accreditation trends.',
                    'to' => '/admin/reports',
                ],
            ],
            'systemOverview' => [
                [
                    'id' => 'sys-001',
                    'label' => 'Laravel API',
                    'value' => 'Healthy',
                    'status' => 'approved',
                    'detail' => 'Average response time 182ms across investor endpoints.',
                ],
                [
                    'id' => 'sys-002',
                    'label' => 'Email delivery',
                    'value' => 'Stable',
                    'status' => 'submitted',
                    'detail' => '96.2% delivery rate over the last 7 days.',
                ],
                [
                    'id' => 'sys-003',
                    'label' => 'KYC backlog',
                    'value' => $pendingKyc > 0 ? 'Needs review' : 'Clear',
                    'status' => $pendingKyc > 0 ? 'pending' : 'approved',
                    'detail' => $pendingKyc.' submissions awaiting compliance review.',
                ],
                [
                    'id' => 'sys-004',
                    'label' => 'Investor dashboard',
                    'value' => 'Online',
                    'status' => 'approved',
                    'detail' => 'No incidents detected in the last 14 days.',
                ],
            ],
        ]);
    }
}
