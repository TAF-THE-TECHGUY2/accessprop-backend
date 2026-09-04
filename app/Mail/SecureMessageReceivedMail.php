<?php

namespace App\Mail;

use App\Models\MessageThread;
use App\Models\ThreadMessage;
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

        return $this
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
