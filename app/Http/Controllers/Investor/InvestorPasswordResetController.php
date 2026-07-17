<?php

namespace App\Http\Controllers\Investor;

use App\Http\Controllers\Controller;
use App\Mail\InvestorPasswordResetMail;
use App\Models\EmailLog;
use App\Models\Investor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

class InvestorPasswordResetController extends Controller
{
    private const TOKEN_TTL_MINUTES = 60;

    public function forgot(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
        ]);

        // Always respond with the same message so the endpoint can't be used
        // to probe which emails have accounts.
        $genericResponse = response()->json([
            'message' => 'If an account exists for that email, a reset link has been sent.',
        ]);

        $investor = Investor::where('email', $validated['email'])->first();

        if (! $investor) {
            return $genericResponse;
        }

        $token = Str::random(64);

        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $investor->email],
            ['token' => Hash::make($token), 'created_at' => now()]
        );

        $resetUrl = rtrim(config('app.frontend_url'), '/')
            .'/reset-password?token='.$token
            .'&email='.urlencode($investor->email);

        $this->sendResetEmail($investor, $resetUrl);

        return $genericResponse;
    }

    public function reset(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'token' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'max:255', 'confirmed'],
        ]);

        $record = DB::table('password_reset_tokens')
            ->where('email', $validated['email'])
            ->first();

        $expired = $record
            && now()->diffInMinutes($record->created_at, true) > self::TOKEN_TTL_MINUTES;

        if (! $record || $expired || ! Hash::check($validated['token'], $record->token)) {
            return response()->json([
                'message' => 'This password reset link is invalid or has expired. Please request a new one.',
            ], 422);
        }

        $investor = Investor::where('email', $validated['email'])->first();

        if (! $investor) {
            return response()->json([
                'message' => 'This password reset link is invalid or has expired. Please request a new one.',
            ], 422);
        }

        $investor->forceFill(['password' => $validated['password']])->save();

        DB::table('password_reset_tokens')->where('email', $validated['email'])->delete();

        // Revoke existing sessions so a stolen token can't outlive the reset.
        $investor->tokens()->delete();

        return response()->json([
            'message' => 'Your password has been reset. You can now sign in.',
        ]);
    }

    private function sendResetEmail(Investor $investor, string $resetUrl): void
    {
        $status = 'sent';

        try {
            Mail::to($investor->email)->send(new InvestorPasswordResetMail($investor, $resetUrl));
        } catch (Throwable $e) {
            $status = 'failed';
            Log::warning('Investor password reset email failed', [
                'investor_code' => $investor->code,
                'email' => $investor->email,
                'error' => $e->getMessage(),
            ]);
        }

        EmailLog::create([
            'code' => 'eml-'.$investor->code.'-pwreset-'.now()->timestamp,
            'recipient' => $investor->email,
            'type' => 'password_reset',
            'subject' => 'Reset your Access Properties password',
            'status' => $status,
            'sent_at' => now(),
        ]);
    }
}
