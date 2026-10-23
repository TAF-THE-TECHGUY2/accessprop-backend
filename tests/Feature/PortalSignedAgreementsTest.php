<?php

namespace Tests\Feature;

use App\Models\Investor;
use App\Models\SigningEnvelope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * An investor's signed agreements, on their Documents page. They were archived
 * after signing but never shown to the investor.
 */
class PortalSignedAgreementsTest extends TestCase
{
    use RefreshDatabase;

    private function investor(string $code): Investor
    {
        return Investor::create([
            'code' => $code,
            'name' => 'Sample Investor',
            'email' => $code.'@example.com',
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

    private function envelope(Investor $investor, string $status, ?string $path = null): SigningEnvelope
    {
        return SigningEnvelope::create([
            'investor_id' => $investor->id,
            'docusign_envelope_id' => 'env-'.uniqid(),
            'template_id' => 'tpl',
            'type' => 'subscription_agreement',
            'status' => $status,
            'investor_email' => $investor->email,
            'investor_name' => $investor->name,
            'signed_at' => now(),
            'completed_at' => $status === SigningEnvelope::STATUS_COMPLETED ? now() : null,
            'signed_document_disk' => $path ? 'local' : null,
            'signed_document_path' => $path,
        ]);
    }

    public function test_only_their_completed_agreements_are_listed(): void
    {
        $mine = $this->investor('inv-6001');
        $other = $this->investor('inv-6002');

        $done = $this->envelope($mine, SigningEnvelope::STATUS_COMPLETED);
        $this->envelope($mine, SigningEnvelope::STATUS_SENT);
        $this->envelope($other, SigningEnvelope::STATUS_COMPLETED);

        Sanctum::actingAs($mine);

        $this->getJson('/api/investor/portal/documents')
            ->assertOk()
            ->assertJsonCount(1, 'signed')
            ->assertJsonPath('signed.0.id', $done->id)
            ->assertJsonPath('signed.0.title', 'Subscription Agreement');
    }

    public function test_they_can_download_their_signed_copy(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('signed-agreements/inv-6001/env.pdf', '%PDF-signed');

        $mine = $this->investor('inv-6001');
        $done = $this->envelope($mine, SigningEnvelope::STATUS_COMPLETED, 'signed-agreements/inv-6001/env.pdf');

        Sanctum::actingAs($mine);

        $response = $this->get('/api/investor/portal/agreements/'.$done->id.'/download')->assertOk();
        $this->assertSame('%PDF-signed', $response->getContent());
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
    }

    public function test_another_investors_agreement_is_not_found(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('signed-agreements/inv-6002/env.pdf', '%PDF-theirs');

        $mine = $this->investor('inv-6001');
        $theirs = $this->envelope($this->investor('inv-6002'), SigningEnvelope::STATUS_COMPLETED, 'signed-agreements/inv-6002/env.pdf');

        Sanctum::actingAs($mine);

        $this->getJson('/api/investor/portal/agreements/'.$theirs->id.'/download')->assertNotFound();
    }

    public function test_an_unfinished_agreement_cannot_be_downloaded(): void
    {
        $mine = $this->investor('inv-6001');
        $sent = $this->envelope($mine, SigningEnvelope::STATUS_SENT);

        Sanctum::actingAs($mine);

        $this->getJson('/api/investor/portal/agreements/'.$sent->id.'/download')->assertNotFound();
    }
}
