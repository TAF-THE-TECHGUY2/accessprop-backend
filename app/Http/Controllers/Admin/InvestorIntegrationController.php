<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\InvestorResource;
use App\Models\Investor;
use App\Services\InvestorProcessingService;
use Illuminate\Http\JsonResponse;

class InvestorIntegrationController extends Controller
{
    public function __construct(private readonly InvestorProcessingService $processing)
    {
    }

    /**
     * Return the InvestReady connection summary for an investor.
     */
    public function showInvestReady(string $code): JsonResponse
    {
        $investor = Investor::where('code', $code)->firstOrFail();

        return response()->json(
            $this->processing->investReadyConnectionSummary($investor)
        );
    }

    /**
     * Trigger a fresh fetch from InvestReady using the stored access_token
     * (refreshing it via refresh_token if it has expired).
     */
    public function resyncInvestReady(string $code): JsonResponse
    {
        $investor = Investor::where('code', $code)->firstOrFail();

        $updated = $this->processing->refreshInvestReadyVerification($investor);
        $summary = $this->processing->investReadyConnectionSummary($updated);

        return response()->json([
            'investor' => (new InvestorResource($updated))->resolve(),
            'investready' => $summary,
        ]);
    }

    /**
     * Return the Stripe funding summary for an investor.
     */
    public function showStripe(string $code): JsonResponse
    {
        $investor = Investor::where('code', $code)->firstOrFail();

        return response()->json(
            $this->processing->stripeFundingSummary($investor)
        );
    }
}
