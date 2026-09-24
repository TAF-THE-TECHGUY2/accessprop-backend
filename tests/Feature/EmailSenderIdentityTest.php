<?php

namespace Tests\Feature;

use App\Mail\InvestorWelcomeMail;
use App\Models\EmailTemplate;
use App\Models\Investor;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EmailSenderIdentityTest extends TestCase
{
    use RefreshDatabase;

    private function envelope(): \Illuminate\Mail\Mailables\Envelope
    {
        $investor = new Investor([
            'code' => 'inv-1003',
            'name' => 'Abel Sample',
            'email' => 'abel.sample@example.com',
        ]);

        return (new InvestorWelcomeMail($investor))->envelope();
    }

    public function test_the_platform_default_sets_the_sender(): void
    {
        Setting::singleton()->update([
            'mail_from_name' => 'Access Properties',
            'mail_from_address' => 'hello@mg.ap.boston',
            'mail_reply_to_address' => 'hello@ap.boston',
        ]);

        $envelope = $this->envelope();

        $this->assertSame('hello@mg.ap.boston', $envelope->from->address);
        $this->assertSame('Access Properties', $envelope->from->name);
        $this->assertSame('hello@ap.boston', $envelope->replyTo[0]->address);
    }

    public function test_changing_the_setting_changes_who_the_email_comes_from(): void
    {
        Setting::singleton()->update([
            'mail_from_name' => 'Access Investor Relations',
            'mail_from_address' => 'investors@mg.ap.boston',
            'mail_reply_to_address' => 'ir@ap.boston',
        ]);

        $envelope = $this->envelope();

        $this->assertSame('Access Investor Relations', $envelope->from->name);
        $this->assertSame('investors@mg.ap.boston', $envelope->from->address);
        $this->assertSame('ir@ap.boston', $envelope->replyTo[0]->address);
    }

    public function test_a_template_override_beats_the_platform_default(): void
    {
        Setting::singleton()->update([
            'mail_from_name' => 'Access Properties',
            'mail_from_address' => 'hello@mg.ap.boston',
            'mail_reply_to_address' => 'hello@ap.boston',
        ]);

        EmailTemplate::create([
            'key' => InvestorWelcomeMail::TEMPLATE_KEY,
            'name' => 'Investor welcome',
            'subject' => 'Welcome to Access Properties',
            'body_html' => '<p>Hi {{ $firstName }}</p>',
            'is_active' => true,
            'from_name' => 'Dionysios at Access',
            'from_address' => 'founder@mg.ap.boston',
            'reply_to_address' => 'founder@ap.boston',
        ]);

        $envelope = $this->envelope();

        $this->assertSame('Dionysios at Access', $envelope->from->name);
        $this->assertSame('founder@mg.ap.boston', $envelope->from->address);
        $this->assertSame('founder@ap.boston', $envelope->replyTo[0]->address);
    }

    public function test_a_blank_template_override_inherits_the_platform_default(): void
    {
        Setting::singleton()->update([
            'mail_from_name' => 'Access Properties',
            'mail_from_address' => 'hello@mg.ap.boston',
        ]);

        EmailTemplate::create([
            'key' => InvestorWelcomeMail::TEMPLATE_KEY,
            'name' => 'Investor welcome',
            'subject' => 'Welcome',
            'body_html' => '<p>Hi</p>',
            'is_active' => true,
            'from_name' => null,
            'from_address' => null,
        ]);

        $this->assertSame('Access Properties', $this->envelope()->from->name);
        $this->assertSame('hello@mg.ap.boston', $this->envelope()->from->address);
    }

    public function test_an_unreadable_settings_row_falls_back_to_config(): void
    {
        // Nothing in settings at all — mail must still address itself correctly.
        Setting::query()->delete();
        config(['mail.from.address' => 'fallback@mg.ap.boston', 'mail.from.name' => 'Fallback Name']);

        $envelope = $this->envelope();

        $this->assertSame('fallback@mg.ap.boston', $envelope->from->address);
        $this->assertSame('Fallback Name', $envelope->from->name);
        // The mailable's own literal still supplies a reply-to.
        $this->assertSame('hello@ap.boston', $envelope->replyTo[0]->address);
    }

    public function test_a_from_address_off_the_verified_domain_is_rejected(): void
    {
        config(['mail.default' => 'mailgun', 'services.mailgun.domain' => 'mg.ap.boston']);
        Sanctum::actingAs(User::factory()->create());

        EmailTemplate::create([
            'key' => 'investor_welcome',
            'name' => 'Investor welcome',
            'subject' => 'Welcome',
            'body_html' => '<p>Hi</p>',
            'is_active' => true,
        ]);

        $this->putJson('/api/admin/settings', ['mailFromAddress' => 'hello@gmail.com'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('mailFromAddress');

        $this->putJson('/api/admin/email-templates/investor_welcome', [
            'subject' => 'Welcome',
            'bodyHtml' => '<p>Hi</p>',
            'fromAddress' => 'hello@gmail.com',
        ])->assertStatus(422)->assertJsonValidationErrors('fromAddress');
    }

    public function test_a_reply_to_on_another_domain_is_allowed(): void
    {
        config(['mail.default' => 'mailgun', 'services.mailgun.domain' => 'mg.ap.boston']);
        Sanctum::actingAs(User::factory()->create());

        // The whole point of reply-to: it is not DKIM-signed, so it can be the
        // human-facing mailbox on the parent domain.
        $this->putJson('/api/admin/settings', ['mailReplyToAddress' => 'hello@ap.boston'])
            ->assertOk()
            ->assertJson(['mailReplyToAddress' => 'hello@ap.boston']);
    }

    public function test_the_editor_is_told_what_a_blank_override_inherits(): void
    {
        Setting::singleton()->update([
            'mail_from_name' => 'Access Properties',
            'mail_from_address' => 'hello@mg.ap.boston',
        ]);
        EmailTemplate::create([
            'key' => 'investor_welcome',
            'name' => 'Investor welcome',
            'subject' => 'Welcome',
            'body_html' => '<p>Hi</p>',
            'is_active' => true,
        ]);
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/admin/email-templates/investor_welcome')
            ->assertOk()
            ->assertJson([
                'fromName' => null,
                'inherited' => [
                    'fromName' => 'Access Properties',
                    'fromAddress' => 'hello@mg.ap.boston',
                ],
            ]);
    }

    public function test_mail_that_has_no_template_still_follows_the_setting(): void
    {
        Setting::singleton()->update([
            'mail_from_name' => 'Access Investor Relations',
            'mail_from_address' => 'investors@mg.ap.boston',
        ]);

        // The team's secure-message notification is not template-backed, but an
        // admin changing the platform sender expects it to change everywhere.
        $investor = Investor::create([
            'code' => 'inv-2001',
            'name' => 'Abel Sample',
            'email' => 'abel.sample@example.com',
            'country' => 'United States',
            'joined_at' => now(),
            'investment_amount' => 10000,
            'accreditation_status' => 'accredited',
            'kyc_status' => 'pending',
            'investment_status' => 'awaiting_kyc',
            'dashboard_status' => 'pending',
            'address_line1' => '1 Test Street',
            'address_city' => 'Boston',
            'address_state' => 'MA',
            'address_postal_code' => '02108',
            'address_country' => 'United States',
            'personal_investor_type' => 'individual',
            'personal_residency' => 'us_resident',
            'investment_fund_name' => 'Access Real Estate Fund I',
            'investment_commitment' => 10000,
            'investment_funded' => 0,
            'investment_wallet_status' => 'pending',
            'investment_expected_yield' => 'n/a',
            'accreditation_verification_status' => 'verification_required',
            'document_signing_status' => 'not_started',
            'newsletter_opted_in' => false,
        ]);

        $thread = \App\Models\MessageThread::create([
            'investor_id' => $investor->id,
            'subject' => 'Adding to my position',
            'category' => 'general',
            'opened_by' => 'investor',
            'last_message_at' => now(),
        ]);
        $message = \App\Models\ThreadMessage::create([
            'thread_id' => $thread->id,
            'author_type' => 'investor',
            'author_name' => $investor->name,
            'body' => 'Could you share the process?',
        ]);

        $mail = new \App\Mail\SecureMessageReceivedMail($thread, $message);
        $mail->build();

        $this->assertSame('investors@mg.ap.boston', $mail->from[0]['address']);
        $this->assertSame('Access Investor Relations', $mail->from[0]['name']);
        // Replies still reach the investor who wrote in.
        $this->assertSame($investor->email, $mail->replyTo[0]['address']);
    }
}
