<?php

namespace Tests\Feature;

use App\Models\Investor;
use App\Models\SigningEnvelope;
use App\Models\User;
use App\Services\DocuSign\EnvelopeBuilder;
use App\Services\DocuSign\SigningNotYetOpen;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

/** Signing links from the admin and the investor portal, in place of the DocuSign email. */
class SigningLinksTest extends TestCase
{
    use RefreshDatabase;

    private function investor(string $code): Investor
    {
        return Investor::create([
            'code' => $code,
            'name' => 'Investor '.$code,
            'email' => $code.'@example.com',
            'password' => 'password123',
            'phone' => '+1 617 555 0100',
            'country' => 'United States',
            'joined_at' => now(),
            'accreditation_status' => 'accredited',
            'kyc_status' => 'approved',
            'investment_status' => 'awaiting_documents',
            'dashboard_status' => 'active',
            'address_line1' => '1 Beacon St',
            'address_city' => 'Boston',
            'address_state' => 'MA',
            'address_postal_code' => '02108',
            'address_country' => 'United States',
            'personal_investor_type' => 'Individual',
            'personal_residency' => Investor::US_PERSON,
            'investment_fund_name' => 'Access Real Estate Fund I',
            'investment_wallet_status' => 'unfunded',
            'investment_expected_yield' => '8%',
        ]);
    }

    private function envelope(Investor $investor, string $status = SigningEnvelope::STATUS_SENT): SigningEnvelope
    {
        return SigningEnvelope::create([
            'investor_id' => $investor->id,
            'docusign_envelope_id' => 'env-'.$investor->code,
            'template_id' => 'tpl-w9',
            'embedded_signing' => true,
            'type' => 'subscription_agreement',
            'status' => $status,
            'investor_email' => $investor->email,
            'investor_name' => $investor->name,
            'counter_signer_email' => 'dion@example.com',
            'counter_signer_name' => 'Dionysios Kaskarelis',
        ]);
    }

    private function builderReturning(callable $fn): void
    {
        $builder = Mockery::mock(EnvelopeBuilder::class);
        $builder->shouldReceive('signingUrl')->andReturnUsing($fn);
        $this->app->instance(EnvelopeBuilder::class, $builder);
    }

    public function test_an_admin_gets_a_link_for_either_signer(): void
    {
        $env = $this->envelope($this->investor('inv-8101'));
        $this->builderReturning(fn ($e, $role) => "https://docusign.test/sign/{$role}");
        Sanctum::actingAs(User::factory()->create());

        $this->postJson("/api/admin/agreements/{$env->id}/signing-link", ['role' => 'counter_signer'])
            ->assertOk()
            ->assertJsonPath('url', 'https://docusign.test/sign/counter_signer');
        $this->postJson("/api/admin/agreements/{$env->id}/signing-link", ['role' => 'investor'])
            ->assertJsonPath('url', 'https://docusign.test/sign/investor');
    }

    public function test_the_investor_s_link_waits_for_the_countersignature(): void
    {
        $investor = $this->investor('inv-8102');
        $env = $this->envelope($investor);
        $this->builderReturning(fn () => throw new SigningNotYetOpen('Dionysios Kaskarelis signs first.'));
        Sanctum::actingAs($investor);

        $this->getJson('/api/investor/portal/agreement')
            ->assertOk()
            ->assertJsonPath('data.id', $env->id)
            ->assertJsonPath('data.canSignInPortal', true);

        $this->postJson("/api/investor/portal/agreements/{$env->id}/signing-link")
            ->assertStatus(409)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'with the fund for its signature first'));
    }

    public function test_an_investor_cannot_get_a_link_for_someone_else(): void
    {
        $mine = $this->investor('inv-8103');
        $theirs = $this->envelope($this->investor('inv-8104'));
        $this->builderReturning(fn () => 'https://docusign.test/sign');
        Sanctum::actingAs($mine);

        $this->postJson("/api/investor/portal/agreements/{$theirs->id}/signing-link")->assertNotFound();
    }

    public function test_no_link_for_a_finished_agreement(): void
    {
        $investor = $this->investor('inv-8105');
        $env = $this->envelope($investor, SigningEnvelope::STATUS_COMPLETED);
        $this->builderReturning(fn () => 'https://docusign.test/sign');

        Sanctum::actingAs(User::factory()->create());
        $this->postJson("/api/admin/agreements/{$env->id}/signing-link", ['role' => 'investor'])->assertStatus(409);

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($investor);
        $this->getJson('/api/investor/portal/agreement')->assertJsonPath('data', null);
    }

    public function test_the_signers_are_flagged_for_app_signing_in_the_request(): void
    {
        $definition = app(EnvelopeBuilder::class)->buildEnvelopeDefinition(
            templateId: 'tpl-w9',
            investor: $this->investor('inv-8106'),
            counterSignerEmail: 'dion@example.com',
            counterSignerName: 'Dion',
            package: true,
        );

        [$investorRole, $counterRole] = $definition->getTemplateRoles();
        $this->assertSame('investor-inv-8106', $investorRole->getClientUserId());
        $this->assertSame('counter-signer', $counterRole->getClientUserId());
        // DocuSign emails neither of them; the app does.
        $this->assertNull($investorRole->getEmbeddedRecipientStartUrl());
        $this->assertNull($counterRole->getEmbeddedRecipientStartUrl());
    }
}
