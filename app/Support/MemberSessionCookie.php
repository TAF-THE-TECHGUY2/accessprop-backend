<?php

namespace App\Support;

use App\Models\Investor;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Cookie as SymfonyCookie;

/**
 * Mints the cross-subdomain cookie that tells the other ap.boston properties
 * this browser is signed in as an investor. See config/member.php.
 */
class MemberSessionCookie
{
    /**
     * Without a secret and a parent domain there is nothing to sign or anywhere
     * to send it, so the whole mechanism stays switched off.
     */
    public static function enabled(): bool
    {
        return filled(config('member.secret')) && filled(config('member.domain'));
    }

    public static function make(Investor $investor): ?SymfonyCookie
    {
        if (! static::enabled()) {
            return null;
        }

        $ttl = max(1, (int) config('member.ttl'));
        $issuedAt = now();

        // Deliberately thin: enough for the marketing site to greet the visitor
        // and decide what to unlock, and nothing that would matter if the
        // cookie were ever read off the wire.
        $token = JWT::encode([
            'sub' => $investor->code,
            'email' => $investor->email,
            'name' => $investor->name,
            'accreditation' => $investor->accreditation_status,
            'iat' => $issuedAt->timestamp,
            'exp' => $issuedAt->copy()->addMinutes($ttl)->timestamp,
        ], config('member.secret'), 'HS256');

        return cookie(
            name: config('member.cookie'),
            value: $token,
            minutes: $ttl,
            path: '/',
            domain: config('member.domain'),
            secure: (bool) config('member.secure'),
            // The marketing site never reads the value, it just gets sent, so
            // keep it away from any script running on those pages.
            httpOnly: true,
            raw: false,
            sameSite: 'lax',
        );
    }

    public static function forget(): ?SymfonyCookie
    {
        if (! static::enabled()) {
            return null;
        }

        return Cookie::forget(config('member.cookie'), '/', config('member.domain'));
    }
}
