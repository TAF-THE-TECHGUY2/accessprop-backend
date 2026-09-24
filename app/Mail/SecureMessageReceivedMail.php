<?php

namespace App\Mail;

use App\Models\MessageThread;
use App\Models\ThreadMessage;
use App\Support\MailSender;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Tells the team an investor has written in.
 *
 * Team-side only: investors are notified in the portal, so there is no
 * investor-facing template to keep in sync here. The body deliberately carries
 * the message, since the point is that somebody reads it within the business
 * day the portal promises.
 */
class SecureMessageReceivedMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public MessageThread $thread,
        public ThreadMessage $message,
    ) {}

    public function build(): self
    {
        $investor = $this->thread->investor;

        $mail = $this
            ->subject(sprintf(
                '[%s] %s — %s',
                $this->categoryLabel(),
                $this->thread->subject,
                $investor->code,
            ))
            ->replyTo($investor->email, $investor->name)
            ->text('emails.secure-message-received', [
                'thread' => $this->thread,
                'message' => $this->message,
                'investor' => $investor,
            ]);

        // Reply-To stays the investor -- hitting reply should answer them, not
        // the platform mailbox -- but From follows the admin setting like every
        // other email. Left unset if nothing resolves, so Laravel's global
        // default still applies.
        if ($from = MailSender::from()) {
            $mail->from($from);
        }

        return $mail;
    }

    private function categoryLabel(): string
    {
        return match ($this->thread->category) {
            'call_request' => 'Call request',
            'documents' => 'Document query',
            default => 'Secure message',
        };
    }
}
