<?php

namespace Tests\Feature;

use App\Models\Investor;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The cookie that lets ap.boston know an investor is signed in here.
 * See config/member.php.
 */
class MemberSessionCookieTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-member-secret-value';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'member.secret' => self::SECRET,
            'member.domain' => '.ap.boston',
            'member.cookie' => 'ap_member',
            'member.ttl' => 120,
            // The test client speaks plain http.
            'member.secure' => false,
        ]);
    }

    private function makeInvestor(array $overrides = []): Investor
    {
        return Investor::create(array_merge([
            'code' => 'inv-7001',
            'name' => 'Dana Investor',
            'email' => 'dana@example.com',
            'password' => 'correct-horse-battery',
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

    public function test_login_sets_a_readable_signed_cookie_on_the_parent_domain(): void
    {
        $investor = $this->makeInvestor();

        $response = $this->postJson('/api/investor/login', [
            'email' => $investor->email,
            'password' => 'correct-horse-battery',
        ])->assertOk();

        $cookie = collect($response->headers->getCookies())
            ->firstWhere(fn ($c) => $c->getName() === 'ap_member');

        $this->assertNotNull($cookie, 'Login did not set the ap_member cookie.');
        $this->assertSame('.ap.boston', $cookie->getDomain());
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', $cookie->getSameSite());

        // The CMS is a Node service and has to be able to verify this itself,
        // so the value must be a plain HS256 JWT, not a Laravel-encrypted blob.
        $claims = JWT::decode($cookie->getValue(), new Key(self::SECRET, 'HS256'));

        $this->assertSame($investor->code, $claims->sub);
        $this->assertSame($investor->email, $claims->email);
        $this->assertGreaterThan(now()->timestamp, $claims->exp);
    }

    public function test_logout_clears_the_cookie(): void
    {
        $investor = $this->makeInvestor();

        // logout() deletes the caller's access token, so it needs a real one --
        // actingAs() would hand it a transient token with nothing to delete.
        $token = $this->postJson('/api/investor/login', [
            'email' => $investor->email,
            'password' => 'correct-horse-battery',
        ])->assertOk()->json('token');

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/investor/logout')
            ->assertOk();

        $cookie = collect($response->headers->getCookies())
            ->firstWhere(fn ($c) => $c->getName() === 'ap_member');

        $this->assertNotNull($cookie, 'Logout did not clear the ap_member cookie.');
        $this->assertSame('', (string) $cookie->getValue());
    }

    public function test_refresh_reissues_the_cookie_for_an_authenticated_investor(): void
    {
        $investor = $this->makeInvestor();

        $response = $this->actingAs($investor, 'sanctum')
            ->postJson('/api/investor/session/refresh')
            ->assertOk()
            ->assertJson(['refreshed' => true]);

        $cookie = collect($response->headers->getCookies())
            ->firstWhere(fn ($c) => $c->getName() === 'ap_member');

        $this->assertNotNull($cookie);
        $claims = JWT::decode($cookie->getValue(), new Key(self::SECRET, 'HS256'));
        $this->assertSame($investor->code, $claims->sub);
    }

    public function test_refresh_requires_authentication(): void
    {
        $this->postJson('/api/investor/session/refresh')->assertStatus(401);
    }

    public function test_no_cookie_is_issued_when_the_feature_is_unconfigured(): void
    {
        // The default for local development and anywhere the two apps do not
        // share a parent domain.
        config(['member.secret' => null, 'member.domain' => null]);

        $investor = $this->makeInvestor();

        $response = $this->postJson('/api/investor/login', [
            'email' => $investor->email,
            'password' => 'correct-horse-battery',
        ])->assertOk();

        $this->assertNull(
            collect($response->headers->getCookies())
                ->firstWhere(fn ($c) => $c->getName() === 'ap_member')
        );
    }
}
