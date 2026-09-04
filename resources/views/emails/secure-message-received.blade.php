{{ $investor->name }} ({{ $investor->code }}) sent a secure message.

Subject: {{ $thread->subject }}
Category: {{ $thread->category }}
Investor email: {{ $investor->email }}

---
{{ $message->body }}
---

Reply in the admin console under Communications → Secure messages, so the
investor sees it in their portal. Replying to this email reaches the investor
directly but is not recorded on the thread.
