<?php

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // This is an API-only backend. Do not redirect unauthenticated browser
        // requests to a non-existent named web login route.
        $middleware->redirectGuestsTo(null);

        // The member cookie is read by the CMS (a Node service), which cannot
        // undo Laravel's cookie encryption. API routes do not run EncryptCookies
        // today, but say so explicitly so adding it later cannot silently break
        // the gate on ap.boston.
        $middleware->encryptCookies(except: [
            env('MEMBER_COOKIE_NAME', 'ap_member'),
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (AuthenticationException $exception, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }
        });
    })->create();
