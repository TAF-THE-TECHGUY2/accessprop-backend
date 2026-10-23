<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\HandlesTwoFactorLogin;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    use HandlesTwoFactorLogin;

    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Invalid credentials.'],
            ]);
        }

        if ($challenge = $this->twoFactorGate($request, 'admin', $user)) {
            return $challenge;
        }

        return $this->signIn($user);
    }

    public function twoFactor(Request $request): JsonResponse
    {
        $user = $this->resolveTwoFactorLogin($request, 'admin');

        if ($user instanceof JsonResponse) {
            return $user;
        }

        return $this->signIn($user, $this->rememberDeviceIfAsked($request, $user));
    }

    /**
     * An admin without two-factor still gets a token — it is how they reach
     * the setup screen — but EnsureTwoFactorEnrolled keeps every other admin
     * endpoint closed to it until setup is finished.
     */
    private function signIn(User $user, ?string $deviceToken = null): JsonResponse
    {
        return response()->json([
            // A working day. Tokens used to live forever, so one lifted from a
            // browser stayed good until that device signed out.
            'token' => $user->createToken('admin-spa', ['*'], now()->addHours(12))->plainTextToken,
            'user' => $this->present($user),
            'twoFactorSetupRequired' => ! $user->hasTwoFactorEnabled(),
            'deviceToken' => $deviceToken,
        ]);
    }

    private function present(User $user): array
    {
        return $user->only(['id', 'name', 'email']) + [
            'twoFactorEnabled' => $user->hasTwoFactorEnabled(),
        ];
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out.']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'user' => $this->present($request->user()),
        ]);
    }
}
