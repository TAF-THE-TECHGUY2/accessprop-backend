<?php

namespace App\Http\Controllers\Investor;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Password change for a signed-in investor.
 *
 * Distinct from InvestorPasswordResetController, which serves someone locked
 * out and proves identity with an emailed token. Here the session already
 * proves identity, so the current password is what guards against a walked-up
 * browser changing the credential.
 */
class InvestorPortalPasswordController extends Controller
{
    public function update(Request $request): JsonResponse
    {
        $investor = $request->user();

        $validated = $request->validate([
            'currentPassword' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        if (! Hash::check($validated['currentPassword'], $investor->password)) {
            throw ValidationException::withMessages([
                'currentPassword' => 'That is not your current password.',
            ]);
        }

        $investor->update(['password' => $validated['password']]);

        // Every other session was authenticated with the old credential. Leaving
        // them alive means a changed password evicts nobody, which is the whole
        // reason someone changes it after a device is lost.
        $current = $request->user()->currentAccessToken();
        $investor->tokens()->where('id', '!=', $current?->id)->delete();

        return response()->json([
            'message' => 'Password updated. Any other signed-in devices have been logged out.',
        ]);
    }
}
