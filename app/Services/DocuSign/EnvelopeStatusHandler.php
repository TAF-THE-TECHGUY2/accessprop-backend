<?php

namespace App\Services\DocuSign;

use App\Jobs\DownloadCompletedDocumentJob;
use App\Models\SigningEnvelope;

class EnvelopeStatusHandler
{
    /**
     * DocuSign Connect "envelope-completed" / status-changed events arrive with
     * status strings like 'sent', 'delivered', 'completed', 'declined', 'voided'.
     * We map them onto our internal lifecycle and dispatch downstream work.
     */
    public function handle(array $payload): void
    {
        $envelopeId = $this->extractEnvelopeId($payload);
        $newStatus = $this->extractStatus($payload);

        if ($envelopeId === null || $newStatus === null) {
            return;
        }

        $envelope = SigningEnvelope::where('docusign_envelope_id', $envelopeId)->first();
        if (! $envelope) {
            return;
        }

        $envelope->last_event_payload = $payload;

        switch (strtolower($newStatus)) {
            case 'sent':
                if (! $envelope->sent_at) {
                    $envelope->sent_at = now();
                }
                $envelope->status = SigningEnvelope::STATUS_SENT;
                break;

            case 'delivered':
                $envelope->delivered_at = now();
                if (! in_array($envelope->status, [SigningEnvelope::STATUS_COMPLETED, SigningEnvelope::STATUS_VOIDED], true)) {
                    $envelope->status = SigningEnvelope::STATUS_DELIVERED;
                }
                break;

            case 'completed':
                $envelope->completed_at = now();
                if (! $envelope->signed_at) {
                    $envelope->signed_at = now();
                }
                $envelope->status = SigningEnvelope::STATUS_COMPLETED;
                break;

            case 'declined':
                $envelope->status = SigningEnvelope::STATUS_DECLINED;
                $envelope->decline_reason = $this->extractDeclineReason($payload);
                break;

            case 'voided':
                $envelope->status = SigningEnvelope::STATUS_VOIDED;
                $envelope->voided_at = now();
                $envelope->decline_reason = $this->extractVoidReason($payload) ?? $envelope->decline_reason;
                break;

            default:
                break;
        }

        $envelope->save();

        $this->syncInvestorSigningStatus($envelope);

        if ($envelope->status === SigningEnvelope::STATUS_COMPLETED && ! $envelope->signed_document_path) {
            DownloadCompletedDocumentJob::dispatch($envelope->id);
        }
    }

    private function extractEnvelopeId(array $payload): ?string
    {
        return $payload['data']['envelopeId']
            ?? $payload['envelopeId']
            ?? $payload['envelopeSummary']['envelopeId']
            ?? null;
    }

    private function extractStatus(array $payload): ?string
    {
        return $payload['data']['envelopeSummary']['status']
            ?? $payload['data']['status']
            ?? $payload['status']
            ?? $payload['event']
            ?? null;
    }

    private function extractDeclineReason(array $payload): ?string
    {
        $recipients = $payload['data']['envelopeSummary']['recipients']['signers']
            ?? $payload['envelopeSummary']['recipients']['signers']
            ?? [];

        foreach ($recipients as $signer) {
            if (! empty($signer['declinedReason'])) {
                return $signer['declinedReason'];
            }
        }

        return null;
    }

    private function extractVoidReason(array $payload): ?string
    {
        return $payload['data']['envelopeSummary']['voidedReason']
            ?? $payload['envelopeSummary']['voidedReason']
            ?? null;
    }

    private function syncInvestorSigningStatus(SigningEnvelope $envelope): void
    {
        $investor = $envelope->investor;
        if (! $investor) {
            return;
        }

        $mapped = match ($envelope->status) {
            SigningEnvelope::STATUS_COMPLETED => 'completed',
            SigningEnvelope::STATUS_DECLINED => 'declined',
            SigningEnvelope::STATUS_VOIDED => 'voided',
            SigningEnvelope::STATUS_SIGNED_BY_INVESTOR => 'awaiting_countersign',
            SigningEnvelope::STATUS_DELIVERED, SigningEnvelope::STATUS_SENT => 'awaiting_investor',
            default => $investor->document_signing_status,
        };

        if ($investor->document_signing_status !== $mapped) {
            $investor->forceFill(['document_signing_status' => $mapped])->save();
        }
    }
}
