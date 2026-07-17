<?php

namespace Tests\Feature;

use App\Mail\InvestorPasswordResetMail;
use App\Models\Investor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class InvestorPasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private function makeInvestor(array $overrides = []): Investor
    {
        return Investor::create(array_merge([
            'code' => 'inv-9001',
            'name' => 'Test Investor',
            'email' => 'investor@example.com',
            'password' => 'old-password-123',
            'country' => 'United States',
            'joined_at' => now(),
            'accreditation_status' => 'accredited',
            'kyc_status' => 'pending',
            'investment_status' => 'awaiting_kyc',
            'dashboard_status' => 'pending',
            'address_line1' => '1 Main St',
            'address_city' => 'Boston',
            'address_state' => 'MA',
            'address_postal_code' => '02110',
            'address_country' => 'United States',
            'personal_investor_type' => 'Individual',
            'personal_residency' => 'U.S. Person',
            'investment_fund_name' => 'Fund I',
            'investment_wallet_status' => 'KYC required',
            'investment_expected_yield' => '8.0% target',
        ], $overrides));
    }

    public function test_forgot_sends_reset_email_and_stores_token(): void
    {
        Mail::fake();
        $investor = $this->makeInvestor();

        $response = $this->postJson('/api/investor/password/forgot', [
            'email' => $investor->email,
        ]);

        $response->assertOk();
        Mail::assertSent(InvestorPasswordResetMail::class, function ($mail) use ($investor) {
            return $mail->hasTo($investor->email)
                && str_contains($mail->resetUrl, '/reset-password?token=');
        });
        $this->assertDatabaseHas('password_reset_tokens', ['email' => $investor->email]);
    }

    public function test_forgot_with_unknown_email_returns_generic_success(): void
    {
        Mail::fake();

        $response = $this->postJson('/api/investor/password/forgot', [
            'email' => 'nobody@example.com',
        ]);

        $response->assertOk();
        Mail::assertNothingSent();
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => 'nobody@example.com']);
    }

    public function test_reset_updates_password_with_valid_token(): void
    {
        $investor = $this->makeInvestor();

        DB::table('password_reset_tokens')->insert([
            'email' => $investor->email,
            'token' => Hash::make('valid-token'),
            'created_at' => now(),
        ]);

        $response = $this->postJson('/api/investor/password/reset', [
            'email' => $investor->email,
            'token' => 'valid-token',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ]);

        $response->assertOk();
        $this->assertTrue(Hash::check('new-password-123', $investor->fresh()->password));
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $investor->email]);

        // Old password no longer works, new one does.
        $this->postJson('/api/investor/login', [
            'email' => $investor->email,
            'password' => 'new-password-123',
        ])->assertOk();
    }

    public function test_reset_rejects_invalid_token(): void
    {
        $investor = $this->makeInvestor();

        DB::table('password_reset_tokens')->insert([
            'email' => $investor->email,
            'token' => Hash::make('valid-token'),
            'created_at' => now(),
        ]);

        $this->postJson('/api/investor/password/reset', [
            'email' => $investor->email,
            'token' => 'wrong-token',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertStatus(422);

        $this->assertTrue(Hash::check('old-password-123', $investor->fresh()->password));
    }

    public function test_reset_rejects_expired_token(): void
    {
        $investor = $this->makeInvestor();

        DB::table('password_reset_tokens')->insert([
            'email' => $investor->email,
            'token' => Hash::make('valid-token'),
            'created_at' => now()->subMinutes(61),
        ]);

        $this->postJson('/api/investor/password/reset', [
            'email' => $investor->email,
            'token' => 'valid-token',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertStatus(422);
    }
}
