<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\InvestorResource;
use App\Models\Investor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class InvestorController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string'],
            'kycStatus' => ['nullable', 'string'],
            'accreditationStatus' => ['nullable', 'string'],
            'accreditationVerificationStatus' => ['nullable', 'string'],
            'investmentStatus' => ['nullable', 'string'],
            'dashboardStatus' => ['nullable', 'string'],
            'documentSigningStatus' => ['nullable', 'string'],
        ]);

        $query = Investor::query();

        if (! empty($filters['search'])) {
            $term = '%'.strtolower($filters['search']).'%';
            $query->where(function ($q) use ($term) {
                $q->whereRaw('LOWER(name) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(email) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(phone) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(country) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(personal_entity_name) LIKE ?', [$term]);
            });
        }

        foreach ([
            'kycStatus' => 'kyc_status',
            'accreditationStatus' => 'accreditation_status',
            'accreditationVerificationStatus' => 'accreditation_verification_status',
            'investmentStatus' => 'investment_status',
            'dashboardStatus' => 'dashboard_status',
            'documentSigningStatus' => 'document_signing_status',
        ] as $param => $column) {
            if (! empty($filters[$param])) {
                $query->where($column, $filters[$param]);
            }
        }

        return InvestorResource::collection(
            $query->orderByDesc('joined_at')->get()
        );
    }

    public function show(string $code): InvestorResource
    {
        $investor = Investor::where('code', $code)
            ->with([
                'documents',
                'activities',
                'messages',
                'notes',
                'integrationRequests',
                'fundingInstructions',
                'paymentConfirmations',
                'partnerMatches',
                'activityLogs',
            ])
            ->firstOrFail();

        return new InvestorResource($investor);
    }

    public function updateStatuses(Request $request, string $code): InvestorResource|JsonResponse
    {
        $payload = $request->validate([
            'kycStatus' => ['nullable', 'string', 'in:pending,submitted,approved,rejected'],
            'accreditationStatus' => ['nullable', 'string', 'in:accredited,non_accredited'],
            'accreditationVerificationStatus' => ['nullable', 'string', 'in:not_started,verification_required,verification_submitted,verification_approved,verification_rejected'],
            'investmentStatus' => ['nullable', 'string', 'in:awaiting_kyc,awaiting_accreditation_verification,awaiting_documents,awaiting_legal_approval,awaiting_funding,funds_sent,funds_confirmed,pending_partner_review,redirected_to_partner,partner_match_pending,partner_match_complete,active,inactive'],
            'dashboardStatus' => ['nullable', 'string', 'in:active,pending,inactive'],
            'documentSigningStatus' => ['nullable', 'string', 'in:not_started,sent,viewed,signed,declined,expired,completed'],
        ]);

        $investor = Investor::where('code', $code)->firstOrFail();

        $columnMap = [
            'kycStatus' => 'kyc_status',
            'accreditationStatus' => 'accreditation_status',
            'accreditationVerificationStatus' => 'accreditation_verification_status',
            'investmentStatus' => 'investment_status',
            'dashboardStatus' => 'dashboard_status',
            'documentSigningStatus' => 'document_signing_status',
        ];

        $updates = [];
        foreach ($payload as $field => $value) {
            if ($value !== null) {
                $updates[$columnMap[$field]] = $value;
            }
        }

        if (($payload['kycStatus'] ?? null) === 'approved') {
            $updates['dashboard_status'] = $updates['dashboard_status'] ?? 'active';
            $updates['investment_status'] = $updates['investment_status']
                ?? ($investor->accreditation_status === 'accredited'
                    ? 'awaiting_accreditation_verification'
                    : 'pending_partner_review');
        }

        if (($payload['kycStatus'] ?? null) === 'rejected') {
            $updates['dashboard_status'] = $updates['dashboard_status'] ?? 'inactive';
            $updates['investment_status'] = $updates['investment_status'] ?? 'inactive';
        }

        if (! empty($updates)) {
            $investor->update($updates);
            $investor->activities()->create([
                'code' => 'act-'.$investor->code.'-'.now()->timestamp,
                'title' => 'Statuses updated',
                'description' => 'Admin adjusted KYC, investment, or dashboard statuses.',
                'occurred_at' => now(),
            ]);
        }

        $investor->load([
            'documents',
            'activities',
            'messages',
            'notes',
            'integrationRequests',
            'fundingInstructions',
            'paymentConfirmations',
            'partnerMatches',
            'activityLogs',
        ]);

        return new InvestorResource($investor);
    }
}
