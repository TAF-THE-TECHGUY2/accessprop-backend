<?php

namespace Tests\Feature;

use App\Models\Investor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * Authenticator-app sign-in: required for admins, optional for investors.
 */
class TwoFactorAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private function codeFor(string $secret, int $offsetSteps = 0): string
    {
        $g = new Google2FA;

        return $g->oathTotp($secret, $g->getTimestamp() + $offsetSteps);
    }

    private function investor(array $overrides = []): Investor
    {
        return Investor::create(array_merge([
            'code' => 'inv-8001',
            'name' => 'Sample Investor',
            'email' => 'sample@example.com',
            'password' => 'password123',
            'phone' => '+1 617 555 0100',
            'country' => 'United States',
            'joined_at' => now(),
            'accreditation_status' => 'accredited',
            'kyc_status' => 'approved',
            'investment_status' => 'active',
            'dashboard_status' => 'active',
            'address_line1' => '1 Beacon St',
            'address_city' => 'Boston',
            'address_state' => 'MA',
            'address_postal_code' => '02108',
            'address_country' => 'United States',
            'personal_investor_type' => 'individual',
            'personal_residency' => 'us',
            'investment_fund_name' => 'Access Real Estate Fund I',
            'investment_wallet_status' => 'unfunded',
            'investment_expected_yield' => '8%',
        ], $overrides));
    }

    /** Runs setup + confirm through the API and returns [secret, recoveryCodes]. */
    private function enrol(string $prefix, string $token): array
    {
        // The test client keeps the last resolved user between requests; a
        // real browser would not, so forget it when switching accounts.
        $this->app['auth']->forgetGuards();

        $setup = $this->withToken($token)->postJson("/api/{$prefix}/two-factor/setup")->assertOk();
        $secret = $setup->json('secret');
        $this->assertStringStartsWith('otpauth://totp/', $setup->json('otpauthUrl'));
        $this->assertStringContainsString('<svg', $setup->json('qrSvg'));

        $codes = $this->withToken($token)
            ->postJson("/api/{$prefix}/two-factor/confirm", ['code' => $this->codeFor($secret)])
            ->assertOk()
            ->json('recoveryCodes');

        $this->assertCount(8, $codes);

        return [$secret, $codes];
    }

    // ---- Admins: required ------------------------------------------------

    public function test_an_admin_without_two_factor_is_held_at_setup(): void
    {
        User::factory()->withoutTwoFactor()->create([
            'email' => 'ops@example.com',
            'password' => Hash::make('secret-pass'),
        ]);

        $login = $this->postJson('/api/admin/login', ['email' => 'ops@example.com', 'password' => 'secret-pass'])
            ->assertOk()
            ->assertJsonPath('twoFactorSetupRequired', true);

        $token = $login->json('token');

        $this->withToken($token)->getJson('/api/admin/investors')
            ->assertForbidden()
            ->assertJsonPath('code', 'two_factor_setup_required');

        $this->withToken($token)->getJson('/api/admin/me')->assertOk();

        $this->enrol('admin', $token);

        $this->withToken($token)->getJson('/api/admin/investors')->assertOk();
    }

    public function test_an_enrolled_admin_signs_in_with_a_code(): void
    {
        $admin = User::factory()->withoutTwoFactor()->create([
            'email' => 'ops@example.com',
            'password' => Hash::make('secret-pass'),
        ]);
        [$secret] = $this->enrol('admin', $admin->createToken('t')->plainTextToken);

        $challenge = $this->postJson('/api/admin/login', ['email' => 'ops@example.com', 'password' => 'secret-pass'])
            ->assertOk()
            ->assertJsonPath('twoFactorRequired', true)
            ->assertJsonMissingPath('token')
            ->json('challenge');

        $this->postJson('/api/admin/login/two-factor', ['challenge' => $challenge, 'code' => '000000'])
            ->assertStatus(422);

        // The enrolment code's window is spent, so use the next one.
        $this->postJson('/api/admin/login/two-factor', ['challenge' => $challenge, 'code' => $this->codeFor($secret, 1)])
            ->assertOk()
            ->assertJsonPath('twoFactorSetupRequired', false)
            ->assertJsonStructure(['token']);
    }

    public function test_a_code_cannot_be_replayed(): void
    {
        $admin = User::factory()->withoutTwoFactor()->create(['password' => Hash::make('pw')]);
        [$secret] = $this->enrol('admin', $admin->createToken('t')->plainTextToken);

        $code = $this->codeFor($secret, 1);

        $first = $this->postJson('/api/admin/login', ['email' => $admin->email, 'password' => 'pw'])->json('challenge');
        $this->postJson('/api/admin/login/two-factor', ['challenge' => $first, 'code' => $code])->assertOk();

        $second = $this->postJson('/api/admin/login', ['email' => $admin->email, 'password' => 'pw'])->json('challenge');
        $this->postJson('/api/admin/login/two-factor', ['challenge' => $second, 'code' => $code])->assertStatus(422);
    }

    public function test_a_recovery_code_works_once(): void
    {
        $admin = User::factory()->withoutTwoFactor()->create(['password' => Hash::make('pw')]);
        [, $codes] = $this->enrol('admin', $admin->createToken('t')->plainTextToken);

        $challenge = fn () => $this->postJson('/api/admin/login', ['email' => $admin->email, 'password' => 'pw'])->json('challenge');

        $this->postJson('/api/admin/login/two-factor', ['challenge' => $challenge(), 'recoveryCode' => strtolower($codes[0])])
            ->assertOk();

        $this->postJson('/api/admin/login/two-factor', ['challenge' => $challenge(), 'recoveryCode' => $codes[0]])
            ->assertStatus(422);

        $this->assertCount(7, $admin->fresh()->two_factor_recovery_codes);
    }

    public function test_remember_this_device_skips_the_code_for_thirty_days(): void
    {
        $admin = User::factory()->withoutTwoFactor()->create(['password' => Hash::make('pw')]);
        [$secret] = $this->enrol('admin', $admin->createToken('t')->plainTextToken);

        $challenge = $this->postJson('/api/admin/login', ['email' => $admin->email, 'password' => 'pw'])->json('challenge');
        $device = $this->postJson('/api/admin/login/two-factor', [
            'challenge' => $challenge,
            'code' => $this->codeFor($secret, 1),
            'remember' => true,
        ])->assertOk()->json('deviceToken');

        $this->assertNotEmpty($device);

        $this->postJson('/api/admin/login', ['email' => $admin->email, 'password' => 'pw', 'deviceToken' => $device])
            ->assertOk()
            ->assertJsonStructure(['token'])
            ->assertJsonMissingPath('twoFactorRequired');

        $this->travel(31)->days();

        $this->postJson('/api/admin/login', ['email' => $admin->email, 'password' => 'pw', 'deviceToken' => $device])
            ->assertJsonPath('twoFactorRequired', true);
    }

    public function test_a_device_token_does_not_carry_over_to_another_account(): void
    {
        $a = User::factory()->withoutTwoFactor()->create(['password' => Hash::make('pw')]);
        $b = User::factory()->withoutTwoFactor()->create(['password' => Hash::make('pw')]);
        [$secretA] = $this->enrol('admin', $a->createToken('t')->plainTextToken);
        $this->enrol('admin', $b->createToken('t')->plainTextToken);

        $challenge = $this->postJson('/api/admin/login', ['email' => $a->email, 'password' => 'pw'])->json('challenge');
        $device = $this->postJson('/api/admin/login/two-factor', [
            'challenge' => $challenge, 'code' => $this->codeFor($secretA, 1), 'remember' => true,
        ])->json('deviceToken');

        $this->postJson('/api/admin/login', ['email' => $b->email, 'password' => 'pw', 'deviceToken' => $device])
            ->assertJsonPath('twoFactorRequired', true);
    }

    public function test_admins_cannot_switch_it_off_or_restart_setup(): void
    {
        $admin = User::factory()->create();
        $token = $admin->createToken('t')->plainTextToken;

        $this->withToken($token)->deleteJson('/api/admin/two-factor', ['password' => 'password', 'code' => '123456'])
            ->assertStatus(405);
        $this->withToken($token)->postJson('/api/admin/two-factor/setup')->assertStatus(409);
        $this->assertTrue($admin->fresh()->hasTwoFactorEnabled());
    }

    // ---- Investors: optional ---------------------------------------------

    public function test_an_investor_without_two_factor_signs_in_as_before(): void
    {
        $this->investor();

        $this->postJson('/api/investor/login', ['email' => 'sample@example.com', 'password' => 'password123'])
            ->assertOk()
            ->assertJsonStructure(['token', 'investor']);
    }

    public function test_an_investor_can_turn_it_on_and_is_then_asked_for_a_code(): void
    {
        $investor = $this->investor();
        [$secret] = $this->enrol('investor', $investor->createToken('t', ['investor'])->plainTextToken);

        $challenge = $this->postJson('/api/investor/login', ['email' => 'sample@example.com', 'password' => 'password123'])
            ->assertJsonPath('twoFactorRequired', true)
            ->json('challenge');

        $this->postJson('/api/investor/login/two-factor', ['challenge' => $challenge, 'code' => $this->codeFor($secret, 1)])
            ->assertOk()
            ->assertJsonStructure(['token', 'investor']);
    }

    public function test_an_investor_challenge_cannot_be_redeemed_on_the_admin_side(): void
    {
        $investor = $this->investor();
        [$secret] = $this->enrol('investor', $investor->createToken('t', ['investor'])->plainTextToken);

        $challenge = $this->postJson('/api/investor/login', ['email' => 'sample@example.com', 'password' => 'password123'])
            ->json('challenge');

        $this->postJson('/api/admin/login/two-factor', ['challenge' => $challenge, 'code' => $this->codeFor($secret, 1)])
            ->assertStatus(422)
            ->assertJsonPath('code', 'challenge_expired');
    }

    public function test_a_stale_challenge_is_refused(): void
    {
        $investor = $this->investor();
        [$secret] = $this->enrol('investor', $investor->createToken('t', ['investor'])->plainTextToken);

        $challenge = $this->postJson('/api/investor/login', ['email' => 'sample@example.com', 'password' => 'password123'])
            ->json('challenge');

        $this->travel(6)->minutes();

        $this->postJson('/api/investor/login/two-factor', ['challenge' => $challenge, 'code' => $this->codeFor($secret)])
            ->assertStatus(422)
            ->assertJsonPath('code', 'challenge_expired');
    }

    public function test_guessing_is_cut_off_after_five_wrong_codes(): void
    {
        $investor = $this->investor();
        [$secret] = $this->enrol('investor', $investor->createToken('t', ['investor'])->plainTextToken);

        $challenge = $this->postJson('/api/investor/login', ['email' => 'sample@example.com', 'password' => 'password123'])
            ->json('challenge');

        foreach (range(1, 5) as $_) {
            $this->postJson('/api/investor/login/two-factor', ['challenge' => $challenge, 'code' => '000000'])->assertStatus(422);
        }

        $this->postJson('/api/investor/login/two-factor', ['challenge' => $challenge, 'code' => $this->codeFor($secret, 1)])
            ->assertStatus(429);
    }

    public function test_an_investor_needs_password_and_code_to_switch_it_off(): void
    {
        $investor = $this->investor();
        $token = $investor->createToken('t', ['investor'])->plainTextToken;
        [$secret] = $this->enrol('investor', $token);

        $this->withToken($token)->deleteJson('/api/investor/two-factor', ['password' => 'wrong', 'code' => $this->codeFor($secret, 1)])
            ->assertStatus(422);
        $this->assertTrue($investor->fresh()->hasTwoFactorEnabled());

        $this->withToken($token)->deleteJson('/api/investor/two-factor', ['password' => 'password123', 'code' => $this->codeFor($secret, 1)])
            ->assertOk()
            ->assertJsonPath('enabled', false);
        $this->assertFalse($investor->fresh()->hasTwoFactorEnabled());
    }

    public function test_an_admin_can_reset_an_investor_who_lost_their_phone(): void
    {
        $investor = $this->investor();
        $this->enrol('investor', $investor->createToken('t', ['investor'])->plainTextToken);

        $this->app['auth']->forgetGuards();

        $this->withToken(User::factory()->create()->createToken('a')->plainTextToken)
            ->deleteJson('/api/admin/investors/inv-8001/two-factor')
            ->assertOk()
            ->assertJsonPath('data.twoFactorEnabled', false);

        $this->assertFalse($investor->fresh()->hasTwoFactorEnabled());
        $this->assertSame('Two-factor sign-in reset', $investor->fresh()->activities()->latest('occurred_at')->first()->title);
    }

    public function test_the_secret_is_stored_encrypted(): void
    {
        $investor = $this->investor();
        $this->enrol('investor', $investor->createToken('t', ['investor'])->plainTextToken);

        $raw = \DB::table('investors')->where('id', $investor->id)->value('two_factor_secret');

        $this->assertNotSame($investor->fresh()->two_factor_secret, $raw);
    }
}
