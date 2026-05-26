<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvestorResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->code,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'country' => $this->country,
            'joinedAt' => optional($this->joined_at)->toIso8601String(),
            'investmentAmount' => (float) $this->investment_amount,
            'accreditationStatus' => $this->accreditation_status,
            'kycStatus' => $this->kyc_status,
            'accreditationVerificationStatus' => $this->accreditation_verification_status,
            'investmentStatus' => $this->investment_status,
            'dashboardStatus' => $this->dashboard_status,
            'documentSigningStatus' => $this->document_signing_status,
            'pathway' => $this->accreditation_status === 'accredited'
                ? 'Accredited'
                : 'Non-accredited',
            'address' => [
                'line1' => $this->address_line1,
                'line2' => $this->address_line2,
                'city' => $this->address_city,
                'state' => $this->address_state,
                'postalCode' => $this->address_postal_code,
                'country' => $this->address_country,
            ],
            'personalInfo' => [
                'investorType' => $this->personal_investor_type,
                'entityName' => $this->personal_entity_name,
                'taxIdLast4' => $this->personal_tax_id_last4,
                'residency' => $this->personal_residency,
                'experience' => $this->experience,
            ],
            'investmentInfo' => [
                'fundName' => $this->investment_fund_name,
                'commitment' => (float) $this->investment_commitment,
                'funded' => (float) $this->investment_funded,
                'walletStatus' => $this->investment_wallet_status,
                'expectedYield' => $this->investment_expected_yield,
                'lastDistribution' => optional($this->investment_last_distribution)->toIso8601String(),
            ],
            'documents' => $this->whenLoaded('documents', fn () => $this->documents->map(fn ($doc) => [
                'id' => $doc->code ?? (string) $doc->id,
                'type' => $doc->type,
                'source' => $doc->source,
                'provider' => $doc->provider,
                'fileName' => $doc->file_name,
                'fileUrl' => $doc->file_url,
                'submittedAt' => optional($doc->submitted_at ?? $doc->created_at)->toIso8601String(),
                'status' => $doc->status,
                'externalReferenceId' => $doc->external_reference_id,
                'reviewedAt' => optional($doc->reviewed_at)->toIso8601String(),
                'rejectionReason' => $doc->rejection_reason,
            ])),
            'activity' => $this->whenLoaded('activities', fn () => $this->activities->map(fn ($a) => [
                'id' => $a->code,
                'title' => $a->title,
                'description' => $a->description,
                'timestamp' => optional($a->occurred_at)->toIso8601String(),
            ])),
            'messages' => $this->whenLoaded('messages', fn () => $this->messages->map(fn ($m) => [
                'id' => $m->code,
                'subject' => $m->subject,
                'preview' => $m->preview,
                'sentAt' => optional($m->sent_at)->toIso8601String(),
            ])),
            'notes' => $this->whenLoaded('notes', fn () => $this->notes->map(fn ($n) => [
                'id' => $n->code,
                'body' => $n->body,
                'createdAt' => optional($n->created_at)->toIso8601String(),
            ])),
            'processing' => [
                'integrationRequests' => $this->relationLoaded('integrationRequests')
                    ? $this->integrationRequests
                        ->sortByDesc('created_at')
                        ->values()
                        ->map(fn ($request) => [
                        'id' => $request->id,
                        'provider' => $request->provider,
                        'type' => $request->type,
                        'status' => $request->status,
                        'externalId' => $request->external_id,
                        'externalUrl' => $request->external_url,
                        'completedAt' => optional($request->completed_at)->toIso8601String(),
                        'createdAt' => optional($request->created_at)->toIso8601String(),
                    ])->values()
                    : [],
                'fundingInstructions' => $this->relationLoaded('fundingInstructions')
                    ? $this->fundingInstructions
                        ->sortByDesc('created_at')
                        ->values()
                        ->map(fn ($instruction) => [
                        'id' => $instruction->id,
                        'status' => $instruction->status,
                        'instructions' => $instruction->instructions,
                        'deliveryChannel' => $instruction->delivery_channel,
                        'externalUrl' => $instruction->external_url,
                        'releasedAt' => optional($instruction->released_at)->toIso8601String(),
                        'createdAt' => optional($instruction->created_at)->toIso8601String(),
                    ])->values()
                    : [],
                'paymentConfirmations' => $this->relationLoaded('paymentConfirmations')
                    ? $this->paymentConfirmations
                        ->sortByDesc('created_at')
                        ->values()
                        ->map(fn ($payment) => [
                        'id' => $payment->id,
                        'status' => $payment->status,
                        'amount' => (float) $payment->amount,
                        'reference' => $payment->reference,
                        'proofFileUrl' => $payment->proof_file_url,
                        'notes' => $payment->notes,
                        'confirmedAt' => optional($payment->confirmed_at)->toIso8601String(),
                        'createdAt' => optional($payment->created_at)->toIso8601String(),
                    ])->values()
                    : [],
                'partnerMatches' => $this->relationLoaded('partnerMatches')
                    ? $this->partnerMatches
                        ->sortByDesc('created_at')
                        ->values()
                        ->map(fn ($match) => [
                        'id' => $match->id,
                        'partnerName' => $match->partner_name,
                        'partnerReferenceId' => $match->partner_reference_id,
                        'status' => $match->status,
                        'notes' => $match->notes,
                        'matchedAt' => optional($match->matched_at)->toIso8601String(),
                        'createdAt' => optional($match->created_at)->toIso8601String(),
                    ])->values()
                    : [],
                'activityLogs' => $this->relationLoaded('activityLogs')
                    ? $this->activityLogs
                        ->sortByDesc('occurred_at')
                        ->values()
                        ->map(fn ($log) => [
                        'id' => $log->id,
                        'category' => $log->category,
                        'action' => $log->action,
                        'title' => $log->title,
                        'description' => $log->description,
                        'metadata' => $log->metadata,
                        'occurredAt' => optional($log->occurred_at)->toIso8601String(),
                    ])->values()
                    : [],
            ],
        ];
    }
}
