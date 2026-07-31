<?php

namespace App\Mail;

use App\Mail\Concerns\UsesEmailTemplate;
use App\Models\Investor;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

class InvestorWelcomeMail extends Mailable
{
    use Queueable, SerializesModels, UsesEmailTemplate;

    public const TEMPLATE_KEY = 'investor_welcome';

    public const DEFAULT_SUBJECT = 'Welcome to Access Properties';

    public function __construct(public Investor $investor)
    {
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
            'emails.investor-welcome',
            'emails.investor-welcome-text',
            $this->templateData(),
        );
    }

    public function headers(): Headers
    {
        return new Headers(
            text: [
                'List-Unsubscribe' => '<mailto:unsubscribe@ap.boston?subject=unsubscribe>',
                'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
            ],
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
            'investorCode' => $this->investor->code,
            'investor' => $this->investor,
        ];
    }
}
