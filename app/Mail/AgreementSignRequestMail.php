<?php

namespace App\Mail;

use App\Mail\Concerns\UsesEmailTemplate;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * "Your documents are ready to sign", sent by the app in place of DocuSign's
 * own email — one template for the counter signer, one for the investor. A
 * reminder is the same email with "Reminder:" before the subject.
 */
class AgreementSignRequestMail extends Mailable
{
    use Queueable, SerializesModels, UsesEmailTemplate;

    public const KEYS = [
        'counter_signer' => 'agreement_countersign_request',
        'investor' => 'agreement_sign_request',
    ];

    public const DEFAULT_SUBJECTS = [
        'counter_signer' => 'Subscription agreement ready for countersignature — {{ $investorName }}',
        'investor' => 'Your subscription documents are ready to sign',
    ];

    public const VIEWS = [
        'counter_signer' => 'agreement-countersign-request',
        'investor' => 'agreement-sign-request',
    ];

    public function __construct(
        public string $role,
        public string $signerName,
        public string $investorName,
        public string $fundName,
        public string $signUrl,
        public bool $reminder = false,
    ) {}

    public function envelope(): Envelope
    {
        $key = self::KEYS[$this->role];
        $fallback = \App\Support\SafeTemplate::render(self::DEFAULT_SUBJECTS[$this->role], $this->templateData());
        $subject = $this->templateSubject($key, $fallback, $this->templateData());

        return new Envelope(
            subject: ($this->reminder ? 'Reminder: ' : '').$subject,
            from: $this->templateFrom($key),
            replyTo: $this->templateReplyTo($key, 'hello@ap.boston', 'Access Properties'),
        );
    }

    public function content(): Content
    {
        $view = self::VIEWS[$this->role];

        return $this->templateContent(self::KEYS[$this->role], "emails.{$view}", "emails.{$view}-text", $this->templateData());
    }

    /** Keep in sync with the `variables` stored for these templates. */
    public function templateData(): array
    {
        return [
            'firstName' => explode(' ', trim($this->signerName))[0] ?: $this->signerName,
            'investorName' => $this->investorName,
            'fundName' => $this->fundName,
            'signUrl' => $this->signUrl,
        ];
    }
}
