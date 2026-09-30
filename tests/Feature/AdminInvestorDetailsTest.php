<?php

namespace Tests\Feature;

use App\Models\Investor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Correcting an investor's phone number after signup.
 *
 * There was no route for this at all: the create form takes a number and
 * nothing afterwards could change it.
 */
class AdminInvestorDetailsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::factory()->create());
    }

    private function investor(?string $phone = '+1 617 555 0100'): Investor
    {
        return Investor::create([
            'code' => 'inv-4001',
            'name' => 'Sample Investor',
            'email' => 'sample@example.com',
            'password' => 'password123',
            'phone' => $phone,
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

    public function test_an_admin_can_correct_a_phone_number(): void
    {
        $investor = $this->investor();

        $this->patchJson('/api/admin/investors/inv-4001/details', [
            'phone' => '+1 617 555 0199',
        ])->assertOk()->assertJsonPath('data.phone', '+1 617 555 0199');

        $this->assertSame('+1 617 555 0199', $investor->fresh()->phone);
    }

    public function test_the_change_is_recorded_on_the_investor(): void
    {
        $investor = $this->investor();

        $this->patchJson('/api/admin/investors/inv-4001/details', [
            'phone' => '+44 20 7946 0000',
        ])->assertOk();

        $activity = $investor->fresh()->activities()->latest('occurred_at')->first();

        $this->assertSame('Contact details updated', $activity->title);
        $this->assertStringContainsString('+1 617 555 0100', $activity->description);
        $this->assertStringContainsString('+44 20 7946 0000', $activity->description);
    }

    public function test_clearing_the_number_stores_null_not_an_empty_string(): void
    {
        $investor = $this->investor();

        $this->patchJson('/api/admin/investors/inv-4001/details', ['phone' => '  '])
            ->assertOk();

        $this->assertNull($investor->fresh()->phone);
    }

    public function test_an_unchanged_number_logs_nothing(): void
    {
        $investor = $this->investor();

        $this->patchJson('/api/admin/investors/inv-4001/details', [
            'phone' => '+1 617 555 0100',
        ])->assertOk();

        $this->assertSame(0, $investor->fresh()->activities()->count());
    }

    public function test_it_will_not_quietly_change_anything_else(): void
    {
        $investor = $this->investor();

        // Email is the login and where every notice goes; name is identity.
        // Neither should move because a phone number was corrected.
        $this->patchJson('/api/admin/investors/inv-4001/details', [
            'phone' => '+1 617 555 0111',
            'email' => 'attacker@example.com',
            'name' => 'Someone Else',
            'accreditationStatus' => 'non_accredited',
        ])->assertOk();

        $fresh = $investor->fresh();

        $this->assertSame('sample@example.com', $fresh->email);
        $this->assertSame('Sample Investor', $fresh->name);
        $this->assertSame('accredited', $fresh->accreditation_status);
    }

    public function test_it_requires_an_authenticated_admin(): void
    {
        $this->investor();
        app('auth')->forgetGuards();

        $this->patchJson('/api/admin/investors/inv-4001/details', ['phone' => '+1 000'])
            ->assertUnauthorized();
    }
}
