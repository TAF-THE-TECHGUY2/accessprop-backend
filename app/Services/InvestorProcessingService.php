<?php

namespace App\Services;

use App\Models\Fund;
use App\Models\FundHolding;
use App\Models\FundingInstruction;
use App\Models\IntegrationRequest;
use App\Models\Investor;
use App\Models\InvestorActivityLog;
use App\Models\InvestorMessage;
use App\Models\PartnerMatch;
use App\Models\PaymentConfirmation;
use App\Services\Integrations\InvestReadyConnectClient;
use App\Services\Integrations\PersonaClient;
use App\Services\Integrations\StripeClient;
use App\Services\Integrations\VerifyInvestorClient;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class InvestorProcessingService
{
    public function __construct(
        private readonly ?PersonaClient $persona = null,
        private readonly ?VerifyInvestorClient $verifyInvestor = null,
        private readonly ?InvestReadyConnectClient $investReady = null,
        private readonly ?StripeClient $stripe = null,
    ) {
    }

    private function stripeClient(): StripeClient
    {
        return $this->stripe ?? StripeClient::fromConfig();
    }

    private function personaClient(): PersonaClient
    {
        return $this->persona ?? PersonaClient::fromConfig();
    }

    private function verifyInvestorClient(): VerifyInvestorClient
    {
        return $this->verifyInvestor ?? VerifyInvestorClient::fromConfig();
    }

    private function investReadyClient(): InvestReadyConnectClient
    {
        return $this->investReady ?? InvestReadyConnectClient::fromConfig();
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
        // Don't downgrade an already-approved investor on a redundant click.
        if ($investor->accreditation_verification_status === 'verification_approved') {
            return $this->freshInvestor($investor);
        }

        $client = $this->investReadyClient();

        if (! $client->isConfigured()) {
            // Fall back to placeholder behaviour while credentials are missing.
            return DB::transaction(function () use ($investor) {
                $request = $this->createIntegrationRequest($investor, 'investready', 'accreditation_review', [
                    'status' => 'created',
                    'external_url' => sprintf('https://investready.placeholder/%s', $investor->code),
                    'request_payload' => ['investorCode' => $investor->code],
                    'response_payload' => ['message' => 'InvestReady placeholder; credentials not configured.'],
                ]);

                $this->updateInvestor($investor, [
                    'investment_status' => 'awaiting_accreditation_verification',
                    'accreditation_verification_status' => 'verification_submitted',
                ]);

                $this->logActivity(
                    $investor,
                    'investready_placeholder',
                    'InvestReady placeholder created',
                    'Credentials not configured; using placeholder URL.',
                    ['integrationRequestId' => $request->id]
                );

                return $this->freshInvestor($investor);
            });
        }

        return DB::transaction(function () use ($investor, $client) {
            // Cryptographically random state token that ties the callback to this investor.
            $state = Str::random(48);
            $authorizeUrl = $client->authorizationUrl($state);

            $request = $this->createIntegrationRequest($investor, 'investready', 'accreditation_review', [
                'status' => 'awaiting_authorization',
                'external_url' => $authorizeUrl,
                'external_id' => $state,
                'request_payload' => [
                    'investorCode' => $investor->code,
                    'state' => $state,
                    'redirect_uri' => $client->redirectUri(),
                    'sandbox' => $client->isSandbox(),
                ],
            ]);

            $this->updateInvestor($investor, [
                'investment_status' => 'awaiting_accreditation_verification',
                'accreditation_verification_status' => 'verification_required',
            ]);

            $this->logActivity(
                $investor,
                'investready_started',
                'InvestReady authorization initiated',
                'Investor was sent to InvestReady Connect to authorize accreditation verification.',
                ['integrationRequestId' => $request->id, 'state' => $state]
            );

            return $this->freshInvestor($investor);
        });
    }

    /**
     * Complete the InvestReady OAuth callback: exchange the code, fetch the
     * verification status, update the investor.
     */
    public function completeInvestReadyVerification(Investor $investor, string $code, string $state): Investor
    {
        $client = $this->investReadyClient();

        $integration = IntegrationRequest::query()
            ->where('investor_profile_id', $investor->id)
            ->where('provider', 'investready')
            ->where('external_id', $state)
            ->latest('id')
            ->first();

        if (! $integration) {
            throw new \RuntimeException('InvestReady state token did not match any pending request.');
        }

        $stage = 'token_exchange';
        try {
            $tokens = $client->exchangeCode($code);
            $accessToken = $tokens['access_token'] ?? null;
            if (! $accessToken) {
                throw new \RuntimeException('InvestReady token response missing access_token.');
            }

            $stage = 'fetch_verification';
            $verification = $client->fetchVerification($accessToken);
        } catch (RequestException $e) {
            $url = $stage === 'token_exchange'
                ? config('services.investready.token_url')
                : $client->verificationEndpointUrl();

            $integration->update([
                'status' => 'failed',
                'response_payload' => [
                    'stage' => $stage,
                    'url' => $url,
                    'http_status' => $e->response?->status(),
                    'body' => $e->response?->json() ?? substr((string) $e->response?->body(), 0, 500),
                ],
                'error_message' => '['.$stage.' @ '.$url.'] HTTP '.$e->response?->status().': '.$e->getMessage(),
                'completed_at' => now(),
            ]);
            Log::warning('InvestReady '.$stage.' failed', [
                'investor' => $investor->code,
                'url' => $url,
                'http_status' => $e->response?->status(),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        // Map the InvestReady response to our accreditation_verification_status.
        // Real shape from InvestReady: data.person.hash + data.certificates[].
        // Accreditation is determined by certificates, not a top-level status.
        $person = (array) data_get($verification, 'data.person', []);
        $certificates = (array) data_get($verification, 'data.certificates', []);
        $remoteId = (string) ($person['hash'] ?? $state);

        $approvedStatuses = ['approved', 'verified', 'valid', 'accredited', 'active'];
        $rejectedStatuses = ['rejected', 'denied', 'failed', 'expired'];
        $hasApproved = false;
        $hasPending = false;
        $hasRejected = false;

        foreach ($certificates as $cert) {
            $certStatus = strtolower((string) ($cert['status'] ?? $cert['state'] ?? ''));
            if (in_array($certStatus, $approvedStatuses, true)) {
                $hasApproved = true;
            } elseif (in_array($certStatus, $rejectedStatuses, true)) {
                $hasRejected = true;
            } else {
                $hasPending = true;
            }
        }

        if ($hasApproved) {
            $mappedStatus = 'verification_approved';
            $remoteStatus = 'approved';
        } elseif ($hasPending) {
            $mappedStatus = 'verification_submitted';
            $remoteStatus = 'pending';
        } elseif ($hasRejected) {
            $mappedStatus = 'verification_rejected';
            $remoteStatus = 'rejected';
        } else {
            // No certificates yet — user linked their InvestReady account but hasn't completed verification.
            $mappedStatus = 'verification_submitted';
            $remoteStatus = 'no_certificates';
        }

        return DB::transaction(function () use ($investor, $integration, $verification, $remoteId, $remoteStatus, $mappedStatus, $tokens) {
            // Persist tokens alongside the verification result so we can refresh later.
            // Per docs: access_token ~90d, refresh_token ~2y. Use created_at to detect expiry.
            $payload = $verification;
            $payload['_oauth'] = [
                'access_token' => $tokens['access_token'] ?? null,
                'refresh_token' => $tokens['refresh_token'] ?? null,
                'expires_in' => $tokens['expires_in'] ?? null,
                'token_type' => $tokens['token_type'] ?? 'Bearer',
                'created_at' => now()->timestamp,
            ];

            $integration->update([
                'status' => 'completed',
                'external_id' => $remoteId,
                'response_payload' => $payload,
                'completed_at' => now(),
            ]);

            $updates = ['accreditation_verification_status' => $mappedStatus];
            if ($mappedStatus === 'verification_approved') {
                $updates['investment_status'] = 'awaiting_documents';
            }
            $this->updateInvestor($investor, $updates);

            $this->logActivity(
                $investor,
                'investready_completed',
                'InvestReady verification result received',
                'InvestReady returned status "'.$remoteStatus.'".',
                ['integrationRequestId' => $integration->id, 'remoteStatus' => $remoteStatus]
            );

            return $this->freshInvestor($investor);
        });
    }

    /**
     * Build a summary of the investor's InvestReady connection state for the admin UI.
     * Strips sensitive token data from the response.
     */
    public function investReadyConnectionSummary(Investor $investor): array
    {
        $latestCompleted = IntegrationRequest::query()
            ->where('investor_profile_id', $investor->id)
            ->where('provider', 'investready')
            ->where('status', 'completed')
            ->latest('id')
            ->first();

        $history = IntegrationRequest::query()
            ->where('investor_profile_id', $investor->id)
            ->where('provider', 'investready')
            ->orderByDesc('id')
            ->limit(10)
            ->get()
            ->map(fn ($r) => [
                'id' => $r->id,
                'status' => $r->status,
                'createdAt' => optional($r->created_at)->toIso8601String(),
                'completedAt' => optional($r->completed_at)->toIso8601String(),
                'errorMessage' => $r->error_message ? mb_substr($r->error_message, 0, 200) : null,
                'externalId' => $r->external_id,
                'failureStage' => data_get($r->response_payload, 'stage'),
                'failureUrl' => data_get($r->response_payload, 'url'),
                'failureHttpStatus' => data_get($r->response_payload, 'http_status'),
            ]);

        if (! $latestCompleted) {
            return [
                'linked' => false,
                'history' => $history,
            ];
        }

        $payload = (array) $latestCompleted->response_payload;
        $oauth = (array) ($payload['_oauth'] ?? []);
        $tokenCreatedAt = $oauth['created_at'] ?? null;
        $expiresIn = $oauth['expires_in'] ?? null;
        $tokenExpiresAt = ($tokenCreatedAt && $expiresIn)
            ? \Carbon\Carbon::createFromTimestamp($tokenCreatedAt + (int) $expiresIn)
            : null;

        $person = (array) data_get($payload, 'data.person', []);
        $certificates = (array) data_get($payload, 'data.certificates', []);

        return [
            'linked' => true,
            'personHash' => $person['hash'] ?? null,
            'personEmail' => $person['email'] ?? null,
            'personName' => trim((string) ($person['name'] ?? '')) ?: null,
            'lastSyncAt' => optional($latestCompleted->completed_at)->toIso8601String(),
            'certificatesCount' => count($certificates),
            'certificates' => array_map(fn ($c) => [
                'id' => $c['id'] ?? null,
                'type' => $c['type'] ?? $c['certificate_type'] ?? null,
                'status' => $c['status'] ?? $c['state'] ?? null,
                'createdAt' => $c['created_at'] ?? null,
                'expiresAt' => $c['expires_at'] ?? null,
            ], $certificates),
            'mappedStatus' => $investor->accreditation_verification_status,
            'tokenExpiresAt' => optional($tokenExpiresAt)->toIso8601String(),
            'tokenExpired' => $tokenExpiresAt ? $tokenExpiresAt->isPast() : null,
            'history' => $history,
        ];
    }

    /**
     * Re-fetch the investor's InvestReady verification using the stored access_token.
     * If the access_token has expired, use the refresh_token to get a new one first.
     */
    public function refreshInvestReadyVerification(Investor $investor): Investor
    {
        $client = $this->investReadyClient();

        $integration = IntegrationRequest::query()
            ->where('investor_profile_id', $investor->id)
            ->where('provider', 'investready')
            ->where('status', 'completed')
            ->latest('id')
            ->first();

        if (! $integration) {
            throw new \RuntimeException('No completed InvestReady integration to refresh.');
        }

        $payload = (array) $integration->response_payload;
        $oauth = (array) ($payload['_oauth'] ?? []);
        $accessToken = $oauth['access_token'] ?? null;
        $refreshToken = $oauth['refresh_token'] ?? null;
        $tokenCreatedAt = (int) ($oauth['created_at'] ?? 0);
        $expiresIn = (int) ($oauth['expires_in'] ?? 0);
        $isExpired = $tokenCreatedAt && $expiresIn && ($tokenCreatedAt + $expiresIn) < now()->timestamp;

        if (! $accessToken && ! $refreshToken) {
            throw new \RuntimeException('No InvestReady tokens stored; cannot refresh.');
        }

        try {
            if ($isExpired && $refreshToken) {
                $tokens = $client->refreshAccessToken($refreshToken);
                $accessToken = $tokens['access_token'] ?? $accessToken;
                $oauth = [
                    'access_token' => $tokens['access_token'] ?? null,
                    'refresh_token' => $tokens['refresh_token'] ?? $refreshToken,
                    'expires_in' => $tokens['expires_in'] ?? null,
                    'token_type' => $tokens['token_type'] ?? 'Bearer',
                    'created_at' => now()->timestamp,
                ];
            }

            $verification = $client->fetchVerification($accessToken);
        } catch (RequestException $e) {
            Log::warning('InvestReady refresh failed', [
                'investor' => $investor->code,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        // Apply the same status-mapping rules as the initial verification.
        [$mappedStatus, $remoteStatus] = $this->mapInvestReadyStatus($verification);

        return DB::transaction(function () use ($investor, $integration, $verification, $oauth, $mappedStatus, $remoteStatus) {
            $newPayload = $verification;
            $newPayload['_oauth'] = $oauth;

            $integration->update([
                'response_payload' => $newPayload,
                'completed_at' => now(),
            ]);

            $updates = ['accreditation_verification_status' => $mappedStatus];
            if ($mappedStatus === 'verification_approved' &&
                $investor->investment_status === 'awaiting_accreditation_verification') {
                $updates['investment_status'] = 'awaiting_documents';
            }
            $this->updateInvestor($investor, $updates);

            $this->logActivity(
                $investor,
                'investready_refreshed',
                'InvestReady re-synced',
                'Admin triggered a refresh; InvestReady returned "'.$remoteStatus.'".',
                ['integrationRequestId' => $integration->id, 'remoteStatus' => $remoteStatus]
            );

            return $this->freshInvestor($investor);
        });
    }

    /**
     * Pure mapping function — takes the InvestReady response and returns
     * [our_internal_status, remote_status_label]. Used by both the initial
     * verification and the refresh flow.
     */
    private function mapInvestReadyStatus(array $verification): array
    {
        $certificates = (array) data_get($verification, 'data.certificates', []);
        $approved = ['approved', 'verified', 'valid', 'accredited', 'active'];
        $rejected = ['rejected', 'denied', 'failed', 'expired'];

        $hasApproved = $hasPending = $hasRejected = false;
        foreach ($certificates as $cert) {
            $s = strtolower((string) ($cert['status'] ?? $cert['state'] ?? ''));
            if (in_array($s, $approved, true)) {
                $hasApproved = true;
            } elseif (in_array($s, $rejected, true)) {
                $hasRejected = true;
            } else {
                $hasPending = true;
            }
        }

        if ($hasApproved) return ['verification_approved', 'approved'];
        if ($hasPending) return ['verification_submitted', 'pending'];
        if ($hasRejected) return ['verification_rejected', 'rejected'];

        return ['verification_submitted', 'no_certificates'];
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
        $stripe = $this->stripeClient();

        if (! $stripe->isConfigured()) {
            // Fall back to placeholder when Stripe keys are missing.
            return DB::transaction(function () use ($investor) {
                $instructions = FundingInstruction::create([
                    'investor_profile_id' => $investor->id,
                    'status' => 'released',
                    'provider' => 'placeholder',
                    'instructions' => 'Stripe is not configured. Placeholder funding row created.',
                    'delivery_channel' => 'email',
                    'released_at' => now(),
                ]);

                $this->updateInvestor($investor, [
                    'investment_status' => 'awaiting_funding',
                    'investment_wallet_status' => 'Funding instructions released (placeholder)',
                ]);

                $this->logActivity(
                    $investor,
                    'funding_placeholder',
                    'Funding placeholder created',
                    'Stripe not configured — placeholder FundingInstruction row written.',
                    ['fundingInstructionId' => $instructions->id]
                );

                return $this->freshInvestor($investor);
            });
        }

        // Amount comes from the investor's commitment. Stripe expects cents.
        $amountCents = (int) round(((float) $investor->investment_commitment) * 100);
        if ($amountCents <= 0) {
            throw new \RuntimeException('Investor has no commitment amount set; cannot create Payment Intent.');
        }

        try {
            $intent = $stripe->createAchPaymentIntent($investor, $amountCents);
        } catch (\Stripe\Exception\ApiErrorException $e) {
            Log::warning('Stripe PaymentIntent creation failed', [
                'investor' => $investor->code,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        return DB::transaction(function () use ($investor, $intent, $amountCents, $stripe) {
            $instruction = FundingInstruction::create([
                'investor_profile_id' => $investor->id,
                'status' => 'released',
                'provider' => 'stripe',
                'provider_intent_id' => $intent->id,
                'provider_client_secret' => $intent->client_secret,
                'amount_cents' => $amountCents,
                'currency' => 'usd',
                'instructions' => 'Pay via ACH using Stripe Financial Connections.',
                'delivery_channel' => 'app',
                'external_url' => $stripe->dashboardUrlForIntent($intent->id),
                'provider_payload' => [
                    'status' => $intent->status,
                    'created' => $intent->created,
                ],
                'released_at' => now(),
            ]);

            $this->updateInvestor($investor, [
                'investment_status' => 'awaiting_funding',
                'investment_wallet_status' => 'Awaiting ACH payment',
            ]);

            $this->createMessage(
                $investor,
                'Time to fund your subscription',
                'Your funding step is unlocked. Link your bank to complete payment via ACH.'
            );

            $this->logActivity(
                $investor,
                'funding_instructions_released',
                'Stripe Payment Intent created',
                sprintf(
                    'PaymentIntent %s created for $%s.',
                    $intent->id,
                    number_format($amountCents / 100, 2)
                ),
                ['fundingInstructionId' => $instruction->id, 'paymentIntentId' => $intent->id]
            );

            return $this->freshInvestor($investor);
        });
    }

    /**
     * Look up the active Stripe FundingInstruction for an investor (the latest released one).
     * Returns null if none exists.
     */
    public function activeFundingInstruction(Investor $investor): ?FundingInstruction
    {
        return FundingInstruction::query()
            ->where('investor_profile_id', $investor->id)
            ->where('provider', 'stripe')
            ->latest('id')
            ->first();
    }

    /**
     * Pull the latest PaymentIntent status from Stripe and apply any state
     * changes locally. Use as a fallback / self-healing alternative to the
     * webhook (handy in dev where webhooks may not be set up).
     *
     * Returns the (possibly updated) FundingInstruction.
     */
    public function syncFundingInstructionFromStripe(FundingInstruction $instruction): FundingInstruction
    {
        if ($instruction->provider !== 'stripe' || ! $instruction->provider_intent_id) {
            return $instruction;
        }

        $stripe = $this->stripeClient();
        if (! $stripe->isConfigured()) {
            return $instruction;
        }

        try {
            $intent = $stripe->retrievePaymentIntent($instruction->provider_intent_id);
        } catch (\Throwable $e) {
            Log::warning('Stripe PI retrieve failed', [
                'intent' => $instruction->provider_intent_id,
                'error' => $e->getMessage(),
            ]);

            return $instruction;
        }

        // Map Stripe statuses to the webhook event names we already handle so
        // we can reuse the same code path.
        $eventType = match ($intent->status) {
            'processing' => 'payment_intent.processing',
            'succeeded' => 'payment_intent.succeeded',
            'canceled' => 'payment_intent.canceled',
            'requires_payment_method' => $intent->last_payment_error
                ? 'payment_intent.payment_failed'
                : null,
            default => null,
        };

        if ($eventType && $instruction->status !== $this->localStatusForEvent($eventType)) {
            $this->applyStripePaymentIntentEvent(
                $intent->id,
                $eventType,
                $intent->toArray()
            );

            return $instruction->fresh();
        }

        return $instruction;
    }

    private function localStatusForEvent(string $eventType): string
    {
        return match ($eventType) {
            'payment_intent.processing' => 'processing',
            'payment_intent.succeeded' => 'succeeded',
            'payment_intent.payment_failed', 'payment_intent.canceled' => 'failed',
            default => 'released',
        };
    }

    /**
     * Apply a Stripe webhook event to an investor. Idempotent — safe to call
     * multiple times for the same event.
     */
    public function applyStripePaymentIntentEvent(string $intentId, string $eventType, array $intentData): ?Investor
    {
        $instruction = FundingInstruction::query()
            ->where('provider_intent_id', $intentId)
            ->latest('id')
            ->first();

        if (! $instruction) {
            Log::info('Stripe webhook for unknown PaymentIntent', ['intent' => $intentId, 'event' => $eventType]);

            return null;
        }

        $investor = Investor::find($instruction->investor_profile_id);
        if (! $investor) {
            return null;
        }

        return DB::transaction(function () use ($investor, $instruction, $intentId, $eventType, $intentData) {
            $payload = (array) ($instruction->provider_payload ?? []);
            $payload['last_event'] = $eventType;
            $payload['status'] = $intentData['status'] ?? $payload['status'] ?? null;
            $payload['updated_at'] = now()->timestamp;

            switch ($eventType) {
                case 'payment_intent.processing':
                    $instruction->update(['status' => 'processing', 'provider_payload' => $payload]);
                    $this->updateInvestor($investor, [
                        'investment_status' => 'funds_sent',
                        'investment_wallet_status' => 'ACH debit submitted',
                    ]);
                    PaymentConfirmation::create([
                        'investor_profile_id' => $investor->id,
                        'status' => 'submitted',
                        'amount' => ($instruction->amount_cents ?? 0) / 100,
                        'reference' => $intentId,
                        'notes' => 'Stripe ACH debit submitted; settlement in 3-5 business days.',
                    ]);
                    $this->logActivity(
                        $investor,
                        'stripe_payment_processing',
                        'Stripe ACH submitted',
                        'PaymentIntent '.$intentId.' moved to processing.',
                        ['paymentIntentId' => $intentId]
                    );
                    break;

                case 'payment_intent.succeeded':
                    $instruction->update(['status' => 'succeeded', 'provider_payload' => $payload]);
                    $amount = ($instruction->amount_cents ?? 0) / 100;
                    $this->updateInvestor($investor, [
                        'investment_funded' => $amount,
                        'investment_wallet_status' => 'Funds received',
                    ]);
                    PaymentConfirmation::query()
                        ->where('investor_profile_id', $investor->id)
                        ->where('reference', $intentId)
                        ->update(['status' => 'confirmed']);

                    // Create / top up the FundHolding so this investor shows up
                    // in the portal's Investment tab with units, current value, etc.
                    $this->upsertHoldingFromFunding($investor, $amount);

                    $this->logActivity(
                        $investor,
                        'stripe_payment_succeeded',
                        'Stripe ACH settled',
                        'PaymentIntent '.$intentId.' succeeded; funds confirmed.',
                        ['paymentIntentId' => $intentId]
                    );

                    // Auto-advance to active per the configured policy.
                    if ($investor->investment_status !== 'active') {
                        $this->updateInvestor($investor, [
                            'investment_status' => 'funds_confirmed',
                            'dashboard_status' => 'active',
                        ]);
                        $this->activateInvestment($investor->fresh());
                    }
                    break;

                case 'payment_intent.payment_failed':
                case 'payment_intent.canceled':
                    $payload['failure_reason'] = data_get($intentData, 'last_payment_error.message');
                    $payload['failure_code'] = data_get($intentData, 'last_payment_error.code');
                    $instruction->update(['status' => 'failed', 'provider_payload' => $payload]);
                    $this->updateInvestor($investor, [
                        'investment_wallet_status' => 'Payment failed',
                    ]);
                    $this->logActivity(
                        $investor,
                        'stripe_payment_failed',
                        'Stripe ACH failed',
                        'PaymentIntent '.$intentId.' failed: '.($payload['failure_reason'] ?? 'unknown'),
                        ['paymentIntentId' => $intentId]
                    );
                    break;

                default:
                    $instruction->update(['provider_payload' => $payload]);
                    break;
            }

            return $this->freshInvestor($investor);
        });
    }

    /**
     * Build the Stripe funding summary for the admin UI.
     */
    public function stripeFundingSummary(Investor $investor): array
    {
        $instruction = $this->activeFundingInstruction($investor);

        $stripe = $this->stripeClient();

        if (! $instruction) {
            return ['linked' => false];
        }

        return [
            'linked' => true,
            'paymentIntentId' => $instruction->provider_intent_id,
            'status' => $instruction->status,
            'amount' => $instruction->amount_cents ? $instruction->amount_cents / 100 : null,
            'currency' => $instruction->currency,
            'releasedAt' => optional($instruction->released_at)->toIso8601String(),
            'lastEvent' => data_get($instruction->provider_payload, 'last_event'),
            'failureReason' => data_get($instruction->provider_payload, 'failure_reason'),
            'dashboardUrl' => $instruction->provider_intent_id
                ? $stripe->dashboardUrlForIntent($instruction->provider_intent_id)
                : null,
        ];
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

    // ============================================================
    //  Admin manual-override actions (bypass the real integrations)
    //  Every override writes both an investor_activity and an
    //  integration_request row with provider="manual" for the audit trail.
    // ============================================================

    public function overrideKycApproval(Investor $investor, string $reason, $admin = null): Investor
    {
        return DB::transaction(function () use ($investor, $reason, $admin) {
            $request = $this->createIntegrationRequest($investor, 'manual', 'kyc_approval_override', [
                'status' => 'completed',
                'request_payload' => [
                    'reason' => $reason,
                    'admin_id' => $admin?->id,
                    'admin_email' => $admin?->email,
                ],
                'response_payload' => ['message' => 'KYC approved by admin override.'],
                'completed_at' => now(),
                'created_by' => $admin?->id,
            ]);

            $updates = [
                'kyc_status' => 'approved',
                'dashboard_status' => 'active',
            ];
            // Only advance investment_status if we haven't moved past KYC already.
            if (in_array($investor->investment_status, ['awaiting_kyc', null], true)) {
                $updates['investment_status'] = $investor->accreditation_status === 'accredited'
                    ? 'awaiting_accreditation_verification'
                    : 'pending_partner_review';
            }
            $this->updateInvestor($investor, $updates);

            $this->logActivity(
                $investor,
                'kyc_override',
                'KYC manually approved (override)',
                'Admin bypassed Persona and marked KYC approved. Reason: '.$reason,
                ['integrationRequestId' => $request->id, 'adminId' => $admin?->id]
            );

            return $this->freshInvestor($investor);
        });
    }

    public function overrideAccreditationApproval(Investor $investor, string $reason, $admin = null): Investor
    {
        return DB::transaction(function () use ($investor, $reason, $admin) {
            $request = $this->createIntegrationRequest($investor, 'manual', 'accreditation_approval_override', [
                'status' => 'completed',
                'request_payload' => [
                    'reason' => $reason,
                    'admin_id' => $admin?->id,
                    'admin_email' => $admin?->email,
                ],
                'response_payload' => ['message' => 'Accreditation approved by admin override.'],
                'completed_at' => now(),
                'created_by' => $admin?->id,
            ]);

            $updates = [
                'accreditation_status' => 'accredited',
                'accreditation_verification_status' => 'verification_approved',
            ];
            if (in_array($investor->investment_status, ['awaiting_kyc', 'awaiting_accreditation_verification'], true)) {
                $updates['investment_status'] = 'awaiting_documents';
            }
            $this->updateInvestor($investor, $updates);

            $this->logActivity(
                $investor,
                'accreditation_override',
                'Accreditation manually approved (override)',
                'Admin bypassed InvestReady and marked accreditation approved. Reason: '.$reason,
                ['integrationRequestId' => $request->id, 'adminId' => $admin?->id]
            );

            return $this->freshInvestor($investor);
        });
    }

    public function overrideDocumentSigning(Investor $investor, string $reason, $admin = null): Investor
    {
        return DB::transaction(function () use ($investor, $reason, $admin) {
            $request = $this->createIntegrationRequest($investor, 'manual', 'document_signing_override', [
                'status' => 'completed',
                'request_payload' => [
                    'reason' => $reason,
                    'admin_id' => $admin?->id,
                    'admin_email' => $admin?->email,
                ],
                'response_payload' => ['message' => 'Documents marked signed by admin override.'],
                'completed_at' => now(),
                'created_by' => $admin?->id,
            ]);

            $updates = [
                'document_signing_status' => 'completed',
            ];
            if (in_array($investor->investment_status, ['awaiting_documents', 'awaiting_legal_approval'], true)) {
                $updates['investment_status'] = 'awaiting_funding';
            }
            $this->updateInvestor($investor, $updates);

            $this->logActivity(
                $investor,
                'documents_override',
                'Documents manually marked signed (override)',
                'Admin bypassed DocuSign and marked documents complete. Reason: '.$reason,
                ['integrationRequestId' => $request->id, 'adminId' => $admin?->id]
            );

            return $this->freshInvestor($investor);
        });
    }

    public function overrideMarkFunded(Investor $investor, float $amount, string $reason, $admin = null): Investor
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('amount must be greater than zero');
        }

        return DB::transaction(function () use ($investor, $amount, $reason, $admin) {
            $request = $this->createIntegrationRequest($investor, 'manual', 'funding_override', [
                'status' => 'completed',
                'request_payload' => [
                    'amount' => $amount,
                    'reason' => $reason,
                    'admin_id' => $admin?->id,
                    'admin_email' => $admin?->email,
                ],
                'response_payload' => ['message' => sprintf('Funded $%s by admin override.', number_format($amount, 2))],
                'completed_at' => now(),
                'created_by' => $admin?->id,
            ]);

            // Record a PaymentConfirmation so it appears alongside real ACH payments.
            PaymentConfirmation::create([
                'investor_profile_id' => $investor->id,
                'status' => 'confirmed',
                'amount' => $amount,
                'reference' => 'manual-'.now()->timestamp,
                'notes' => 'Manual override: '.$reason,
            ]);

            $this->updateInvestor($investor, [
                'investment_funded' => $amount,
                'investment_status' => 'funds_confirmed',
                'investment_wallet_status' => 'Funds received (manual override)',
                'dashboard_status' => 'active',
            ]);

            $this->upsertHoldingFromFunding($investor, $amount);

            $this->logActivity(
                $investor,
                'funding_override',
                'Funds manually confirmed (override)',
                sprintf('Admin bypassed Stripe and marked $%s funded. Reason: %s', number_format($amount, 2), $reason),
                ['integrationRequestId' => $request->id, 'amount' => $amount, 'adminId' => $admin?->id]
            );

            return $this->freshInvestor($investor);
        });
    }

    public function overrideFullyActivate(Investor $investor, ?float $amount, string $reason, $admin = null): Investor
    {
        return DB::transaction(function () use ($investor, $amount, $reason, $admin) {
            $resolvedAmount = $amount ?: (float) ($investor->investment_commitment ?: 0);

            $request = $this->createIntegrationRequest($investor, 'manual', 'fully_activate_override', [
                'status' => 'completed',
                'request_payload' => [
                    'amount' => $resolvedAmount,
                    'reason' => $reason,
                    'admin_id' => $admin?->id,
                    'admin_email' => $admin?->email,
                ],
                'response_payload' => ['message' => 'Investor fully activated by admin override.'],
                'completed_at' => now(),
                'created_by' => $admin?->id,
            ]);

            $updates = [
                'kyc_status' => 'approved',
                'accreditation_status' => $investor->accreditation_status ?: 'accredited',
                'accreditation_verification_status' => 'verification_approved',
                'document_signing_status' => 'completed',
                'investment_status' => 'active',
                'dashboard_status' => 'active',
                'investment_wallet_status' => 'Funds received (manual override)',
            ];
            if ($resolvedAmount > 0) {
                $updates['investment_funded'] = $resolvedAmount;
            }
            $this->updateInvestor($investor, $updates);

            if ($resolvedAmount > 0) {
                PaymentConfirmation::create([
                    'investor_profile_id' => $investor->id,
                    'status' => 'confirmed',
                    'amount' => $resolvedAmount,
                    'reference' => 'manual-'.now()->timestamp,
                    'notes' => 'Manual override (fully activate): '.$reason,
                ]);

                $this->upsertHoldingFromFunding($investor, $resolvedAmount);
            }

            $this->logActivity(
                $investor,
                'fully_activate_override',
                'Investor fully activated (override)',
                'Admin set KYC, accreditation, documents, funding and active status in one action. Reason: '.$reason,
                ['integrationRequestId' => $request->id, 'amount' => $resolvedAmount, 'adminId' => $admin?->id]
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

    /**
     * Create or top up a FundHolding for an investor based on a funded amount.
     * Uses the fund identified by the investor's investment_fund_name (falls
     * back to the first active fund). Units are computed at the latest NAV.
     *
     * Safe to call multiple times — re-runs update the holding additively.
     */
    private function upsertHoldingFromFunding(Investor $investor, float $amount): void
    {
        if ($amount <= 0) {
            return;
        }

        // Pick the fund: match by name first, otherwise fall back to any active fund.
        $fund = null;
        if (! empty($investor->investment_fund_name)) {
            $fund = Fund::where('name', $investor->investment_fund_name)->first();
        }
        $fund = $fund ?? Fund::where('status', 'active')->first();

        if (! $fund) {
            Log::warning('upsertHoldingFromFunding: no fund available', [
                'investor' => $investor->code,
                'amount' => $amount,
            ]);
            return;
        }

        $latestPrice = $fund->currentUnitPrice();
        $price = (float) ($latestPrice?->price ?? 100.0);
        if ($price <= 0) {
            return;
        }

        $newUnits = round($amount / $price, 6);

        $existing = FundHolding::where('investor_id', $investor->id)
            ->where('fund_id', $fund->id)
            ->first();

        if ($existing) {
            // Top up: blend the average price over the combined cost basis.
            $prevAmount = (float) $existing->amount_invested;
            $prevUnits = (float) $existing->units;
            $totalUnits = round($prevUnits + $newUnits, 6);
            $totalAmount = round($prevAmount + $amount, 2);
            $avgPrice = $totalUnits > 0 ? round($totalAmount / $totalUnits, 4) : $price;

            $existing->update([
                'units' => $totalUnits,
                'amount_invested' => $totalAmount,
                'average_unit_price' => $avgPrice,
            ]);
        } else {
            FundHolding::create([
                'investor_id' => $investor->id,
                'fund_id' => $fund->id,
                'units' => $newUnits,
                'amount_invested' => $amount,
                'average_unit_price' => $price,
                'first_invested_at' => now(),
            ]);
        }
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
