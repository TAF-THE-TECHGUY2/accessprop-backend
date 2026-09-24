<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LegalLinkSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_public_endpoint_serves_the_configured_links(): void
    {
        Setting::singleton()->update([
            'terms_of_use_url' => 'https://www.ap.boston/legal/terms',
            'privacy_policy_url' => 'https://www.ap.boston/legal/privacy',
        ]);

        // No authentication: the create-account page has no account yet.
        $this->getJson('/api/legal-links')
            ->assertOk()
            ->assertJson([
                'termsOfUseUrl' => 'https://www.ap.boston/legal/terms',
                'privacyPolicyUrl' => 'https://www.ap.boston/legal/privacy',
            ]);
    }

    public function test_the_public_endpoint_exposes_nothing_but_the_links(): void
    {
        Setting::singleton()->update(['support_email' => 'ops@internal.test']);

        $body = $this->getJson('/api/legal-links')->assertOk()->json();

        $this->assertSame(['termsOfUseUrl', 'privacyPolicyUrl'], array_keys($body));
        $this->assertStringNotContainsString('ops@internal.test', json_encode($body));
    }

    public function test_a_fresh_install_serves_working_links_before_anyone_edits_them(): void
    {
        $this->getJson('/api/legal-links')
            ->assertOk()
            ->assertJson([
                'termsOfUseUrl' => Setting::DEFAULT_TERMS_OF_USE_URL,
                'privacyPolicyUrl' => Setting::DEFAULT_PRIVACY_POLICY_URL,
            ]);
    }

    public function test_a_blank_stored_link_falls_back_to_the_bundled_default(): void
    {
        Setting::singleton()->update(['terms_of_use_url' => '']);

        $this->getJson('/api/legal-links')
            ->assertOk()
            ->assertJson(['termsOfUseUrl' => Setting::DEFAULT_TERMS_OF_USE_URL]);
    }

    public function test_an_admin_can_change_the_links(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->putJson('/api/admin/settings', [
            'termsOfUseUrl' => 'https://www.ap.boston/terms-v2',
            'privacyPolicyUrl' => 'https://www.ap.boston/privacy-v2',
        ])
            ->assertOk()
            ->assertJson(['termsOfUseUrl' => 'https://www.ap.boston/terms-v2']);

        // The change has to reach the page that renders the links, not just the
        // admin's own view of settings.
        $this->getJson('/api/legal-links')
            ->assertOk()
            ->assertJson([
                'termsOfUseUrl' => 'https://www.ap.boston/terms-v2',
                'privacyPolicyUrl' => 'https://www.ap.boston/privacy-v2',
            ]);
    }

    public function test_a_link_that_is_not_a_url_is_rejected(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->putJson('/api/admin/settings', ['termsOfUseUrl' => 'not a url'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('termsOfUseUrl');

        $this->assertSame(
            Setting::DEFAULT_TERMS_OF_USE_URL,
            Setting::singleton()->fresh()->terms_of_use_url,
        );
    }

    public function test_the_links_are_not_readable_by_editing_settings_anonymously(): void
    {
        $this->putJson('/api/admin/settings', [
            'termsOfUseUrl' => 'https://evil.example.com/terms',
        ])->assertUnauthorized();
    }
}
