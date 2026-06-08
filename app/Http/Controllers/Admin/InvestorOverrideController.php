<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\InvestorResource;
use App\Models\Investor;
use App\Services\InvestorProcessingService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Manual override / bypass actions for admin operators.
 * Every action requires a written reason and is recorded in both
 * investor_activities and integration_requests (provider="manual") for audit.
 */
class InvestorOverrideController extends Controller
{
    public function __construct(private readonly InvestorProcessingService $processing)
    {
    }

    public function handle(Request $request, string $code, string $action): InvestorResource
    {
        $validated = $request->validate([
            'action' => ['sometimes', Rule::in([
                'approve-kyc',
                'approve-accreditation',
                'mark-documents-signed',
                'mark-funded',
                'fully-activate',
            ])],
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
            'amount' => ['nullable', 'numeric', 'min:0.01'],
        ]);

        if (! in_array($action, [
            'approve-kyc',
            'approve-accreditation',
            'mark-documents-signed',
            'mark-funded',
            'fully-activate',
        ], true)) {
            abort(404, 'Unknown override action');
        }

        $investor = Investor::where('code', $code)->firstOrFail();
        $admin = $request->user();
        $reason = $validated['reason'];
        $amount = $validated['amount'] ?? null;

        $updated = match ($action) {
            'approve-kyc' => $this->processing->overrideKycApproval($investor, $reason, $admin),
            'approve-accreditation' => $this->processing->overrideAccreditationApproval($investor, $reason, $admin),
            'mark-documents-signed' => $this->processing->overrideDocumentSigning($investor, $reason, $admin),
            'mark-funded' => $this->processing->overrideMarkFunded(
                $investor,
                $amount ?: (float) ($investor->investment_commitment ?: 0),
                $reason,
                $admin
            ),
            'fully-activate' => $this->processing->overrideFullyActivate($investor, $amount, $reason, $admin),
        };

        return new InvestorResource($updated);
    }
}
