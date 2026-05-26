<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\InvestorResource;
use App\Models\Investor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class KycController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'kycStatus' => ['nullable', 'string'],
            'documentType' => ['nullable', 'string'],
            'accreditationStatus' => ['nullable', 'string'],
            'submittedDate' => ['nullable', 'date'],
        ]);

        $query = Investor::with(['documents', 'notes' => fn ($q) => $q->orderBy('created_at')])
            ->orderByDesc('joined_at');

        if (! empty($filters['kycStatus'])) {
            $query->where('kyc_status', $filters['kycStatus']);
        }
        if (! empty($filters['accreditationStatus'])) {
            $query->where('accreditation_status', $filters['accreditationStatus']);
        }

        $items = $query->get()->map(function (Investor $investor) {
            $latestDoc = $investor->documents->sortByDesc('submitted_at')->first();
            $firstNote = $investor->notes->first();

            $averageHours = match ($investor->kyc_status) {
                'approved' => 18,
                'rejected' => 26,
                default => 31,
            };

            return [
                'id' => $investor->code,
                'investorId' => $investor->code,
                'investorName' => $investor->name,
                'investorEmail' => $investor->email,
                'accreditationStatus' => $investor->accreditation_status,
                'documentType' => $latestDoc?->type ?? 'Profile Review',
                'submittedDate' => optional($latestDoc?->submitted_at ?? $investor->joined_at)->toIso8601String(),
                'kycStatus' => $investor->kyc_status,
                'documents' => $investor->documents->sortByDesc('submitted_at')->values()->map(fn ($doc) => [
                    'id' => $doc->code,
                    'type' => $doc->type,
                    'fileName' => $doc->file_name,
                    'submittedAt' => optional($doc->submitted_at)->toIso8601String(),
                    'status' => $doc->status,
                ])->values(),
                'reviewNotes' => $firstNote?->body ?? 'Awaiting reviewer note for this submission.',
                'averageReviewHours' => $averageHours,
                'country' => $investor->country,
                'phone' => $investor->phone,
            ];
        });

        if (! empty($filters['documentType'])) {
            $items = $items->filter(fn ($item) => $item['documentType'] === $filters['documentType']);
        }
        if (! empty($filters['submittedDate'])) {
            $target = substr($filters['submittedDate'], 0, 10);
            $items = $items->filter(fn ($item) => is_string($item['submittedDate']) && str_starts_with($item['submittedDate'], $target));
        }
        $items = $items->values();

        $summary = [
            'pendingReview' => $items->where('kycStatus', 'pending')->count(),
            'submitted' => $items->where('kycStatus', 'submitted')->count(),
            'approved' => $items->where('kycStatus', 'approved')->count(),
            'rejected' => $items->where('kycStatus', 'rejected')->count(),
            'averageReviewTime' => '21h',
        ];

        return response()->json([
            'summary' => $summary,
            'items' => $items,
        ]);
    }

    public function review(Request $request, string $code): InvestorResource
    {
        $payload = $request->validate([
            'kycStatus' => ['required', 'string', 'in:pending,submitted,approved,rejected'],
        ]);

        $investor = Investor::where('code', $code)->firstOrFail();

        $updates = [
            'kyc_status' => $payload['kycStatus'],
            'dashboard_status' => match ($payload['kycStatus']) {
                'approved' => 'active',
                'rejected' => 'inactive',
                default => 'pending',
            },
        ];

        if ($payload['kycStatus'] === 'approved') {
            $updates['investment_status'] = $investor->accreditation_status === 'accredited'
                ? 'awaiting_accreditation_verification'
                : 'pending_partner_review';
        }

        if ($payload['kycStatus'] === 'rejected') {
            $updates['investment_status'] = 'inactive';
        }

        $investor->update($updates);

        $investor->activities()->create([
            'code' => 'act-'.$investor->code.'-'.now()->timestamp,
            'title' => 'KYC '.$payload['kycStatus'],
            'description' => 'Compliance reviewer set KYC status to '.$payload['kycStatus'].'.',
            'occurred_at' => now(),
        ]);

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
