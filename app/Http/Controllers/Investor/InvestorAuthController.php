<?php

namespace App\Http\Controllers\Investor;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\InvestorResource;
use App\Models\Investor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

        return response()->json([
            'token' => $token,
            'investor' => new InvestorResource($this->loadRelations($investor)),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out']);
    }

    public function me(Request $request): InvestorResource
    {
        return new InvestorResource($this->loadRelations($request->user()));
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
