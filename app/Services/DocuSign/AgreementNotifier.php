<?php

namespace App\Services\DocuSign;

use App\Mail\AgreementCompletedMail;
use App\Mail\AgreementSignRequestMail;
use App\Models\EmailLog;
use App\Models\SigningEnvelope;
use App\Models\SigningLink;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * The app's own signing emails, in place of DocuSign's: who is up, when to
 * email them, when to remind them, and the "all signed" email at the end.
 *
 * It asks DocuSign which signer has finished rather than waiting to be told,
 * so it works from the hourly follow-up even with no Connect webhook; with
 * one, each signing event runs it at once.
 */
class AgreementNotifier
{
    private const CLOSED = [SigningEnvelope::STATUS_VOIDED, SigningEnvelope::STATUS_DECLINED];

    public function __construct(private Client $client) {}

    public function followUp(SigningEnvelope $envelope): void
    {
        if (! $envelope->embedded_signing || in_array($envelope->status, self::CLOSED, true)) {
            return;
        }

        $state = $this->state($envelope);

        foreach ($state['roles'] as $role => $info) {
            if ($info['status'] === 'completed') {
                SigningLink::where('signing_envelope_id', $envelope->id)->where('role', $role)
                    ->whereNull('signed_at')->update(['signed_at' => now()]);
            }
        }

        // No webhook needed to see an envelope finish, or be voided or declined.
        if (in_array($state['envelope'], ['completed', 'voided', 'declined'], true) && $envelope->status !== $state['envelope']) {
            app(EnvelopeStatusHandler::class)->handle(
                ['envelopeId' => $envelope->docusign_envelope_id, 'status' => $state['envelope']],
                followUp: false,
            );
            $envelope->refresh();
        }

        if ($envelope->status === SigningEnvelope::STATUS_COMPLETED) {
            $this->sendCompletionOnce($envelope);

            return;
        }

        // Only while it is out for signing: not a draft, not closed.
        if (! in_array($state['envelope'], ['sent', 'delivered'], true)) {
            return;
        }

        $current = $this->currentRole($state['roles']);
        if ($current === null) {
            return;
        }

        $link = SigningLink::issue($envelope, $current);

        if ($link->requested_at === null) {
            $this->sendRequest($envelope, $link, reminder: false);
            $link->update(['requested_at' => now(), 'last_emailed_at' => now()]);

            return;
        }

        $every = max(1, (int) config('docusign.reminders.every_days', 3));
        $max = (int) config('docusign.reminders.max', 3);

        if ($link->reminders_sent < $max && $link->last_emailed_at?->lte(now()->subDays($every))) {
            $this->sendRequest($envelope, $link, reminder: true);
            $link->update(['last_emailed_at' => now(), 'reminders_sent' => $link->reminders_sent + 1]);
        }
    }

    /** "Resend" from the admin: email whoever's turn it is now, as a reminder. */
    public function resend(SigningEnvelope $envelope): ?string
    {
        $current = $this->currentRole($this->state($envelope)['roles']);
        if ($current === null) {
            return null;
        }

        $link = SigningLink::issue($envelope, $current);
        $this->sendRequest($envelope, $link, reminder: $link->requested_at !== null);
        $link->update([
            'requested_at' => $link->requested_at ?? now(),
            'last_emailed_at' => now(),
        ]);

        return $current;
    }

    /**
     * Each signer's status and order, from DocuSign.
     *
     * @return array{envelope: string, roles: array<string, array{status: string, order: int}>}
     */
    public function state(SigningEnvelope $envelope): array
    {
        $envelopeStatus = (string) $this->client->envelopes()
            ->getEnvelope($this->client->accountId(), $envelope->docusign_envelope_id)
            ->getStatus();

        $roles = [];
        $signers = $this->client->envelopes()
            ->listRecipients($this->client->accountId(), $envelope->docusign_envelope_id)
            ->getSigners() ?? [];

        foreach ($signers as $signer) {
            $role = $signer->getClientUserId() === 'counter-signer' ? 'counter_signer' : 'investor';
            $roles[$role] = [
                'status' => (string) $signer->getStatus(),
                'order' => (int) $signer->getRoutingOrder(),
            ];
        }

        return ['envelope' => $envelopeStatus, 'roles' => $roles];
    }

    /** The first signer in order who has not finished. */
    private function currentRole(array $roles): ?string
    {
        $open = array_filter($roles, fn ($r) => ! in_array($r['status'], ['completed', 'declined'], true));
        if (! $open) {
            return null;
        }

        uasort($open, fn ($a, $b) => $a['order'] <=> $b['order']);

        return array_key_first($open);
    }

    private function sendRequest(SigningEnvelope $envelope, SigningLink $link, bool $reminder): void
    {
        [$email, $name] = $link->role === 'counter_signer'
            ? [$envelope->counter_signer_email, $envelope->counter_signer_name]
            : [$envelope->investor_email, $envelope->investor_name];

        $this->send($email, new AgreementSignRequestMail(
            role: $link->role,
            signerName: (string) $name,
            investorName: (string) $envelope->investor_name,
            fundName: $this->fundName($envelope),
            signUrl: $link->url(),
            reminder: $reminder,
        ), AgreementSignRequestMail::KEYS[$link->role].($reminder ? '_reminder' : ''), $envelope);
    }

    private function sendCompletionOnce(SigningEnvelope $envelope): void
    {
        $claimed = SigningEnvelope::whereKey($envelope->id)
            ->whereNull('completion_emailed_at')
            ->update(['completion_emailed_at' => now()]);

        if (! $claimed) {
            return;
        }

        $this->send($envelope->investor_email, new AgreementCompletedMail(
            investorName: (string) $envelope->investor_name,
            fundName: $this->fundName($envelope),
            portalUrl: rtrim((string) config('app.frontend_url'), '/').'/dashboard/documents',
        ), AgreementCompletedMail::TEMPLATE_KEY, $envelope);
    }

    private function fundName(SigningEnvelope $envelope): string
    {
        $investor = $envelope->investor;

        return (string) ($investor?->investment_fund_name ?: 'Access Real Estate Fund I');
    }

    private function send(?string $to, Mailable $mail, string $type, SigningEnvelope $envelope): void
    {
        if (! $to) {
            return;
        }

        $status = 'sent';
        try {
            Mail::to($to)->send($mail);
        } catch (Throwable $e) {
            $status = 'failed';
            Log::warning('Agreement email failed', ['envelope' => $envelope->docusign_envelope_id, 'type' => $type, 'error' => $e->getMessage()]);
        }

        EmailLog::create([
            'code' => 'eml-'.($envelope->investor?->code ?? 'agreement').'-'.$type.'-'.now()->timestamp.'-'.Str::lower(Str::random(4)),
            'recipient' => $to,
            'type' => $type,
            'subject' => $mail->envelope()->subject,
            'status' => $status,
            'sent_at' => now(),
        ]);
    }
}
