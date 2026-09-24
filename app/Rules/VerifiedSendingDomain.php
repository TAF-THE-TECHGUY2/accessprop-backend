<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Str;

/**
 * A From address has to be on a domain the mail provider is authorised to sign
 * for. Mailgun will refuse to send from anything else, and a provider that did
 * accept it would produce mail that fails SPF/DKIM alignment and lands in spam.
 *
 * Reply-To is deliberately NOT subject to this: it is not authenticated, which
 * is exactly why replies can go to hello@ap.boston while the mail is sent from
 * hello@mg.ap.boston.
 */
class VerifiedSendingDomain implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $domain = static::sendingDomain();

        // No configured sending domain (SMTP, log driver, local dev): nothing
        // to check against, so don't block the save.
        if ($domain === null || ! is_string($value) || $value === '') {
            return;
        }

        if (! Str::endsWith(Str::lower($value), '@'.Str::lower($domain))) {
            $fail("The :attribute must be an address on {$domain} — the only domain this platform is verified to send from.");
        }
    }

    public static function sendingDomain(): ?string
    {
        if (config('mail.default') !== 'mailgun') {
            return null;
        }

        $domain = config('services.mailgun.domain');

        return is_string($domain) && $domain !== '' ? $domain : null;
    }
}
