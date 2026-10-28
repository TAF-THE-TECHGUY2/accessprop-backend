<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticator sign-in is required. Until it is on, the only endpoints that
 * answer are the ones needed to set it up (and to sign out). Admins have no
 * way round it; an investor may decline it by accepting the risk warning.
 * Tokens issued before this existed are caught here too.
 */
class EnsureTwoFactorEnrolled
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->needsTwoFactorSetup() ?? true) {
            return response()->json([
                'message' => 'Set up two-factor sign-in to continue.',
                'code' => 'two_factor_setup_required',
            ], 403);
        }

        return $next($request);
    }
}
