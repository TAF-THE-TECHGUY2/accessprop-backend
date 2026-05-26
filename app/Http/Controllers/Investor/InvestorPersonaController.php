<?php

namespace App\Http\Controllers\Investor;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\InvestorResource;
use App\Services\InvestorProcessingService;
use Illuminate\Http\Request;

class InvestorPersonaController extends Controller
{
    public function __construct(private readonly InvestorProcessingService $processing)
    {
    }

    public function start(Request $request): InvestorResource
    {
        return new InvestorResource(
            $this->processing->startPersonaVerification($request->user())
        );
    }

    public function complete(Request $request): InvestorResource
    {
        $validated = $request->validate([
            'inquiryId' => ['required', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'max:64'],
        ]);

        return new InvestorResource(
            $this->processing->applyPersonaInquiryResult(
                $request->user(),
                $validated['inquiryId'],
                $validated['status'] ?? null,
            )
        );
    }
}
