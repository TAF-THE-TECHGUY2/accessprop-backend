<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Mail\Mailables\Address;

/**
 * The platform's outgoing sender identity, as maintained in
 * Settings -> Email sender.
 *
 * Resolved lazily at send time rather than pushed into config at boot, so a
 * settings change takes effect on the next email instead of the next deploy,
 * and no request pays for a query it doesn't need.
 */
class MailSender
{
    /**
     * Falls back to config('mail.from') so mail still addresses itself
     * correctly on a database that is unreachable or not yet migrated.
     */
    public static function from(): ?Address
    {
        $setting = Setting::current();

        $address = static::firstFilled(
            $setting?->mail_from_address,
            config('mail.from.address'),
        );

        if ($address === null) {
            return null;
        }

        $name = static::firstFilled(
            $setting?->mail_from_name,
            config('mail.from.name'),
        );

        return new Address($address, $name ?? '');
    }

    public static function replyToAddress(?string $fallback = null): ?string
    {
        return static::firstFilled(Setting::current()?->mail_reply_to_address, $fallback);
    }

    public static function firstFilled(?string ...$values): ?string
    {
        foreach ($values as $value) {
            if ($value !== null && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }
}
