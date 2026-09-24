<?php

namespace Tests\Feature;

use App\Models\EmailTemplate;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The preview shows who the email comes from, not just what it says.
 *
 * It used to return the subject and body alone, so an admin who changed the
 * From address had no way to see the change in the preview, and no way to tell
 * a blank field inheriting the platform default from one that had not saved.
 */
class EmailTemplatePreviewSenderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs(User::factory()->create());

        Setting::singleton()->update([
            'mail_from_name' => 'Access Properties',
            'mail_from_address' => 'hello@mg.ap.boston',
            'mail_reply_to_address' => 'hello@ap.boston',
        ]);
    }

    private function template(array $overrides = []): EmailTemplate
    {
        return EmailTemplate::create(array_merge([
            'key' => 'investor_welcome',
            'name' => 'Investor welcome',
            'subject' => 'Welcome to Access Properties',
            'body_html' => '<p>Hi {{ $firstName }}</p>',
            'is_active' => true,
        ], $overrides));
    }

    public function test_a_blank_template_previews_the_inherited_platform_sender(): void
    {
        $this->template();

        $this->postJson('/api/admin/email-templates/investor_welcome/preview', [])
            ->assertOk()
            ->assertJsonPath('sender.fromAddress', 'hello@mg.ap.boston')
            ->assertJsonPath('sender.fromName', 'Access Properties')
            ->assertJsonPath('sender.replyToAddress', 'hello@ap.boston')
            // Flagged so the UI can say where the address came from.
            ->assertJsonPath('sender.inherited.fromAddress', true)
            ->assertJsonPath('sender.inherited.replyToAddress', true);
    }

    public function test_a_saved_override_previews_ahead_of_the_platform_default(): void
    {
        $this->template([
            'from_name' => 'Dionysios at Access',
            'from_address' => 'founder@mg.ap.boston',
            'reply_to_address' => 'founder@ap.boston',
        ]);

        $this->postJson('/api/admin/email-templates/investor_welcome/preview', [])
            ->assertOk()
            ->assertJsonPath('sender.fromAddress', 'founder@mg.ap.boston')
            ->assertJsonPath('sender.fromName', 'Dionysios at Access')
            ->assertJsonPath('sender.replyToAddress', 'founder@ap.boston')
            ->assertJsonPath('sender.inherited.fromAddress', false);
    }

    public function test_an_unsaved_edit_in_the_form_shows_in_the_preview(): void
    {
        $this->template(['from_address' => 'founder@mg.ap.boston']);

        // This is the case that had no feedback at all: the admin types a new
        // address and hits Preview before saving.
        $this->postJson('/api/admin/email-templates/investor_welcome/preview', [
            'fromName' => 'Investor Relations',
            'fromAddress' => 'investors@mg.ap.boston',
            'replyToAddress' => 'ir@ap.boston',
        ])
            ->assertOk()
            ->assertJsonPath('sender.fromAddress', 'investors@mg.ap.boston')
            ->assertJsonPath('sender.fromName', 'Investor Relations')
            ->assertJsonPath('sender.replyToAddress', 'ir@ap.boston')
            ->assertJsonPath('sender.inherited.fromAddress', false);
    }

    public function test_a_test_send_is_recorded_in_the_email_log(): void
    {
        Mail::fake();
        $this->template();

        $this->postJson('/api/admin/email-templates/investor_welcome/test', [
            'email' => 'ops@example.com',
        ])->assertOk();

        $this->assertDatabaseHas('email_logs', [
            'recipient' => 'ops@example.com',
            'type' => 'investor_welcome_test',
            'status' => 'sent',
        ]);
    }

    public function test_a_failed_test_send_is_recorded_as_failed(): void
    {
        $this->template();

        Mail::shouldReceive('to->send')
            ->andThrow(new \RuntimeException('Mailgun refused the message'));

        $this->postJson('/api/admin/email-templates/investor_welcome/test', [
            'email' => 'ops@example.com',
        ])->assertStatus(502)
            ->assertJsonPath('message', 'Send failed: Mailgun refused the message');

        $this->assertDatabaseHas('email_logs', [
            'recipient' => 'ops@example.com',
            'status' => 'failed',
        ]);
    }

    public function test_the_plain_text_part_is_saved_rendered_and_previewed(): void
    {
        $this->template(['body_text' => 'Hi {{ $firstName }}, welcome.']);

        // Saved and read back.
        $this->putJson('/api/admin/email-templates/investor_welcome', [
            'subject' => 'Welcome',
            'bodyHtml' => '<p>Hi</p>',
            'bodyText' => 'Hello {{ $firstName }} in plain text.',
        ])->assertOk()->assertJsonPath('bodyText', 'Hello {{ $firstName }} in plain text.');

        // Rendered with its variables resolved, like the HTML part.
        $this->postJson('/api/admin/email-templates/investor_welcome/preview', [])
            ->assertOk()
            ->assertJsonPath('text', 'Hello Alex in plain text.');
    }

    public function test_clearing_the_plain_text_previews_as_empty_not_as_the_stored_copy(): void
    {
        $this->template(['body_text' => 'The previously saved plain text.']);

        // Emptying the box sends null. It used to fall through to the stored
        // value, so the preview showed text the admin had just deleted.
        $this->postJson('/api/admin/email-templates/investor_welcome/preview', [
            'subject' => 'Welcome',
            'bodyHtml' => '<p>Hi</p>',
            'bodyText' => null,
        ])
            ->assertOk()
            ->assertJsonPath('text', null);
    }

    public function test_an_omitted_field_still_falls_back_to_what_is_stored(): void
    {
        $this->template(['body_text' => 'The previously saved plain text.']);

        // Sending nothing at all must still preview the saved template.
        $this->postJson('/api/admin/email-templates/investor_welcome/preview', [])
            ->assertOk()
            ->assertJsonPath('text', 'The previously saved plain text.');
    }

    public function test_the_body_still_renders_alongside_the_sender(): void
    {
        $this->template();

        $response = $this->postJson('/api/admin/email-templates/investor_welcome/preview', [
            'subject' => 'Hello {{ $firstName }}',
            'bodyHtml' => '<p>Welcome {{ $firstName }}</p>',
        ])->assertOk();

        $this->assertSame('Hello Alex', $response->json('subject'));
        $this->assertStringContainsString('Welcome Alex', $response->json('html'));
        $this->assertSame([], $response->json('missingVariables'));
    }
}
