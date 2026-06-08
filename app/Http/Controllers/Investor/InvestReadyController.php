<?php

namespace App\Http\Controllers\Investor;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\InvestorResource;
use App\Models\IntegrationRequest;
use App\Services\InvestorProcessingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InvestReadyController extends Controller
{
    public function __construct(private readonly InvestorProcessingService $processing)
    {
    }

    /**
     * Kick off an InvestReady Connect authorization. Returns the URL the
     * browser should be redirected to.
     */
    public function start(Request $request): JsonResponse
    {
        $investor = $request->user();
        $investor = $this->processing->startVerifyInvestorReview($investor);

        // The most recent integration_requests row for this investor holds
        // the authorize URL we just generated.
        $latest = IntegrationRequest::query()
            ->where('investor_profile_id', $investor->id)
            ->where('provider', 'investready')
            ->latest('id')
            ->first();

        return response()->json([
            'investor' => (new InvestorResource($investor))->resolve(),
            'authorizationUrl' => $latest?->external_url,
            'state' => $latest?->external_id,
        ]);
    }

    /**
     * Browser-side callback delivers ?code & ?state back to us via this
     * endpoint; we exchange the code for tokens and pull the verification
     * status.
     */
    public function exchange(Request $request): InvestorResource
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:2000'],
            'state' => ['required', 'string', 'max:128'],
        ]);

        $updated = $this->processing->completeInvestReadyVerification(
            $request->user(),
            $validated['code'],
            $validated['state'],
        );

        return new InvestorResource($updated);
    }
}
