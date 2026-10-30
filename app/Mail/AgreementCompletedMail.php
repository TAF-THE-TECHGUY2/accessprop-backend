<?php

namespace App\Mail;

use App\Mail\Concerns\UsesEmailTemplate;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** "Your documents are signed", sent to the investor once everyone has signed. */
class AgreementCompletedMail extends Mailable
{
    use Queueable, SerializesModels, UsesEmailTemplate;

    public const TEMPLATE_KEY = 'agreement_completed';

    public const DEFAULT_SUBJECT = 'Your subscription documents are signed';

    public function __construct(
        public string $investorName,
        public string $fundName,
        public string $portalUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->templateSubject(self::TEMPLATE_KEY, self::DEFAULT_SUBJECT, $this->templateData()),
            from: $this->templateFrom(self::TEMPLATE_KEY),
            replyTo: $this->templateReplyTo(self::TEMPLATE_KEY, 'hello@ap.boston', 'Access Properties'),
        );
    }

    public function content(): Content
    {
        return $this->templateContent(self::TEMPLATE_KEY, 'emails.agreement-completed', 'emails.agreement-completed-text', $this->templateData());
    }

    public function templateData(): array
    {
        return [
            'firstName' => explode(' ', trim($this->investorName))[0] ?: $this->investorName,
            'fundName' => $this->fundName,
            'portalUrl' => $this->portalUrl,
        ];
    }
}
