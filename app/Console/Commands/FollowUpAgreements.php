<?php

namespace App\Console\Commands;

use App\Models\SigningEnvelope;
use App\Services\DocuSign\AgreementNotifier;
use Illuminate\Console\Command;
use Throwable;

/**
 * Runs hourly. For each agreement out for signature through the app: email
 * the signer whose turn it now is, remind them on schedule, and send the
 * "all signed" email once it completes.
 */
class FollowUpAgreements extends Command
{
    protected $signature = 'agreements:follow-up';

    protected $description = 'Email the next signer, send reminders, and confirm completed agreements';

    public function handle(AgreementNotifier $notifier): int
    {
        $envelopes = SigningEnvelope::query()
            ->where('embedded_signing', true)
            ->where(function ($q) {
                $q->whereNotIn('status', [
                    SigningEnvelope::STATUS_COMPLETED,
                    SigningEnvelope::STATUS_VOIDED,
                    SigningEnvelope::STATUS_DECLINED,
                ])->orWhere(fn ($q) => $q->where('status', SigningEnvelope::STATUS_COMPLETED)
                    ->whereNull('completion_emailed_at'));
            })
            ->get();

        foreach ($envelopes as $envelope) {
            try {
                $notifier->followUp($envelope);
            } catch (Throwable $e) {
                $this->warn("Envelope {$envelope->docusign_envelope_id}: {$e->getMessage()}");
            }
        }

        $this->info("Followed up {$envelopes->count()} agreement(s).");

        return self::SUCCESS;
    }
}
