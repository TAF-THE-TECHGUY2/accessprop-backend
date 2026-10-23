<?php

namespace App\Http\Controllers\Concerns;

use App\Support\TwoFactor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The second half of sign-in, shared by the admin and investor login
 * controllers. They differ only in what a finished sign-in hands back.
 */
trait HandlesTwoFactorLogin
{
    /** Attempts at the code per account per minute, whatever IP they come from. */
    private int $twoFactorAttemptsPerMinute = 5;

    /**
     * After a correct password: null to sign in now, or the challenge response
     * when the account needs a code first.
     */
    protected function twoFactorGate(Request $request, string $type, Model $account): ?JsonResponse
    {
        if (! $account->hasTwoFactorEnabled()) {
            return null;
        }

        if (app(TwoFactor::class)->isTrustedDevice($account, $request->input('deviceToken'))) {
            return null;
        }

        return response()->json([
            'twoFactorRequired' => true,
            'challenge' => app(TwoFactor::class)->makeChallenge($type, $account),
        ]);
    }

    /**
     * Checks the code (or a recovery code) against the challenge. Returns the
     * account to sign in, or the error response to send instead.
     */
    protected function resolveTwoFactorLogin(Request $request, string $type): Model|JsonResponse
    {
        $data = $request->validate([
            'challenge' => ['required', 'string'],
            'code' => ['nullable', 'string', 'max:20'],
            'recoveryCode' => ['nullable', 'string', 'max:40'],
            'remember' => ['nullable', 'boolean'],
        ]);

        $twoFactor = app(TwoFactor::class);
        $account = $twoFactor->resolveChallenge($type, $data['challenge']);

        if (! $account) {
            return response()->json([
                'message' => 'This sign-in has expired. Please enter your email and password again.',
                'code' => 'challenge_expired',
            ], 422);
        }

        $key = 'two-factor:'.$type.':'.$account->getKey();

        if (RateLimiter::tooManyAttempts($key, $this->twoFactorAttemptsPerMinute)) {
            return response()->json([
                'message' => 'Too many attempts. Wait a minute and try again.',
            ], 429);
        }

        $passed = ! empty($data['recoveryCode'])
            ? $twoFactor->useRecoveryCode($account, $data['recoveryCode'])
            : $twoFactor->verifyCode($account, (string) ($data['code'] ?? ''));

        if (! $passed) {
            RateLimiter::hit($key, 60);

            return response()->json([
                'message' => ! empty($data['recoveryCode'])
                    ? 'That recovery code is not valid, or has already been used.'
                    : 'That code is not right. Check your authenticator app and try again.',
                'errors' => [
                    (! empty($data['recoveryCode']) ? 'recoveryCode' : 'code') => ['Invalid code.'],
                ],
            ], 422);
        }

        RateLimiter::clear($key);

        return $account;
    }

    /** The raw device token to return, when the person ticked "remember". */
    protected function rememberDeviceIfAsked(Request $request, Model $account): ?string
    {
        return $request->boolean('remember')
            ? app(TwoFactor::class)->trustDevice($account, $request->userAgent())
            : null;
    }
}
