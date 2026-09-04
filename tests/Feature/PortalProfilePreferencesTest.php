<?php

namespace Tests\Feature;

use App\Models\Fund;
use App\Models\FundHolding;
use App\Models\Investor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The newsletter preference and the fund's descriptive fields.
 *
 * The registration case is the one that matters most: onboarding asked for
 * `receiveUpdates`, validated it, and then discarded it because there was
 * nowhere to put it. Nothing failed and nothing complained — the answer simply
 * never arrived. A test is the only thing that notices that class of bug.
 */
class PortalProfilePreferencesTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_persists_the_investors_newsletter_choice(): void
    {
        Mail::fake();
        $this->makeOpenFund();

        $this->postJson('/api/investors/register', $this->registrationPayload([
            'receiveUpdates' => false,
        ]))->assertCreated();

        $this->assertFalse(
            Investor::where('email', 'ada.prefs@example.com')->value('newsletter_opted_in'),
            'declining updates at registration should be recorded, not dropped',
        );
    }

    public function test_registration_defaults_to_opted_in_when_not_answered(): void
    {
        Mail::fake();
        $this->makeOpenFund();

        $payload = $this->registrationPayload();
        unset($payload['receiveUpdates']);

        $this->postJson('/api/investors/register', $payload)->assertCreated();

        // Absent is not the same as declining. Everyone receives updates today,
        // so an unanswered question must not silently unsubscribe anyone.
        $this->assertTrue(
            Investor::where('email', 'ada.prefs@example.com')->value('newsletter_opted_in'),
        );
    }

    public function test_investor_can_toggle_the_newsletter_preference_from_the_portal(): void
    {
        $investor = $this->makeInvestor();
        Sanctum::actingAs($investor);

        $this->getJson('/api/investor/portal/profile')
            ->assertOk()
            ->assertJsonPath('editable.newsletterOptedIn', true);

        $this->patchJson('/api/investor/portal/profile', ['newsletterOptedIn' => false])
            ->assertOk()
            ->assertJsonPath('editable.newsletterOptedIn', false);

        $this->assertFalse($investor->fresh()->newsletter_opted_in);
    }

    public function test_toggling_the_newsletter_leaves_the_other_profile_fields_alone(): void
    {
        $investor = $this->makeInvestor();
        Sanctum::actingAs($investor);

        $this->patchJson('/api/investor/portal/profile', ['newsletterOptedIn' => false])
            ->assertOk()
            ->assertJsonPath('editable.city', 'Johannesburg')
            ->assertJsonPath('editable.name', 'Preferences Investor');
    }

    public function test_profile_reports_the_verification_states_the_header_shows(): void
    {
        $investor = $this->makeInvestor();
        Sanctum::actingAs($investor);

        $this->getJson('/api/investor/portal/profile')
            ->assertOk()
            ->assertJsonPath('status.accreditation', 'accredited')
            ->assertJsonPath('status.kyc', 'approved');
    }

    public function test_holdings_carry_the_funds_descriptive_fields(): void
    {
        $fund = Fund::create([
            'code' => 'AREF-I',
            'name' => 'Access Real Estate Fund I',
            'tagline' => 'Residential real estate fund',
            'investment_focus' => 'Residential Real Estate',
            'market' => 'Greater Boston',
        ]);

        $investor = $this->makeInvestor();
        FundHolding::create([
            'investor_id' => $investor->id,
            'fund_id' => $fund->id,
            'units' => 10,
            'amount_invested' => 1000,
            'average_unit_price' => 100,
        ]);

        Sanctum::actingAs($investor);

        $this->getJson('/api/investor/portal/holdings')
            ->assertOk()
            ->assertJsonPath('data.0.tagline', 'Residential real estate fund')
            ->assertJsonPath('data.0.investmentFocus', 'Residential Real Estate')
            ->assertJsonPath('data.0.market', 'Greater Boston');
    }

    public function test_descriptive_fields_are_null_rather_than_absent_when_unset(): void
    {
        $fund = Fund::create(['code' => 'AREF-II', 'name' => 'Access Real Estate Fund II']);
        $investor = $this->makeInvestor();
        FundHolding::create([
            'investor_id' => $investor->id,
            'fund_id' => $fund->id,
            'units' => 10,
            'amount_invested' => 1000,
            'average_unit_price' => 100,
        ]);

        Sanctum::actingAs($investor);

        // The portal omits an attribute row whose value is null. It has to see
        // the key to make that decision, so the keys are always present.
        $this->getJson('/api/investor/portal/holdings')
            ->assertOk()
            ->assertJsonPath('data.0.tagline', null)
            ->assertJsonPath('data.0.investmentFocus', null)
            ->assertJsonPath('data.0.market', null);
    }

    private function makeOpenFund(): Fund
    {
        return Fund::create([
            'code' => 'AREF-I',
            'name' => 'Access Real Estate Fund I',
            'status' => 'active',
        ]);
    }

    private function registrationPayload(array $overrides = []): array
    {
        return array_merge([
            'firstName' => 'Ada',
            'lastName' => 'Lovelace',
            'email' => 'ada.prefs@example.com',
            'password' => 'Secure123!',
            'password_confirmation' => 'Secure123!',
            'addressLine1' => '123 Main Street',
            'city' => 'Johannesburg',
            'stateProvince' => 'Gauteng',
            'zipPostalCode' => '2000',
            'country' => 'United States',
            'experience' => 'experienced',
            'investmentAmount' => 10000,
            'accreditationStatus' => 'accredited',
            'receiveUpdates' => true,
        ], $overrides);
    }

    /**
     * Returns the row as loaded from the database, not the instance create()
     * hands back. That instance carries only the attributes passed to it, so
     * column defaults such as newsletter_opted_in are absent from it — and
     * Sanctum::actingAs authenticates that same in-memory object, unlike
     * production where the user is always resolved from the token by a query.
     */
    private function makeInvestor(): Investor
    {
        $investor = Investor::create([
            'code' => 'inv-8001',
            'name' => 'Preferences Investor',
            'email' => 'prefs@example.com',
            'password' => 'secret-password',
            'country' => 'South Africa',
            'joined_at' => now(),
            'accreditation_status' => 'accredited',
            'kyc_status' => 'approved',
            'investment_status' => 'active',
            'dashboard_status' => 'active',
            'address_line1' => '1 Main Road',
            'address_city' => 'Johannesburg',
            'address_state' => 'Gauteng',
            'address_postal_code' => '2000',
            'address_country' => 'South Africa',
            'personal_investor_type' => 'Individual',
            'personal_residency' => 'South African Resident',
            // Still NOT NULL even though fund_id is now authoritative — the
            // denormalised copy has not been dropped yet.
            'investment_fund_name' => 'Access Real Estate Fund I',
            'investment_wallet_status' => 'Active',
            'investment_expected_yield' => '8%',
        ]);

        return $investor->refresh();
    }
}
