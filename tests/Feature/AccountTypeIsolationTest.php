<?php

namespace Tests\Feature;

use App\Models\Investor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * An investor's token must not open the admin API, nor an admin's the portal.
 * Both are Sanctum tokens, and `auth:sanctum` alone accepted either.
 */
class AccountTypeIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function investor(): Investor
    {
        return Investor::create([
            'code' => 'inv-7001',
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
        ]);
    }

    public function test_an_investor_token_cannot_reach_the_admin_api(): void
    {
        $token = $this->investor()->createToken('investor-dashboard', ['investor'])->plainTextToken;

        $this->withToken($token)->getJson('/api/admin/investors')->assertForbidden();
        $this->withToken($token)->deleteJson('/api/admin/investors/inv-7001')->assertForbidden();

        $this->assertDatabaseHas('investors', ['code' => 'inv-7001']);
    }

    public function test_an_admin_token_cannot_reach_the_investor_portal(): void
    {
        $token = User::factory()->create()->createToken('admin-spa')->plainTextToken;

        $this->withToken($token)->getJson('/api/investor/portal/profile')->assertForbidden();
    }

    public function test_each_token_still_works_where_it_belongs(): void
    {
        $investorToken = $this->investor()->createToken('investor-dashboard', ['investor'])->plainTextToken;
        $this->withToken($investorToken)->getJson('/api/investor/portal/profile')->assertOk();

        $this->app['auth']->forgetGuards();

        $adminToken = User::factory()->create()->createToken('admin-spa')->plainTextToken;
        $this->withToken($adminToken)->getJson('/api/admin/investors')->assertOk();
    }
}
