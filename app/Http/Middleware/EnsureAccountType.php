<?php

namespace App\Http\Middleware;

use App\Models\Investor;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admins and investors both sign in with Sanctum tokens, and `auth:sanctum`
 * accepts either. On its own it let an investor's token call every admin
 * endpoint. This pins each route group to the account type it was built for.
 */
class EnsureAccountType
{
    private const TYPES = [
        'admin' => User::class,
        'investor' => Investor::class,
    ];

    public function handle(Request $request, Closure $next, string $type): Response
    {
        $expected = self::TYPES[$type] ?? null;

        if ($expected === null || ! $request->user() instanceof $expected) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        return $next($request);
    }
}
