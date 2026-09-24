<?php

namespace App\Http\Controllers\Investor;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\InvestorResource;
use App\Models\Investor;
use App\Models\Setting;
use App\Support\MemberSessionCookie;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Hash;

class InvestorAuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $investor = Investor::where('email', $validated['email'])->first();

        if (! $investor || ! $investor->password || ! Hash::check($validated['password'], $investor->password)) {
            return response()->json(['message' => 'Invalid credentials'], 422);
        }

        $token = $investor->createToken('investor-dashboard', ['investor'])->plainTextToken;

        $response = response()->json([
            'token' => $token,
            'investor' => new InvestorResource($this->loadRelations($investor)),
        ]);

        // Also unlocks the gated fund pages on ap.boston -- see config/member.php.
        if ($cookie = MemberSessionCookie::make($investor)) {
            $response->withCookie($cookie);
        }

        return $response;
    }

    /**
     * Re-issues the cross-subdomain cookie for a browser that already holds a
     * valid dashboard token. Without this, investors who signed in before the
     * cookie existed -- or whose cookie has simply expired -- stay locked out
     * of the gated marketing pages until their next login.
     */
    public function refreshSession(Request $request): JsonResponse
    {
        $response = response()->json(['refreshed' => MemberSessionCookie::enabled()]);

        if ($cookie = MemberSessionCookie::make($request->user())) {
            $response->withCookie($cookie);
        }

        return $response;
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        $response = response()->json(['message' => 'Logged out']);

        if ($cookie = MemberSessionCookie::forget()) {
            $response->withCookie($cookie);
        }

        return $response;
    }

    public function me(Request $request): JsonResource
    {
        $investor = $this->loadRelations($request->user());
        $setting = Setting::singleton();

        return (new InvestorResource($investor))->additional([
            'platform' => [
                'allowParallelOnboarding' => (bool) $setting->allow_parallel_onboarding,
            ],
        ]);
    }

    private function loadRelations(Investor $investor): Investor
    {
        return $investor->load([
            'documents',
            'activities',
            'messages',
            'integrationRequests',
            'fundingInstructions',
            'paymentConfirmations',
            'partnerMatches',
            'activityLogs',
        ]);
    }
}
