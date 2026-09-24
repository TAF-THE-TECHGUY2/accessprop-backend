<?php

namespace Tests\Feature;

use App\Models\LegalPage;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Terms of Use and Privacy Policy as pages this app owns.
 *
 * The behaviour that matters is the fallback: until someone has written and
 * published a page, the onboarding checkbox must keep pointing at the external
 * URL. A blank page under a heading saying "Terms of Use" is worse than a link
 * that leaves the site.
 */
class LegalPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_page_nobody_has_written_falls_back_to_the_external_link(): void
    {
        $this->getJson('/api/legal-links')
            ->assertOk()
            ->assertJsonPath('termsOfUseInternal', false)
            ->assertJsonPath('termsOfUseUrl', Setting::DEFAULT_TERMS_OF_USE_URL)
            ->assertJsonPath('privacyPolicyInternal', false);
    }

    public function test_a_published_page_takes_over_the_link(): void
    {
        $this->publish(LegalPage::SLUG_TERMS, '<h2>Terms</h2><p>The wording.</p>');

        $this->getJson('/api/legal-links')
            ->assertOk()
            ->assertJsonPath('termsOfUseInternal', true)
            ->assertJsonPath('termsOfUseUrl', '/legal/terms-of-use')
            // The other one is untouched and still external.
            ->assertJsonPath('privacyPolicyInternal', false);
    }

    public function test_a_draft_does_not_take_over_the_link(): void
    {
        // Written but not published: an investor must not be agreeing to a
        // paragraph somebody is still midway through.
        LegalPage::where('slug', LegalPage::SLUG_TERMS)
            ->update(['body_html' => '<p>Half a sentence</p>', 'published_at' => null]);

        $this->getJson('/api/legal-links')
            ->assertOk()
            ->assertJsonPath('termsOfUseInternal', false);

        $this->getJson('/api/legal/terms-of-use')->assertNotFound();
    }

    public function test_an_empty_page_cannot_be_published(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->patchJson('/api/admin/legal-pages/terms-of-use', ['published' => true])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Add some content before publishing this page.');

        $this->assertNull(LegalPage::where('slug', LegalPage::SLUG_TERMS)->value('published_at'));
    }

    public function test_anyone_can_read_a_published_page_without_signing_in(): void
    {
        $this->publish(LegalPage::SLUG_PRIVACY, '<p>How we handle your data.</p>');

        $this->getJson('/api/legal/privacy-policy')
            ->assertOk()
            ->assertJsonPath('title', 'Privacy Policy')
            ->assertJsonPath('bodyHtml', '<p>How we handle your data.</p>');
    }

    public function test_executable_markup_is_stripped_before_it_is_stored(): void
    {
        Sanctum::actingAs(User::factory()->create());

        // The portal renders this HTML. A stored script tag would run for every
        // investor who opened the page.
        $this->patchJson('/api/admin/legal-pages/terms-of-use', [
            'bodyHtml' => '<p>Real wording.</p><script>fetch("/steal")</script>'
                .'<p onclick="alert(1)">Clickable</p>'
                .'<a href="javascript:alert(1)">Link</a>',
        ])->assertOk();

        $stored = LegalPage::where('slug', LegalPage::SLUG_TERMS)->value('body_html');

        $this->assertStringContainsString('Real wording.', $stored);
        $this->assertStringNotContainsString('<script', $stored);
        $this->assertStringNotContainsString('onclick', $stored);
        $this->assertStringNotContainsString('javascript:', $stored);
    }

    public function test_only_the_two_known_slugs_are_editable(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->patchJson('/api/admin/legal-pages/anything-else', ['title' => 'Nope'])
            ->assertNotFound();
    }

    public function test_an_anonymous_caller_cannot_edit_a_page(): void
    {
        $this->patchJson('/api/admin/legal-pages/terms-of-use', ['title' => 'Rewritten'])
            ->assertUnauthorized();

        $this->assertSame(
            'Terms of Use',
            LegalPage::where('slug', LegalPage::SLUG_TERMS)->value('title'),
        );
    }

    public function test_unpublishing_returns_the_link_to_the_external_url(): void
    {
        $this->publish(LegalPage::SLUG_TERMS, '<p>Wording.</p>');
        Sanctum::actingAs(User::factory()->create());

        $this->patchJson('/api/admin/legal-pages/terms-of-use', ['published' => false])
            ->assertOk()
            ->assertJsonPath('live', false);

        $this->getJson('/api/legal-links')
            ->assertOk()
            ->assertJsonPath('termsOfUseInternal', false)
            ->assertJsonPath('termsOfUseUrl', Setting::DEFAULT_TERMS_OF_USE_URL);
    }

    private function publish(string $slug, string $html): void
    {
        LegalPage::where('slug', $slug)->update([
            'body_html' => $html,
            'published_at' => now(),
        ]);
    }
}
