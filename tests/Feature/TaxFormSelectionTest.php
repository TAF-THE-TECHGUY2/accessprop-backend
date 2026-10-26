<?php

namespace Tests\Feature;

use App\Models\Fund;
use App\Models\Investor;
use App\Models\SigningEnvelope;
use App\Models\User;
use App\Services\DocuSign\EnvelopeBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * W-9 for US persons, W-8BEN for everyone else — decided by the investor's own
 * answer at signup, not their address.
 */
class TaxFormSelectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Fund::create(['code' => 'aref-i', 'name' => 'Access Real Estate Fund I', 'status' => 'active']);
        config([
            'docusign.templates.subscription_agreement.us_person' => 'tpl-w9',
            'docusign.templates.subscription_agreement.non_us_person' => 'tpl-w8',
            'docusign.templates.subscription_agreement.accredited' => 'tpl-legacy',
        ]);
    }

    private function register(string $email, string $country, ?bool $usPerson)
    {
        return $this->postJson('/api/investors/register', array_filter([
            'firstName' => 'Ada',
            'lastName' => 'Lovelace',
            'email' => $email,
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'addressLine1' => '1 Beacon St',
            'city' => 'Boston',
            'stateProvince' => 'MA',
            'zipPostalCode' => '02108',
            'country' => $country,
            'experience' => 'experienced',
            'accreditationStatus' => 'accredited',
            'investmentAmount' => 25000,
            'usPerson' => $usPerson,
        ], fn ($v) => $v !== null));
    }

    private function template(string $email): string
    {
        return app(EnvelopeBuilder::class)->resolveTemplateId(Investor::where('email', $email)->firstOrFail());
    }

    public function test_a_us_citizen_living_abroad_gets_the_w9_package(): void
    {
        $this->register('abroad@example.com', 'Greece', true)->assertSuccessful();

        $this->assertSame('tpl-w9', $this->template('abroad@example.com'));
    }

    public function test_a_foreign_national_living_in_the_us_gets_the_w8_package(): void
    {
        $this->register('visitor@example.com', 'United States', false)->assertSuccessful();

        $this->assertSame('tpl-w8', $this->template('visitor@example.com'));
    }

    public function test_without_an_answer_the_country_decides(): void
    {
        $this->register('legacy@example.com', 'United Kingdom', null)->assertSuccessful();

        $this->assertSame('tpl-w8', $this->template('legacy@example.com'));
    }

    public function test_until_the_new_templates_are_set_the_old_one_is_used(): void
    {
        config([
            'docusign.templates.subscription_agreement.us_person' => null,
            'docusign.templates.subscription_agreement.non_us_person' => null,
        ]);
        $this->register('early@example.com', 'United States', true)->assertSuccessful();

        $this->assertSame('tpl-legacy', $this->template('early@example.com'));
    }

    public function test_an_admin_can_correct_the_tax_status(): void
    {
        $this->register('fix@example.com', 'Greece', false)->assertSuccessful();
        $code = Investor::where('email', 'fix@example.com')->value('code');

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs(User::factory()->create());

        $this->patchJson("/api/admin/investors/{$code}/tax-status", ['usPerson' => true])
            ->assertOk()
            ->assertJsonPath('data.taxForm', 'W-9');

        $this->assertSame('tpl-w9', $this->template('fix@example.com'));
    }

    public function test_with_only_one_new_template_set_everyone_stays_on_the_old_one(): void
    {
        config(['docusign.templates.subscription_agreement.non_us_person' => null]);
        $this->register('half@example.com', 'United States', true)->assertSuccessful();

        $this->assertSame('tpl-legacy', $this->template('half@example.com'));
    }

    public function test_an_admin_is_warned_when_a_sent_agreement_has_the_other_tax_form(): void
    {
        $this->register('sent@example.com', 'Greece', false)->assertSuccessful();
        $investor = Investor::where('email', 'sent@example.com')->firstOrFail();

        SigningEnvelope::create([
            'investor_id' => $investor->id,
            'docusign_envelope_id' => 'env-sent',
            'template_id' => 'tpl-w8',
            'type' => 'subscription_agreement',
            'status' => SigningEnvelope::STATUS_SENT,
            'investor_email' => $investor->email,
            'investor_name' => $investor->name,
        ]);

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs(User::factory()->create());

        $this->patchJson("/api/admin/investors/{$investor->code}/tax-status", ['usPerson' => true])
            ->assertOk()
            ->assertJsonPath('data.taxForm', 'W-9')
            ->assertJson(['warning' => 'An agreement already sent to them uses the other tax form: void it and send a new one from the Agreements tab.']);

        // Back to the form the envelope was built with: nothing to warn about.
        $this->patchJson("/api/admin/investors/{$investor->code}/tax-status", ['usPerson' => false])
            ->assertOk()
            ->assertJsonPath('warning', null);
    }
}
