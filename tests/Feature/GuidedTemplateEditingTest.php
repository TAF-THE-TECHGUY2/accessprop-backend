<?php

namespace Tests\Feature;

use App\Models\EmailTemplate;
use App\Models\User;
use App\Support\EditableTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Editing an email's prose without touching the markup that makes it render.
 */
class GuidedTemplateEditingTest extends TestCase
{
    use RefreshDatabase;

    /** A miniature of the real letter: nested tables, inline styles, a leaf per role. */
    private const LETTER = <<<'HTML'
    <table role="presentation" width="100%" style="background:#f5f5f5;">
      <tr><td align="center">
        <table width="600">
          <tr><td style="padding:40px;">
            <h1 data-edit="Headline" style="font-size:28px;">Welcome to Access Properties</h1>
            <p data-edit="Greeting" style="margin:0 0 20px 0;">Dear {{ $firstName }},</p>
            <p style="margin:0 0 20px 0;">
                Thank you for creating your investor account with
                <strong>Access Real Estate Fund I</strong>.
            </p>
            <p style="margin:0;">Second paragraph.</p>
            <td data-edit="false" style="background:#0b0b0b;">AP</td>
          </td></tr>
        </table>
      </td></tr>
    </table>
    HTML;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::factory()->create());
    }

    private function template(): EmailTemplate
    {
        return EmailTemplate::create([
            'key' => 'investor_welcome',
            'name' => 'Investor welcome',
            'subject' => 'Welcome to Access Properties',
            'body_html' => self::LETTER,
            'body_text' => 'stale hand-written copy',
            'is_active' => true,
        ]);
    }

    public function test_the_prose_is_offered_as_labelled_fields(): void
    {
        $this->template();

        $fields = $this->getJson('/api/admin/email-templates/investor_welcome')
            ->assertOk()
            ->json('fields');

        $labels = array_column($fields, 'label');

        $this->assertSame(['Headline', 'Greeting', 'Paragraph 1', 'Paragraph 2'], $labels);

        // Markup is not what the admin edits: the box holds words.
        $this->assertSame('Dear {{ $firstName }},', $fields[1]['text']);
        $this->assertSame(
            'Thank you for creating your investor account with *Access Real Estate Fund I*.',
            $fields[2]['text'],
        );
    }

    public function test_the_wordmark_is_not_offered_for_editing(): void
    {
        $this->template();

        $fields = $this->getJson('/api/admin/email-templates/investor_welcome')->json('fields');

        $this->assertNotContains('AP', array_column($fields, 'text'));
    }

    public function test_a_field_edit_leaves_the_scaffolding_byte_identical(): void
    {
        $template = $this->template();
        $fields = EditableTemplate::fields($template->body_html);
        $greeting = collect($fields)->firstWhere('label', 'Greeting');

        $this->putJson('/api/admin/email-templates/investor_welcome', [
            'subject' => 'Welcome to Access Properties',
            'fields' => [$greeting['key'] => 'Hello {{ $firstName }},'],
        ])->assertOk();

        $saved = $template->fresh()->body_html;

        $this->assertStringContainsString('Hello {{ $firstName }},', $saved);
        $this->assertStringNotContainsString('Dear {{ $firstName }},', $saved);

        // Everything that makes it render in Outlook is still there, verbatim.
        $this->assertSame(substr_count(self::LETTER, '<table'), substr_count($saved, '<table'));
        $this->assertSame(substr_count(self::LETTER, 'style="'), substr_count($saved, 'style="'));
        $this->assertStringContainsString('<strong>Access Real Estate Fund I</strong>', $saved);
        $this->assertStringContainsString('style="background:#f5f5f5;"', $saved);
    }

    public function test_a_guided_save_rebuilds_the_plain_text_from_the_same_words(): void
    {
        $template = $this->template();
        $fields = EditableTemplate::fields($template->body_html);
        $second = collect($fields)->firstWhere('label', 'Paragraph 2');

        $this->putJson('/api/admin/email-templates/investor_welcome', [
            'subject' => 'Welcome to Access Properties',
            'fields' => [$second['key'] => 'A replacement second paragraph.'],
        ])->assertOk();

        $text = $template->fresh()->body_text;

        // The half nobody remembers to edit is no longer authored by hand.
        $this->assertStringNotContainsString('stale hand-written copy', $text);
        $this->assertStringContainsString('A replacement second paragraph.', $text);
        $this->assertStringContainsString("WELCOME TO ACCESS PROPERTIES\n====", $text);
        $this->assertStringContainsString('Dear {{ $firstName }},', $text);
        // Markup never reaches the text part.
        $this->assertStringNotContainsString('<', $text);
    }

    public function test_a_guided_preview_renders_unsaved_field_values(): void
    {
        $template = $this->template();
        $fields = EditableTemplate::fields($template->body_html);
        $headline = collect($fields)->firstWhere('label', 'Headline');

        $response = $this->postJson('/api/admin/email-templates/investor_welcome/preview', [
            'subject' => 'Welcome',
            'fields' => [$headline['key'] => 'A brand new headline'],
        ])->assertOk();

        $this->assertStringContainsString('A brand new headline', $response->json('html'));
        $this->assertStringContainsString('A BRAND NEW HEADLINE', $response->json('text'));
        // Nothing was written by previewing.
        $this->assertStringContainsString('Welcome to Access Properties', $template->fresh()->body_html);
    }

    public function test_the_editor_marker_never_reaches_the_investor(): void
    {
        $template = $this->template();

        $rendered = $template->renderHtml(['firstName' => 'Alex']);

        $this->assertStringNotContainsString('data-edit', $rendered);
        $this->assertStringContainsString('Dear Alex,', $rendered);
        $this->assertStringContainsString('<table', $rendered);
    }

    public function test_emphasis_survives_a_round_trip(): void
    {
        $template = $this->template();
        $fields = EditableTemplate::fields($template->body_html);
        $paragraph = collect($fields)->firstWhere('label', 'Paragraph 1');

        // Bold reaches the editor as *stars*, not as a tag.
        $this->assertSame(
            'Thank you for creating your investor account with *Access Real Estate Fund I*.',
            $paragraph['text'],
        );

        $this->putJson('/api/admin/email-templates/investor_welcome', [
            'subject' => 'Welcome to Access Properties',
            'fields' => [$paragraph['key'] => 'Now with *two* bold *phrases* in it.'],
        ])->assertOk();

        $saved = $template->fresh()->body_html;

        $this->assertStringContainsString('<strong>two</strong>', $saved);
        $this->assertStringContainsString('<strong>phrases</strong>', $saved);
    }

    public function test_a_region_holding_markup_it_cannot_represent_is_not_offered(): void
    {
        // A styled call-to-action button. Reduced to text and rebuilt it would
        // lose every attribute that makes it a button, so it is left alone.
        $template = EmailTemplate::create([
            'key' => 'investor_password_reset',
            'name' => 'Password reset',
            'subject' => 'Reset your password',
            'body_html' => '<table><tr><td>'
                .'<p>Click the button below.</p>'
                .'<p><a href="{{ $resetUrl }}" style="background:#0b0b0b; padding:14px 36px;">Reset password</a></p>'
                .'</td></tr></table>',
            'is_active' => true,
        ]);

        $labels = array_column(
            $this->getJson('/api/admin/email-templates/investor_password_reset')->json('fields'),
            'text',
        );

        $this->assertContains('Click the button below.', $labels);
        $this->assertNotContains('Reset password ({{ $resetUrl }})', $labels);

        // And a save of the offered field leaves the button untouched.
        $fields = EditableTemplate::fields($template->body_html);
        $this->putJson('/api/admin/email-templates/investor_password_reset', [
            'subject' => 'Reset your password',
            'fields' => [$fields[0]['key'] => 'Use the button below.'],
        ])->assertOk();

        $this->assertStringContainsString(
            '<a href="{{ $resetUrl }}" style="background:#0b0b0b; padding:14px 36px;">Reset password</a>',
            $template->fresh()->body_html,
        );
    }

    public function test_a_blade_expression_is_not_escaped_on_the_way_back(): void
    {
        $template = $this->template();
        $fields = EditableTemplate::fields($template->body_html);
        $greeting = collect($fields)->firstWhere('label', 'Greeting');

        // htmlspecialchars would turn $investor->email into $investor-&gt;email
        // and the template would stop compiling entirely.
        $this->putJson('/api/admin/email-templates/investor_welcome', [
            'subject' => 'Welcome',
            'fields' => [$greeting['key'] => 'Hi {{ $firstName }} at {{ $investor->email }},'],
        ])->assertOk();

        $saved = $template->fresh();

        $this->assertStringContainsString('{{ $investor->email }}', $saved->body_html);
        // It compiles, and both expressions resolve.
        $rendered = $saved->renderHtml([
            'firstName' => 'Alex',
            'investor' => (object) ['email' => 'alex@example.com'],
        ]);

        $this->assertStringContainsString('Hi Alex at alex@example.com,', $rendered);
        $this->assertStringNotContainsString('&gt;', $rendered);
    }

    public function test_an_unchanged_paragraph_is_left_exactly_as_it_was(): void
    {
        $template = $this->template();
        $fields = EditableTemplate::fields($template->body_html);
        $second = collect($fields)->firstWhere('label', 'Paragraph 2');

        // Only the field that changed is sent, so nothing else is rewritten.
        $this->putJson('/api/admin/email-templates/investor_welcome', [
            'subject' => 'Welcome to Access Properties',
            'fields' => [$second['key'] => 'Replaced.'],
        ])->assertOk();

        $saved = $template->fresh()->body_html;

        // The edited paragraph changed; the one above it was not rewritten, so
        // its source line breaks and its <strong> survive exactly as authored.
        $this->assertStringContainsString('<p style="margin:0;">Replaced.</p>', $saved);

        $untouched = substr(self::LETTER, strpos(self::LETTER, 'Thank you for creating'));
        $untouched = substr($untouched, 0, strpos($untouched, '</p>'));
        $this->assertStringContainsString($untouched, $saved);
    }

    public function test_the_raw_editor_still_works_for_a_template_with_no_fields(): void
    {
        EmailTemplate::create([
            'key' => 'investor_password_reset',
            'name' => 'Password reset',
            'subject' => 'Reset your password',
            'body_html' => 'plain string, no elements at all',
            'is_active' => true,
        ]);

        $this->getJson('/api/admin/email-templates/investor_password_reset')
            ->assertOk()
            ->assertJsonPath('fields', []);

        $this->putJson('/api/admin/email-templates/investor_password_reset', [
            'subject' => 'Reset your password',
            'bodyHtml' => '<p>Rewritten by hand.</p>',
            'bodyText' => 'Rewritten by hand.',
        ])->assertOk()->assertJsonPath('bodyHtml', '<p>Rewritten by hand.</p>');
    }
}
