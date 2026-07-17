<?php

namespace Tests\Feature;

use App\Models\Fund;
use App\Models\FundHolding;
use App\Models\Investor;
use App\Models\PortalDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminPortalDocumentTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_upload_and_delete_a_fund_offering_document(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create();
        $fund = Fund::create(['code' => 'AP-FUND-I', 'name' => 'Access Property Fund I']);

        Sanctum::actingAs($admin);

        $response = $this->post('/api/admin/funds/AP-FUND-I/documents', [
            'title' => 'Private Placement Memorandum',
            'category' => 'legal',
            'subcategory' => 'Offering memorandum',
            'documentDatedAt' => '2026-07-17',
            'file' => UploadedFile::fake()->create('ppm.pdf', 250, 'application/pdf'),
        ], ['Accept' => 'application/json']);

        $response
            ->assertCreated()
            ->assertJsonPath('data.title', 'Private Placement Memorandum')
            ->assertJsonPath('data.category', 'legal');

        $document = PortalDocument::firstOrFail();
        $this->assertSame($fund->id, $document->fund_id);
        $this->assertSame('fund', $document->scope);
        Storage::disk('local')->assertExists($document->file_url);

        $this->getJson('/api/admin/funds/AP-FUND-I')
            ->assertOk()
            ->assertJsonPath('documents.0.id', $document->id);

        $this->deleteJson("/api/admin/funds/AP-FUND-I/documents/{$document->id}")
            ->assertOk();

        $this->assertDatabaseMissing('portal_documents', ['id' => $document->id]);
        Storage::disk('local')->assertMissing($document->file_url);
    }

    public function test_investor_in_the_fund_can_list_and_download_uploaded_document(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create();
        $fund = Fund::create(['code' => 'AP-FUND-I', 'name' => 'Access Property Fund I']);

        Sanctum::actingAs($admin);
        $this->post('/api/admin/funds/AP-FUND-I/documents', [
            'title' => 'Fund Overview',
            'category' => 'operational',
            'file' => UploadedFile::fake()->create('overview.pdf', 100, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertCreated();

        $investor = $this->makeInvestor();
        FundHolding::create([
            'investor_id' => $investor->id,
            'fund_id' => $fund->id,
            'units' => 10,
            'amount_invested' => 1000,
            'average_unit_price' => 100,
        ]);
        $document = PortalDocument::firstOrFail();

        Sanctum::actingAs($investor);

        $this->getJson('/api/investor/portal/documents')
            ->assertOk()
            ->assertJsonPath('data.operational.0.id', $document->id)
            ->assertJsonPath('data.operational.0.title', 'Fund Overview');

        $this->get("/api/investor/portal/documents/{$document->id}/download")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_pre_commitment_investor_can_access_selected_offering_documents_without_a_holding(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create();
        Fund::create([
            'code' => 'ARE I',
            'name' => 'Access Properties Diversified Income Fund I',
        ]);

        Sanctum::actingAs($admin);
        $this->post('/api/admin/funds/ARE%20I/documents', [
            'title' => 'Pre-commitment Offering Memorandum',
            'category' => 'legal',
            'file' => UploadedFile::fake()->create('offering.pdf', 100, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertCreated();

        $investor = $this->makeInvestor();
        $document = PortalDocument::firstOrFail();

        $this->assertDatabaseCount('fund_holdings', 0);

        Sanctum::actingAs($investor);

        $this->getJson('/api/investor/portal/documents')
            ->assertOk()
            ->assertJsonPath('data.legal.0.id', $document->id)
            ->assertJsonPath('data.legal.0.title', 'Pre-commitment Offering Memorandum');

        $this->get("/api/investor/portal/documents/{$document->id}/download")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_upload_rejects_non_pdf_files(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create();
        Fund::create(['code' => 'AP-FUND-I', 'name' => 'Access Property Fund I']);
        Sanctum::actingAs($admin);

        $this->post('/api/admin/funds/AP-FUND-I/documents', [
            'title' => 'Not a PDF',
            'category' => 'legal',
            'file' => UploadedFile::fake()->create('notes.txt', 10, 'text/plain'),
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');

        $this->assertDatabaseCount('portal_documents', 0);
    }

    public function test_unauthenticated_browser_download_returns_401_instead_of_server_error(): void
    {
        $this->get('/api/investor/portal/documents/1/download')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Unauthenticated.');
    }

    private function makeInvestor(): Investor
    {
        return Investor::create([
            'code' => 'inv-7001',
            'name' => 'Offering Investor',
            'email' => 'offering@example.com',
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
            'investment_fund_name' => 'Access Property Fund I',
            'investment_wallet_status' => 'Active',
            'investment_expected_yield' => '8%',
        ]);
    }
}
