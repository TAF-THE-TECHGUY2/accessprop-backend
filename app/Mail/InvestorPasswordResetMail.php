<?php

namespace App\Mail;

use App\Mail\Concerns\UsesEmailTemplate;
use App\Models\Investor;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class InvestorPasswordResetMail extends Mailable
{
    use Queueable, SerializesModels, UsesEmailTemplate;

    public const TEMPLATE_KEY = 'investor_password_reset';

    public const DEFAULT_SUBJECT = 'Reset your Access Properties password';

    public function __construct(
        public Investor $investor,
        public string $resetUrl,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->templateSubject(
                self::TEMPLATE_KEY,
                self::DEFAULT_SUBJECT,
                $this->templateData(),
            ),
            replyTo: [
                new Address('hello@ap.boston', 'Access Properties'),
            ],
        );
    }

    public function content(): Content
    {
        return $this->templateContent(
            self::TEMPLATE_KEY,
            'emails.investor-password-reset',
            'emails.investor-password-reset-text',
            $this->templateData(),
        );
    }

    /**
     * Variables exposed to the template. Keep in sync with the `variables`
     * column seeded for this key so the admin UI documents them accurately.
     */
    public function templateData(): array
    {
        return [
            'firstName' => explode(' ', trim($this->investor->name))[0] ?? $this->investor->name,
            'resetUrl' => $this->resetUrl,
            'investor' => $this->investor,
        ];
    }
}
