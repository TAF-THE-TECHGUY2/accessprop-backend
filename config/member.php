<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Member session cookie
    |--------------------------------------------------------------------------
    |
    | Investor login hands the dashboard a Sanctum bearer token, which lives in
    | localStorage on investor.ap.boston and is unreadable from any other
    | origin. The marketing site (ap.boston) and its CMS (api.ap.boston) need
    | to know the same visitor is signed in, so login also drops a short-lived
    | signed cookie scoped to the parent domain, which every *.ap.boston host
    | receives automatically.
    |
    | The cookie carries a signed JWT, not a session id: the CMS verifies it
    | with the shared secret and never has to call back here. That also means
    | it cannot be revoked before it expires, so keep the TTL short.
    |
    | Leaving `secret` or `domain` unset disables the cookie entirely, which is
    | the right default for local development and for any environment where the
    | two apps are not on a shared parent domain.
    |
    */

    'cookie' => env('MEMBER_COOKIE_NAME', 'ap_member'),

    // Must begin with a dot to cover subdomains, e.g. ".ap.boston".
    'domain' => env('MEMBER_COOKIE_DOMAIN'),

    // Shared with the CMS backend's MEMBER_JWT_SECRET. Rotating it signs
    // everyone out of the gated marketing pages, not out of the dashboard.
    'secret' => env('MEMBER_JWT_SECRET'),

    'ttl' => (int) env('MEMBER_COOKIE_TTL', 120),

    // Only turn this off to test over plain http locally.
    'secure' => (bool) env('MEMBER_COOKIE_SECURE', true),

];
