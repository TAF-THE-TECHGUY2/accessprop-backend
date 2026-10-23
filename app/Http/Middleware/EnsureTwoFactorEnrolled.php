<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admins must have authenticator sign-in on. Until they do, the only admin
 * endpoints that answer are the ones needed to set it up (and to sign out).
 * Tokens issued before this existed are caught here too.
 */
class EnsureTwoFactorEnrolled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->hasTwoFactorEnabled()) {
            return response()->json([
                'message' => 'Set up two-factor sign-in to continue.',
                'code' => 'two_factor_setup_required',
            ], 403);
        }

        return $next($request);
    }
}
