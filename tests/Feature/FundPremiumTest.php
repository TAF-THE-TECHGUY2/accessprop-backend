<?php

namespace Tests\Feature;

use App\Models\Fund;
use App\Models\FundUnitPrice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FundPremiumTest extends TestCase
{
    use RefreshDatabase;

    private Fund $fund;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fund = Fund::create(['code' => 'aref-i', 'name' => 'Access Real Estate Fund I', 'status' => 'active']);
        FundUnitPrice::create(['fund_id' => $this->fund->id, 'as_of_date' => '2026-09-30', 'price' => '14.0945809850']);
        $this->admin = User::factory()->create(['name' => 'Tafara']);
    }

    public function test_an_admin_sets_the_premium_and_it_is_logged(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/admin/funds/aref-i/premium', ['premiumPct' => 2.5, 'reason' => 'Q4 pricing'])
            ->assertOk()
            ->assertJsonPath('premiumPct', 2.5)
            ->assertJsonPath('premiumChanges.0.oldPct', 0)
            ->assertJsonPath('premiumChanges.0.newPct', 2.5)
            ->assertJsonPath('premiumChanges.0.reason', 'Q4 pricing')
            ->assertJsonPath('premiumChanges.0.changedBy', 'Tafara');

        $this->assertSame('2.500', $this->fund->fresh()->current_premium_pct);

        $this->getJson('/api/admin/funds/aref-i')
            ->assertOk()
            ->assertJsonPath('fund.premiumPct', 2.5)
            ->assertJsonCount(1, 'premiumChanges');
    }

    public function test_new_agreements_are_priced_at_the_new_premium(): void
    {
        Sanctum::actingAs($this->admin);
        $this->postJson('/api/admin/funds/aref-i/premium', ['premiumPct' => 5])->assertOk();

        $this->getJson('/api/admin/funds/aref-i/price-preview?date=2026-10-15&amount=10000')
            ->assertOk()
            ->assertJsonPath('premiumPct', 5);
    }

    public function test_an_unchanged_or_out_of_range_premium_is_refused(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/admin/funds/aref-i/premium', ['premiumPct' => 0])->assertStatus(422);
        $this->postJson('/api/admin/funds/aref-i/premium', ['premiumPct' => 250])->assertStatus(422);
        $this->postJson('/api/admin/funds/aref-i/premium', ['premiumPct' => 1.23456])->assertStatus(422);
        $this->postJson('/api/admin/funds/aref-i/premium', [])->assertStatus(422);

        $this->assertDatabaseCount('fund_premium_changes', 0);
    }

    public function test_investors_cannot_set_the_premium(): void
    {
        $this->postJson('/api/admin/funds/aref-i/premium', ['premiumPct' => 3])->assertUnauthorized();
    }
}
