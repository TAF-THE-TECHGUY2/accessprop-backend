<?php

namespace App\Mail;

use App\Models\Investor;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class InvestorPasswordResetMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Investor $investor,
        public string $resetUrl,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Reset your Access Properties password',
            replyTo: [
                new Address('hello@ap.boston', 'Access Properties'),
            ],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.investor-password-reset',
            text: 'emails.investor-password-reset-text',
            with: [
                'firstName' => explode(' ', trim($this->investor->name))[0] ?? $this->investor->name,
                'resetUrl' => $this->resetUrl,
            ],
        );
    }
}
