<?php

namespace App\Services\DocuSign;

use App\Jobs\DownloadCompletedDocumentJob;
use App\Models\InvestorActivityLog;
use App\Models\SigningEnvelope;

class EnvelopeStatusHandler
{
    /**
     * DocuSign Connect "envelope-completed" / status-changed events arrive with
     * status strings like 'sent', 'delivered', 'completed', 'declined', 'voided'.
     * We map them onto our internal lifecycle and dispatch downstream work.
     */
    public function handle(array $payload, bool $followUp = true): void
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
                $justCompleted = $envelope->status !== SigningEnvelope::STATUS_COMPLETED;
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

        if ($justCompleted ?? false) {
            $this->adoptWholeUnitAmount($envelope);
        }

        if ($envelope->status === SigningEnvelope::STATUS_COMPLETED && ! $envelope->signed_document_path) {
            DownloadCompletedDocumentJob::dispatch($envelope->id);
        }

        // A signer finishing is the moment to email the next one. Never fatal:
        // the hourly follow-up catches anything missed here.
        if ($followUp) {
            try {
                app(AgreementNotifier::class)->followUp($envelope->fresh());
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Agreement follow-up after webhook failed', [
                    'envelope' => $envelope->docusign_envelope_id,
                    'error' => $e->getMessage(),
                ]);
            }
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

    /**
     * A whole-Unit agreement signs for a little less than the investor offered
     * ($9,991.67 for 695 Units, not $10,000). Once signed, that is what they
     * owe, so the commitment the payment step charges follows it.
     */
    private function adoptWholeUnitAmount(SigningEnvelope $envelope): void
    {
        $pricing = $envelope->pricing;
        $investor = $envelope->investor;

        if (! $investor || empty($pricing['whole_units']) || ! isset($pricing['amount'], $pricing['requested_amount'])) {
            return;
        }

        $signed = (float) $pricing['amount'];
        $commitment = (float) $investor->investment_commitment;

        // Only the commitment this agreement priced; a later edit by an admin wins.
        if (abs($commitment - (float) $pricing['requested_amount']) >= 0.01 || abs($commitment - $signed) < 0.01) {
            return;
        }

        $investor->forceFill(['investment_commitment' => $signed])->save();

        InvestorActivityLog::create([
            'investor_profile_id' => $investor->id,
            'category' => 'processing',
            'action' => 'commitment_adjusted_to_whole_units',
            'title' => 'Commitment adjusted to whole Units',
            'description' => sprintf(
                'Commitment changed from $%s to $%s: %s whole Units at $%s per Unit, as signed.',
                number_format($commitment, 2),
                number_format($signed, 2),
                number_format((float) $pricing['units'], 0),
                number_format((float) $pricing['price_per_unit'], 4),
            ),
            'metadata' => ['signingEnvelopeId' => $envelope->id],
            'occurred_at' => now(),
        ]);
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
            SigningEnvelope::STATUS_DELIVERED, SigningEnvelope::STATUS_SENT => 'out_for_signature',
            default => $investor->document_signing_status,
        };

        if ($investor->document_signing_status !== $mapped) {
            $investor->forceFill(['document_signing_status' => $mapped])->save();
        }
    }
}
