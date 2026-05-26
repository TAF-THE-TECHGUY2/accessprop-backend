<?php

namespace App\Services;

use App\Models\FundingInstruction;
use App\Models\IntegrationRequest;
use App\Models\Investor;
use App\Models\InvestorActivityLog;
use App\Models\InvestorMessage;
use App\Models\PartnerMatch;
use App\Models\PaymentConfirmation;
use App\Services\Integrations\PersonaClient;
use App\Services\Integrations\VerifyInvestorClient;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class InvestorProcessingService
{
    public function __construct(
        private readonly ?PersonaClient $persona = null,
        private readonly ?VerifyInvestorClient $verifyInvestor = null,
    ) {
    }

    private function personaClient(): PersonaClient
    {
        return $this->persona ?? PersonaClient::fromConfig();
    }

    private function verifyInvestorClient(): VerifyInvestorClient
    {
        return $this->verifyInvestor ?? VerifyInvestorClient::fromConfig();
    }

    public function startPersonaVerification(Investor $investor): Investor
    {
        $persona = $this->personaClient();

        if (! $persona->isConfigured()) {
            return $this->markPersonaPlaceholder($investor, 'Persona API key is not configured.');
        }

        if (! $persona->templateId()) {
            return $this->markPersonaPlaceholder($investor, 'Persona template id is not configured.');
        }

        try {
            $inquiry = $persona->createInquiry($investor->code, [
                'name_first' => $this->firstName($investor->name),
                'name_last' => $this->lastName($investor->name),
                'email_address' => $investor->email,
                'phone_number' => $investor->phone,
            ]);

            $inquiryId = data_get($inquiry, 'data.id');
            $status = data_get($inquiry, 'data.attributes.status', 'created');
            $oneTimeLink = '';

            if ($inquiryId) {
                try {
                    $oneTimeLink = $persona->generateOneTimeLink($inquiryId);
                } catch (RequestException $e) {
                    Log::warning('Persona one-time link generation failed', [
                        'inquiryId' => $inquiryId,
                        'message' => $e->getMessage(),
                    ]);
                }
            }

            return DB::transaction(function () use ($investor, $inquiry, $inquiryId, $status, $oneTimeLink) {
                $request = $this->createIntegrationRequest($investor, 'persona', 'identity_verification', [
                    'status' => $status,
                    'external_id' => $inquiryId,
                    'external_url' => $oneTimeLink ?: null,
                    'request_payload' => ['investorCode' => $investor->code],
                    'response_payload' => $inquiry,
                ]);

                $this->updateInvestor($investor, [
                    'investment_status' => 'awaiting_accreditation_verification',
                    'accreditation_verification_status' => 'verification_required',
                ]);

                $this->logActivity(
                    $investor,
                    'persona_started',
                    'Persona inquiry created',
                    sprintf('Created Persona inquiry %s for the investor.', $inquiryId ?: '(unknown id)'),
                    [
                        'integrationRequestId' => $request->id,
                        'personaInquiryId' => $inquiryId,
                    ]
                );

                return $this->freshInvestor($investor);
            });
        } catch (RequestException $e) {
            Log::error('Persona inquiry creation failed', [
                'investorCode' => $investor->code,
                'status' => $e->response?->status(),
                'body' => $e->response?->body(),
            ]);

            return DB::transaction(function () use ($investor, $e) {
                $request = $this->createIntegrationRequest($investor, 'persona', 'identity_verification', [
                    'status' => 'failed',
                    'request_payload' => ['investorCode' => $investor->code],
                    'response_payload' => [
                        'status' => $e->response?->status(),
                        'body' => $e->response?->json() ?? $e->response?->body(),
                    ],
                    'error_message' => $e->getMessage(),
                ]);

                $this->logActivity(
                    $investor,
                    'persona_failed',
                    'Persona inquiry failed',
                    'Persona API rejected the inquiry. See integration request for details.',
                    ['integrationRequestId' => $request->id]
                );

                return $this->freshInvestor($investor);
            });
        }
    }

    public function applyPersonaInquiryResult(Investor $investor, string $inquiryId, ?string $reportedStatus = null): Investor
    {
        $persona = $this->personaClient();
        $details = null;

        if ($persona->isConfigured()) {
            try {
                $details = $persona->fetchInquiry($inquiryId);
            } catch (RequestException $e) {
                Log::warning('Persona inquiry fetch failed', [
                    'inquiryId' => $inquiryId,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        $status = data_get($details, 'data.attributes.status', $reportedStatus ?? 'unknown');
        $isApproved = in_array($status, ['approved', 'completed'], true);

        return DB::transaction(function () use ($investor, $inquiryId, $status, $details, $isApproved) {
            IntegrationRequest::query()
                ->where('investor_profile_id', $investor->id)
                ->where('provider', 'persona')
                ->where('external_id', $inquiryId)
                ->update([
                    'status' => $status,
                    'response_payload' => $details,
                    'completed_at' => $isApproved ? now() : null,
                ]);

            $updates = [];
            if ($isApproved) {
                $updates['accreditation_verification_status'] = 'verification_submitted';
            } elseif (in_array($status, ['declined', 'failed', 'expired'], true)) {
                $updates['accreditation_verification_status'] = 'verification_rejected';
            }

            if ($updates) {
                $this->updateInvestor($investor, $updates);
            }

            $this->logActivity(
                $investor,
                'persona_status_update',
                'Persona inquiry status updated',
                sprintf('Persona inquiry %s status is now "%s".', $inquiryId, $status),
                ['personaInquiryId' => $inquiryId, 'status' => $status]
            );

            return $this->freshInvestor($investor);
        });
    }

    private function markPersonaPlaceholder(Investor $investor, string $reason): Investor
    {
        return DB::transaction(function () use ($investor, $reason) {
            $request = $this->createIntegrationRequest($investor, 'persona', 'identity_verification', [
                'status' => 'skipped',
                'request_payload' => ['investorCode' => $investor->code],
                'response_payload' => ['message' => $reason],
                'error_message' => $reason,
            ]);

            $this->updateInvestor($investor, [
                'investment_status' => 'awaiting_accreditation_verification',
                'accreditation_verification_status' => 'verification_required',
            ]);

            $this->logActivity(
                $investor,
                'persona_skipped',
                'Persona verification skipped',
                $reason,
                ['integrationRequestId' => $request->id]
            );

            return $this->freshInvestor($investor);
        });
    }

    private function firstName(?string $name): ?string
    {
        $parts = preg_split('/\s+/', trim((string) $name)) ?: [];

        return $parts[0] ?? null;
    }

    private function lastName(?string $name): ?string
    {
        $parts = preg_split('/\s+/', trim((string) $name)) ?: [];
        if (count($parts) <= 1) {
            return null;
        }

        return implode(' ', array_slice($parts, 1));
    }

    public function startVerifyInvestorReview(Investor $investor): Investor
    {
        return DB::transaction(function () use ($investor) {
            $request = $this->createIntegrationRequest($investor, 'verifyinvestor', 'accreditation_review', [
                'status' => 'created',
                'external_url' => sprintf('https://verifyinvestor.placeholder/%s', $investor->code),
                'request_payload' => ['investorCode' => $investor->code],
                'response_payload' => ['message' => 'VerifyInvestor placeholder review created.'],
            ]);

            $this->updateInvestor($investor, [
                'investment_status' => 'awaiting_accreditation_verification',
                'accreditation_verification_status' => 'verification_submitted',
            ]);

            $this->logActivity(
                $investor,
                'verifyinvestor_started',
                'VerifyInvestor review started',
                'Admin initiated a placeholder VerifyInvestor accreditation review.',
                ['integrationRequestId' => $request->id]
            );

            return $this->freshInvestor($investor);
        });
    }

    public function sendDocusignDocuments(Investor $investor): Investor
    {
        return DB::transaction(function () use ($investor) {
            $request = $this->createIntegrationRequest($investor, 'docusign', 'document_signing', [
                'status' => 'sent',
                'external_url' => sprintf('https://docusign.placeholder/%s', $investor->code),
                'request_payload' => ['investorCode' => $investor->code],
                'response_payload' => ['message' => 'DocuSign placeholder envelope created.'],
                'completed_at' => now(),
            ]);

            $this->updateInvestor($investor, [
                'investment_status' => 'awaiting_documents',
                'document_signing_status' => 'sent',
            ]);

            $this->logActivity(
                $investor,
                'docusign_sent',
                'DocuSign documents sent',
                'Placeholder DocuSign documents were sent to the investor.',
                ['integrationRequestId' => $request->id]
            );

            return $this->freshInvestor($investor);
        });
    }

    public function approveLegalReview(Investor $investor): Investor
    {
        return DB::transaction(function () use ($investor) {
            $this->updateInvestor($investor, [
                'investment_status' => 'awaiting_funding',
                'accreditation_verification_status' => 'verification_approved',
                'document_signing_status' => 'completed',
            ]);

            $this->logActivity(
                $investor,
                'legal_review_approved',
                'Legal review approved',
                'Admin/legal approved the investor package for funding readiness.'
            );

            return $this->freshInvestor($investor);
        });
    }

    public function rejectLegalReview(Investor $investor, ?string $reason = null): Investor
    {
        return DB::transaction(function () use ($investor, $reason) {
            $this->updateInvestor($investor, [
                'investment_status' => 'awaiting_accreditation_verification',
                'accreditation_verification_status' => 'verification_rejected',
            ]);

            $this->logActivity(
                $investor,
                'legal_review_rejected',
                'Legal review rejected',
                $reason
                    ? 'Admin/legal rejected the investor package: '.$reason
                    : 'Admin/legal rejected the investor package.',
                ['reason' => $reason]
            );

            return $this->freshInvestor($investor);
        });
    }

    public function releaseFundingInstructions(Investor $investor): Investor
    {
        return DB::transaction(function () use ($investor) {
            $instructions = FundingInstruction::create([
                'investor_profile_id' => $investor->id,
                'status' => 'released',
                'instructions' => 'Placeholder funding instructions released by admin.',
                'delivery_channel' => 'email',
                'external_url' => sprintf('https://funding.placeholder/%s', $investor->code),
                'released_at' => now(),
            ]);

            $this->updateInvestor($investor, [
                'investment_status' => 'awaiting_funding',
                'investment_wallet_status' => 'Funding instructions released',
            ]);

            $this->createMessage(
                $investor,
                'Funding instructions available',
                'Funding instructions were released to the investor.'
            );

            $this->logActivity(
                $investor,
                'funding_instructions_released',
                'Funding instructions released',
                'Funding instructions were released to the investor.',
                ['fundingInstructionId' => $instructions->id]
            );

            return $this->freshInvestor($investor);
        });
    }

    public function markFundsSent(Investor $investor, array $payload = []): Investor
    {
        return DB::transaction(function () use ($investor, $payload) {
            $payment = PaymentConfirmation::create([
                'investor_profile_id' => $investor->id,
                'status' => 'submitted',
                'amount' => Arr::get($payload, 'amount', $investor->investment_commitment),
                'reference' => Arr::get($payload, 'reference', 'proof-'.$investor->code),
                'proof_file_url' => Arr::get($payload, 'proofFileUrl'),
                'notes' => Arr::get($payload, 'notes', 'Investor reported that funds were sent.'),
            ]);

            $this->updateInvestor($investor, [
                'investment_status' => 'funds_sent',
                'investment_wallet_status' => 'Funds sent - awaiting confirmation',
            ]);

            $this->logActivity(
                $investor,
                'funds_marked_sent',
                'Funds marked as sent',
                'Admin marked the investor as having sent funds or uploaded proof of payment.',
                ['paymentConfirmationId' => $payment->id]
            );

            return $this->freshInvestor($investor);
        });
    }

    public function confirmFundsReceived(Investor $investor): Investor
    {
        return DB::transaction(function () use ($investor) {
            $request = $this->createIntegrationRequest($investor, 'internal', 'funds_confirmation', [
                'status' => 'completed',
                'request_payload' => ['investorCode' => $investor->code],
                'response_payload' => ['message' => 'Funds confirmed internally.'],
                'completed_at' => now(),
            ]);

            $payment = PaymentConfirmation::query()
                ->where('investor_profile_id', $investor->id)
                ->latest('id')
                ->first();

            if ($payment) {
                $payment->update([
                    'status' => 'confirmed',
                    'confirmed_at' => now(),
                    'amount' => $payment->amount ?: $investor->investment_commitment,
                ]);
            } else {
                $payment = PaymentConfirmation::create([
                    'investor_profile_id' => $investor->id,
                    'status' => 'confirmed',
                    'amount' => $investor->investment_commitment,
                    'reference' => 'confirmed-'.$investor->code,
                    'notes' => 'Funds were confirmed without a prior payment submission record.',
                    'confirmed_at' => now(),
                ]);
            }

            $this->updateInvestor($investor, [
                'investment_status' => 'funds_confirmed',
                'investment_wallet_status' => 'Funds confirmed',
                'investment_funded' => $investor->investment_commitment,
            ]);

            $this->logActivity(
                $investor,
                'funds_confirmed',
                'Funds received confirmed',
                'Admin confirmed the investor funds were received.',
                [
                    'integrationRequestId' => $request->id,
                    'paymentConfirmationId' => $payment->id,
                ]
            );

            return $this->freshInvestor($investor);
        });
    }

    public function generatePartnerRedirect(Investor $investor): Investor
    {
        return DB::transaction(function () use ($investor) {
            $request = $this->createIntegrationRequest($investor, 'crowdfunding_partner', 'redirect_link', [
                'status' => 'created',
                'external_id' => 'partner-'.$investor->code,
                'external_url' => sprintf('https://partner.placeholder/investors/%s', $investor->code),
                'request_payload' => ['investorCode' => $investor->code],
                'response_payload' => ['message' => 'Crowdfunding partner redirect generated.'],
            ]);

            $this->updateInvestor($investor, [
                'investment_status' => 'pending_partner_review',
            ]);

            $this->logActivity(
                $investor,
                'partner_redirect_generated',
                'Partner redirect generated',
                'A placeholder crowdfunding partner redirect link was generated.',
                ['integrationRequestId' => $request->id]
            );

            return $this->freshInvestor($investor);
        });
    }

    public function markRedirectedToPartner(Investor $investor): Investor
    {
        return DB::transaction(function () use ($investor) {
            $this->updateInvestor($investor, [
                'investment_status' => 'redirected_to_partner',
            ]);

            $this->logActivity(
                $investor,
                'redirected_to_partner',
                'Investor redirected to partner',
                'Admin marked the investor as redirected to the external crowdfunding partner.'
            );

            return $this->freshInvestor($investor);
        });
    }

    public function addPartnerReferenceId(Investor $investor, string $referenceId): Investor
    {
        return DB::transaction(function () use ($investor, $referenceId) {
            $match = PartnerMatch::query()->updateOrCreate(
                ['investor_profile_id' => $investor->id],
                [
                    'partner_name' => 'Crowdfunding Partner',
                    'partner_reference_id' => $referenceId,
                    'status' => 'reference_added',
                ]
            );

            $this->updateInvestor($investor, [
                'investment_status' => 'partner_match_pending',
            ]);

            $this->logActivity(
                $investor,
                'partner_reference_added',
                'Partner reference added',
                'Admin added a partner reference ID for manual matching.',
                ['partnerMatchId' => $match->id, 'partnerReferenceId' => $referenceId]
            );

            return $this->freshInvestor($investor);
        });
    }

    public function markPartnerMatchPending(Investor $investor): Investor
    {
        return DB::transaction(function () use ($investor) {
            PartnerMatch::query()->updateOrCreate(
                ['investor_profile_id' => $investor->id],
                [
                    'partner_name' => 'Crowdfunding Partner',
                    'status' => 'pending',
                ]
            );

            $this->updateInvestor($investor, [
                'investment_status' => 'partner_match_pending',
            ]);

            $this->logActivity(
                $investor,
                'partner_match_pending',
                'Partner match pending',
                'Admin marked the investor as awaiting manual partner matching.'
            );

            return $this->freshInvestor($investor);
        });
    }

    public function confirmPartnerMatch(Investor $investor): Investor
    {
        return DB::transaction(function () use ($investor) {
            $request = $this->createIntegrationRequest($investor, 'crowdfunding_partner', 'partner_match_confirmation', [
                'status' => 'completed',
                'request_payload' => ['investorCode' => $investor->code],
                'response_payload' => ['message' => 'Partner match confirmed internally.'],
                'completed_at' => now(),
            ]);

            $match = PartnerMatch::query()->updateOrCreate(
                ['investor_profile_id' => $investor->id],
                [
                    'partner_name' => 'Crowdfunding Partner',
                    'status' => 'matched',
                    'matched_at' => now(),
                ]
            );

            $this->updateInvestor($investor, [
                'investment_status' => 'partner_match_complete',
            ]);

            $this->logActivity(
                $investor,
                'partner_match_confirmed',
                'Partner match confirmed',
                'Admin confirmed the investor was matched to the partner reference.',
                [
                    'integrationRequestId' => $request->id,
                    'partnerMatchId' => $match->id,
                ]
            );

            return $this->freshInvestor($investor);
        });
    }

    public function activateInvestment(Investor $investor): Investor
    {
        return DB::transaction(function () use ($investor) {
            $request = $this->createIntegrationRequest($investor, 'internal', 'investment_activation', [
                'status' => 'completed',
                'request_payload' => ['investorCode' => $investor->code],
                'response_payload' => ['message' => 'Investor activation completed internally.'],
                'completed_at' => now(),
            ]);

            $updates = [
                'investment_status' => 'active',
                'dashboard_status' => 'active',
                'investment_wallet_status' => 'Active',
            ];

            if ($investor->accreditation_status === 'accredited') {
                $updates['accreditation_verification_status'] = 'verification_approved';
                $updates['document_signing_status'] = 'completed';
                $updates['investment_funded'] = $investor->investment_commitment;
            }

            $this->updateInvestor($investor, $updates);

            $this->createMessage(
                $investor,
                'Your Access Properties investment is active',
                'Activation email placeholder created for the investor.'
            );

            $this->logActivity(
                $investor,
                'investment_activated',
                'Investment activated',
                'The investment was activated and a placeholder activation email was prepared.',
                ['integrationRequestId' => $request->id]
            );

            return $this->freshInvestor($investor);
        });
    }

    private function updateInvestor(Investor $investor, array $updates): void
    {
        $investor->fill($updates);
        $investor->save();
    }

    private function createIntegrationRequest(Investor $investor, string $provider, string $type, array $attributes = []): IntegrationRequest
    {
        return IntegrationRequest::create([
            'investor_profile_id' => $investor->id,
            'provider' => $provider,
            'type' => $type,
            'status' => Arr::get($attributes, 'status', 'created'),
            'external_id' => Arr::get($attributes, 'external_id'),
            'external_url' => Arr::get($attributes, 'external_url'),
            'request_payload' => Arr::get($attributes, 'request_payload'),
            'response_payload' => Arr::get($attributes, 'response_payload'),
            'error_message' => Arr::get($attributes, 'error_message'),
            'created_by' => Arr::get($attributes, 'created_by'),
            'completed_at' => Arr::get($attributes, 'completed_at'),
        ]);
    }

    private function createMessage(Investor $investor, string $subject, string $preview): InvestorMessage
    {
        return InvestorMessage::create([
            'investor_id' => $investor->id,
            'code' => 'msg-'.$investor->code.'-'.now()->timestamp.'-'.str()->lower(str()->random(4)),
            'subject' => $subject,
            'preview' => $preview,
            'sent_at' => now(),
        ]);
    }

    private function logActivity(
        Investor $investor,
        string $action,
        string $title,
        string $description,
        array $metadata = []
    ): InvestorActivityLog {
        return InvestorActivityLog::create([
            'investor_profile_id' => $investor->id,
            'category' => 'processing',
            'action' => $action,
            'title' => $title,
            'description' => $description,
            'metadata' => $metadata ?: null,
            'occurred_at' => now(),
        ]);
    }

    private function freshInvestor(Investor $investor): Investor
    {
        return $investor->fresh([
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
    }
}
