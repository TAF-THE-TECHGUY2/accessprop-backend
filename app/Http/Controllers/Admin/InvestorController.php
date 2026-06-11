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

    /**
     * Admin-create an investor. Defaults all status fields to pending —
     * the new investor goes through the regular onboarding tracker when
     * they log in. Use the override panel if you need to bypass steps.
     */
    public function store(Request $request): InvestorResource
    {
        $data = $request->validate([
            'firstName' => ['required', 'string', 'max:100'],
            'lastName' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255', 'unique:investors,email'],
            'password' => ['required', 'string', 'min:8', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'addressLine1' => ['required', 'string', 'max:255'],
            'addressLine2' => ['nullable', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:100'],
            'stateProvince' => ['required', 'string', 'max:100'],
            'zipPostalCode' => ['required', 'string', 'max:20'],
            'country' => ['required', 'string', 'max:100'],
            'investorType' => ['required', 'string', 'in:Individual,LLC,Trust,Corporation'],
            'entityName' => ['nullable', 'string', 'max:255'],
            'accreditationStatus' => ['required', 'string', 'in:accredited,non_accredited'],
            'commitment' => ['required', 'numeric', 'min:10000'],
            'fundCode' => ['required', 'string', 'exists:funds,code'],
        ]);

        if ($data['investorType'] !== 'Individual' && empty($data['entityName'])) {
            return abort(422, 'entityName is required for non-Individual investor types.');
        }

        $fund = \App\Models\Fund::where('code', $data['fundCode'])->firstOrFail();

        // Generate the next sequential investor code.
        $maxNum = (int) Investor::query()
            ->selectRaw("MAX(CAST(SUBSTRING(code, 5) AS UNSIGNED)) as max_num")
            ->value('max_num');
        $nextNum = max(1000, $maxNum) + 1;
        $code = sprintf('inv-%04d', $nextNum);

        $investor = Investor::create([
            'code' => $code,
            'name' => trim($data['firstName'].' '.$data['lastName']),
            'email' => $data['email'],
            'password' => $data['password'],   // model cast hashes it
            'phone' => $data['phone'] ?? null,
            'country' => $data['country'],
            'joined_at' => now(),
            'investment_amount' => $data['commitment'],
            'investment_commitment' => $data['commitment'],
            'investment_funded' => 0,
            'investment_fund_name' => $fund->name,
            'investment_wallet_status' => 'Awaiting capital call',
            'investment_expected_yield' => $fund->target_yield ?? '8.0% target',
            'accreditation_status' => $data['accreditationStatus'],
            'accreditation_verification_status' => 'not_started',
            'kyc_status' => 'pending',
            'investment_status' => 'awaiting_kyc',
            'dashboard_status' => 'pending',
            'document_signing_status' => 'not_started',
            'address_line1' => $data['addressLine1'],
            'address_line2' => $data['addressLine2'] ?? null,
            'address_city' => $data['city'],
            'address_state' => $data['stateProvince'],
            'address_postal_code' => $data['zipPostalCode'],
            'address_country' => $data['country'],
            'personal_investor_type' => $data['investorType'],
            'personal_entity_name' => $data['investorType'] !== 'Individual'
                ? $data['entityName']
                : null,
            'personal_residency' => $data['country'] === 'United States'
                ? 'U.S. Person'
                : 'Non-U.S. Person',
        ]);

        $investor->activities()->create([
            'code' => 'act-'.$investor->code.'-created',
            'title' => 'Investor created by admin',
            'description' => 'Admin manually created this investor profile via the admin panel.',
            'occurred_at' => now(),
        ]);

        $investor->load([
            'documents', 'activities', 'messages', 'notes',
            'integrationRequests', 'fundingInstructions',
            'paymentConfirmations', 'partnerMatches', 'activityLogs',
        ]);

        return new InvestorResource($investor);
    }

    /**
     * Delete an investor and all of their related records. Cascades via FK
     * constraints. Requires `confirm` to match the investor code exactly.
     */
    public function destroy(Request $request, string $code): JsonResponse
    {
        $request->validate([
            'confirm' => ['required', 'string'],
        ]);

        if ($request->input('confirm') !== $code) {
            return response()->json([
                'message' => 'Confirmation code does not match.',
            ], 422);
        }

        $investor = Investor::where('code', $code)->firstOrFail();

        $impact = [
            'documents' => $investor->documents()->count(),
            'activities' => $investor->activities()->count(),
            'messages' => $investor->messages()->count(),
            'notes' => $investor->notes()->count(),
            'holdings' => $investor->holdings()->count(),
            'integrationRequests' => $investor->integrationRequests()->count(),
            'fundingInstructions' => $investor->fundingInstructions()->count(),
            'paymentConfirmations' => $investor->paymentConfirmations()->count(),
        ];

        // Sanctum tokens live in personal_access_tokens via polymorphic relation
        // (no FK cascade). Clear them explicitly so the deletion is clean.
        $investor->tokens()->delete();

        $email = $investor->email;
        $investor->delete();

        return response()->json([
            'message' => 'Investor deleted.',
            'code' => $code,
            'email' => $email,
            'cascadeImpact' => $impact,
        ]);
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
