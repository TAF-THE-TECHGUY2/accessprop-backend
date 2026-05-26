<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\InvestorResource;
use App\Models\Investor;
use App\Services\InvestorProcessingService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class InvestorProcessingController extends Controller
{
    public function __construct(private readonly InvestorProcessingService $processingService)
    {
    }

    public function recordPersonaCompletion(Request $request, string $code): InvestorResource
    {
        $investor = Investor::where('code', $code)->firstOrFail();

        $validated = $request->validate([
            'inquiryId' => ['required', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'max:64'],
        ]);

        $updated = $this->processingService->applyPersonaInquiryResult(
            $investor,
            $validated['inquiryId'],
            $validated['status'] ?? null,
        );

        return new InvestorResource($updated);
    }

    public function handle(Request $request, string $code, string $action): InvestorResource
    {
        $investor = Investor::where('code', $code)->firstOrFail();

        $validated = $request->validate([
            'referenceId' => ['nullable', 'string', 'max:255'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'proofFileUrl' => ['nullable', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'action' => ['sometimes', Rule::in([
                'start-persona-verification',
                'start-verifyinvestor-review',
                'send-docusign-documents',
                'approve-legal-review',
                'reject-legal-review',
                'release-funding-instructions',
                'mark-funds-sent',
                'confirm-funds-received',
                'generate-partner-redirect',
                'mark-redirected-to-partner',
                'add-partner-reference',
                'mark-partner-match-pending',
                'confirm-partner-match',
                'activate-investment',
            ])],
        ]);

        $updatedInvestor = match ($action) {
            'start-persona-verification' => $this->processingService->startPersonaVerification($investor),
            'start-verifyinvestor-review' => $this->processingService->startVerifyInvestorReview($investor),
            'send-docusign-documents' => $this->processingService->sendDocusignDocuments($investor),
            'approve-legal-review' => $this->processingService->approveLegalReview($investor),
            'reject-legal-review' => $this->processingService->rejectLegalReview($investor, $validated['reason'] ?? null),
            'release-funding-instructions' => $this->processingService->releaseFundingInstructions($investor),
            'mark-funds-sent' => $this->processingService->markFundsSent($investor, $validated),
            'confirm-funds-received' => $this->processingService->confirmFundsReceived($investor),
            'generate-partner-redirect' => $this->processingService->generatePartnerRedirect($investor),
            'mark-redirected-to-partner' => $this->processingService->markRedirectedToPartner($investor),
            'add-partner-reference' => $this->processingService->addPartnerReferenceId(
                $investor,
                $validated['referenceId'] ?? 'partner-ref-'.$investor->code
            ),
            'mark-partner-match-pending' => $this->processingService->markPartnerMatchPending($investor),
            'confirm-partner-match' => $this->processingService->confirmPartnerMatch($investor),
            'activate-investment' => $this->processingService->activateInvestment($investor),
            default => abort(404),
        };

        return new InvestorResource($updatedInvestor);
    }
}
