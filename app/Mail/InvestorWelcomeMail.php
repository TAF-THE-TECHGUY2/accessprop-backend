<?php

namespace App\Mail;

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
    use Queueable, SerializesModels;

    public function __construct(public Investor $investor)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Welcome to Access Properties',
            replyTo: [
                new Address('hello@ap.boston', 'Access Properties'),
            ],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.investor-welcome',
            text: 'emails.investor-welcome-text',
            with: [
                'firstName' => explode(' ', trim($this->investor->name))[0] ?? $this->investor->name,
                'investorCode' => $this->investor->code,
            ],
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
}
