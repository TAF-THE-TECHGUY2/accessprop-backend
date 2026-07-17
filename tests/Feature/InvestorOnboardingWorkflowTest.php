<?php

namespace Tests\Feature;

use App\Mail\InvestorWelcomeMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class InvestorOnboardingWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_three_page_onboarding_payload_creates_a_pending_investor_session(): void
    {
        Mail::fake();

        $response = $this->postJson('/api/investors/register', [
            'firstName' => 'Ada',
            'lastName' => 'Lovelace',
            'email' => 'ada.onboarding@example.com',
            'password' => 'Secure123!',
            'password_confirmation' => 'Secure123!',
            'mobilePhone' => null,
            'addressLine1' => '123 Main Street',
            'addressLine2' => null,
            'city' => 'Johannesburg',
            'stateProvince' => 'Gauteng',
            'zipPostalCode' => '2000',
            'country' => 'United States',
            'experience' => 'experienced',
            'investmentAmount' => 10000,
            'accreditationStatus' => 'accredited',
            'receiveUpdates' => false,
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('name', 'Ada Lovelace')
            ->assertJsonPath('kycStatus', 'pending')
            ->assertJsonPath('accreditationStatus', 'accredited')
            ->assertJsonStructure(['code', 'name', 'kycStatus', 'accreditationStatus', 'token']);

        $this->assertDatabaseHas('investors', [
            'email' => 'ada.onboarding@example.com',
            'investment_amount' => 10000,
            'kyc_status' => 'pending',
            'accreditation_verification_status' => 'not_started',
            'investment_status' => 'awaiting_kyc',
            'dashboard_status' => 'pending',
            'document_signing_status' => 'not_started',
        ]);

        Mail::assertSent(InvestorWelcomeMail::class);
    }
}
