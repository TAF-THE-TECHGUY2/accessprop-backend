<?php

namespace Tests\Feature;

use App\Models\Investor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Marking an investor as a Managing Member.
 *
 * A label over accreditation, not a replacement for it: the investor stays
 * accredited for every pathway, document and audience decision.
 */
class AdminManagingMemberTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::factory()->create());
    }

    private function investor(string $code = 'inv-5001', string $accreditation = 'accredited'): Investor
    {
        return Investor::create([
            'code' => $code,
            'name' => 'Sample Investor',
            'email' => $code.'@example.com',
            'password' => 'password123',
            'phone' => '+1 617 555 0100',
            'country' => 'United States',
            'joined_at' => now(),
            'accreditation_status' => $accreditation,
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

    public function test_an_accredited_investor_can_be_marked_and_stays_accredited(): void
    {
        $investor = $this->investor();

        $this->patchJson('/api/admin/investors/inv-5001/managing-member', [
            'isManagingMember' => true,
        ])
            ->assertOk()
            ->assertJsonPath('data.isManagingMember', true)
            ->assertJsonPath('data.accreditationStatus', 'accredited')
            ->assertJsonPath('data.pathway', 'Accredited');

        $this->assertTrue($investor->fresh()->is_managing_member);
        $this->assertSame(
            'Marked as Managing Member',
            $investor->fresh()->activities()->latest('occurred_at')->first()->title,
        );
    }

    public function test_a_non_accredited_investor_cannot_be_marked(): void
    {
        $investor = $this->investor('inv-5002', 'non_accredited');

        $this->patchJson('/api/admin/investors/inv-5002/managing-member', [
            'isManagingMember' => true,
        ])->assertStatus(422)->assertJsonValidationErrors('isManagingMember');

        $this->assertFalse($investor->fresh()->is_managing_member);
    }

    public function test_the_designation_can_be_removed(): void
    {
        $investor = $this->investor();
        $investor->update(['is_managing_member' => true]);

        $this->patchJson('/api/admin/investors/inv-5001/managing-member', [
            'isManagingMember' => false,
        ])->assertOk()->assertJsonPath('data.isManagingMember', false);

        $this->assertFalse($investor->fresh()->is_managing_member);
    }

    public function test_downgrading_accreditation_clears_the_designation(): void
    {
        $investor = $this->investor();
        $investor->update(['is_managing_member' => true]);

        $this->patchJson('/api/admin/investors/inv-5001/statuses', [
            'accreditationStatus' => 'non_accredited',
        ])->assertOk();

        $this->assertFalse($investor->fresh()->is_managing_member);
    }

    public function test_the_filter_narrows_to_managing_members_and_accredited_still_includes_them(): void
    {
        $this->investor('inv-5001')->update(['is_managing_member' => true]);
        $this->investor('inv-5003');

        $this->getJson('/api/admin/investors?accreditationStatus=managing_member')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', 'inv-5001');

        $this->getJson('/api/admin/investors?accreditationStatus=accredited')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_the_investor_portal_profile_carries_the_flag(): void
    {
        $investor = $this->investor();
        $investor->update(['is_managing_member' => true]);
        Sanctum::actingAs($investor);

        $this->getJson('/api/investor/portal/profile')
            ->assertOk()
            ->assertJsonPath('status.accreditation', 'accredited')
            ->assertJsonPath('status.isManagingMember', true)
            ->assertJsonPath('readonly.accreditationStatus', 'accredited')
            ->assertJsonPath('readonly.isManagingMember', true);
    }
}
