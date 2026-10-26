<?php

namespace App\Http\Controllers;

use App\Support\TwoFactor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * Setting up and managing authenticator sign-in for the signed-in account.
 * Mounted under both /admin and /investor; $request->user() is whichever
 * account the token belongs to.
 *
 * Admins cannot switch it off: it is required for them. An admin who loses
 * their phone uses a recovery code, or another admin runs
 * `php artisan two-factor:reset`.
 */
class TwoFactorController extends Controller
{
    public function __construct(private TwoFactor $twoFactor) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json($this->twoFactor->status($request->user()));
    }

    public function setup(Request $request): JsonResponse
    {
        $account = $request->user();

        // Restarting setup on an enabled account would switch protection off
        // until it is confirmed again. Turning it off is its own, deliberate step.
        if ($account->hasTwoFactorEnabled()) {
            return response()->json([
                'message' => 'Two-factor sign-in is already on.',
            ], 409);
        }

        return response()->json($this->twoFactor->beginSetup($account));
    }

    public function confirm(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:20']]);
        $account = $request->user();

        if ($account->hasTwoFactorEnabled()) {
            return response()->json(['message' => 'Two-factor sign-in is already on.'], 409);
        }

        $codes = $this->twoFactor->confirm($account, $data['code']);

        if ($codes === null) {
            return response()->json([
                'message' => 'That code is not right. Check the app shows "Access Properties" and try the newest code.',
                'errors' => ['code' => ['Invalid code.']],
            ], 422);
        }

        return response()->json([
            'recoveryCodes' => $codes,
            'status' => $this->twoFactor->status($account),
        ]);
    }

    /**
     * Shows the QR code again so another phone can scan it. Gated on a current
     * code: the secret is the whole of the second factor, so only someone
     * already holding a working authenticator may see it.
     */
    public function addDevice(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:20']]);
        $account = $request->user();

        if (! $account->hasTwoFactorEnabled() || ! $this->twoFactor->verifyCode($account, $data['code'])) {
            return response()->json([
                'message' => 'That code is not right.',
                'errors' => ['code' => ['Invalid code.']],
            ], 422);
        }

        return response()->json($this->twoFactor->provisioning($account));
    }

    public function regenerateRecoveryCodes(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:20']]);
        $account = $request->user();

        if (! $account->hasTwoFactorEnabled() || ! $this->twoFactor->verifyCode($account, $data['code'])) {
            return response()->json([
                'message' => 'That code is not right.',
                'errors' => ['code' => ['Invalid code.']],
            ], 422);
        }

        return response()->json([
            'recoveryCodes' => $this->twoFactor->regenerateRecoveryCodes($account),
        ]);
    }

    public function forgetDevices(Request $request): JsonResponse
    {
        $this->twoFactor->forgetDevices($request->user());

        return response()->json($this->twoFactor->status($request->user()));
    }

    /** Investors only — see the class note. Needs the password as well as a code. */
    public function disable(Request $request): JsonResponse
    {
        $data = $request->validate([
            'password' => ['required', 'string'],
            'code' => ['required', 'string', 'max:20'],
        ]);
        $account = $request->user();

        if (! Hash::check($data['password'], $account->password)) {
            return response()->json([
                'message' => 'That password is not right.',
                'errors' => ['password' => ['Incorrect password.']],
            ], 422);
        }

        if ($account->hasTwoFactorEnabled() && ! $this->twoFactor->verifyCode($account, $data['code'])) {
            return response()->json([
                'message' => 'That code is not right.',
                'errors' => ['code' => ['Invalid code.']],
            ], 422);
        }

        $this->twoFactor->disable($account);

        return response()->json($this->twoFactor->status($account));
    }
}
