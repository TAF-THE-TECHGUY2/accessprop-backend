<?php

namespace Tests\Feature;

use App\Mail\AgreementCompletedMail;
use App\Mail\AgreementSignRequestMail;
use App\Models\EmailTemplate;
use App\Models\Investor;
use App\Models\SigningEnvelope;
use App\Models\SigningLink;
use App\Models\User;
use App\Services\DocuSign\AgreementNotifier;
use App\Services\DocuSign\EnvelopeBuilder;
use App\Services\DocuSign\SigningNotYetOpen;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

/**
 * The app's own signing emails in place of DocuSign's: the counter signer is
 * asked first, the investor once that is done, reminders on a schedule, and a
 * single "all signed" email at the end. The links open signing with no sign-in.
 */
class AgreementEmailsTest extends TestCase
{
    use RefreshDatabase;

    /** What DocuSign reports; each test sets it. */
    private array $state = [];

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Bus::fake();
        config(['app.frontend_url' => 'https://investor.ap.boston', 'docusign.reminders' => ['every_days' => 3, 'max' => 3]]);

        $notifier = Mockery::mock(AgreementNotifier::class)->makePartial();
        $notifier->shouldReceive('state')->andReturnUsing(fn () => $this->state);
        $this->app->instance(AgreementNotifier::class, $notifier);
    }

    private function docusignSays(string $envelope, string $counter, string $investor): void
    {
        $this->state = ['envelope' => $envelope, 'roles' => [
            'counter_signer' => ['status' => $counter, 'order' => 1],
            'investor' => ['status' => $investor, 'order' => 2],
        ]];
    }

    private function envelope(string $status = SigningEnvelope::STATUS_SENT, bool $embedded = true): SigningEnvelope
    {
        $investor = Investor::create([
            'code' => 'inv-9101',
            'name' => 'Jane Investor',
            'email' => 'jane@example.com',
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

        return SigningEnvelope::create([
            'investor_id' => $investor->id,
            'docusign_envelope_id' => 'env-9101',
            'template_id' => 'tpl-w9',
            'embedded_signing' => $embedded,
            'type' => 'subscription_agreement',
            'status' => $status,
            'investor_email' => 'jane@example.com',
            'investor_name' => 'Jane Investor',
            'counter_signer_email' => 'dion@example.com',
            'counter_signer_name' => 'Dionysios Kaskarelis',
        ]);
    }

    private function followUp(SigningEnvelope $envelope): void
    {
        app(AgreementNotifier::class)->followUp($envelope->fresh());
    }

    public function test_the_counter_signer_is_emailed_first_and_only_once(): void
    {
        $env = $this->envelope();
        $this->docusignSays('sent', 'sent', 'created');

        $this->followUp($env);
        $this->followUp($env);

        Mail::assertSent(AgreementSignRequestMail::class, 1);
        Mail::assertSent(AgreementSignRequestMail::class, function ($mail) {
            return $mail->hasTo('dion@example.com')
                && $mail->role === 'counter_signer'
                && ! $mail->reminder
                && str_starts_with($mail->signUrl, 'https://investor.ap.boston/sign/');
        });
        $this->assertDatabaseHas('email_logs', ['recipient' => 'dion@example.com', 'type' => 'agreement_countersign_request']);
    }

    public function test_the_investor_is_emailed_once_the_counter_signer_has_signed(): void
    {
        $env = $this->envelope();
        $this->docusignSays('sent', 'sent', 'created');
        $this->followUp($env);

        $this->docusignSays('sent', 'completed', 'sent');
        $this->followUp($env);

        Mail::assertSent(AgreementSignRequestMail::class, fn ($m) => $m->hasTo('jane@example.com') && $m->role === 'investor');
        $this->assertNotNull(SigningLink::where('role', 'counter_signer')->first()->signed_at);
    }

    public function test_reminders_go_every_three_days_up_to_three_times(): void
    {
        $env = $this->envelope();
        $this->docusignSays('sent', 'completed', 'delivered');
        $this->followUp($env);

        $this->travel(2)->days();
        $this->followUp($env);
        Mail::assertSent(AgreementSignRequestMail::class, 1);

        foreach (range(1, 5) as $_) {
            $this->travel(3)->days();
            $this->followUp($env);
        }

        Mail::assertSent(AgreementSignRequestMail::class, 4);
        Mail::assertSent(AgreementSignRequestMail::class, fn ($m) => $m->reminder && str_starts_with($m->envelope()->subject, 'Reminder: '));
        $this->assertSame(3, SigningLink::where('role', 'investor')->first()->reminders_sent);
    }

    public function test_finishing_sends_one_completion_email_without_a_webhook(): void
    {
        $env = $this->envelope();
        $this->docusignSays('completed', 'completed', 'completed');

        $this->followUp($env);
        $this->followUp($env);

        $this->assertSame(SigningEnvelope::STATUS_COMPLETED, $env->fresh()->status);
        $this->assertSame('completed', $env->investor->fresh()->document_signing_status);
        Mail::assertSent(AgreementCompletedMail::class, 1);
        Mail::assertSent(AgreementCompletedMail::class, fn ($m) => $m->hasTo('jane@example.com'));
        Mail::assertNotSent(AgreementSignRequestMail::class);
    }

    public function test_a_voided_envelope_is_noticed_and_nobody_is_emailed(): void
    {
        $env = $this->envelope();
        $this->docusignSays('voided', 'sent', 'created');

        $this->followUp($env);

        $this->assertSame(SigningEnvelope::STATUS_VOIDED, $env->fresh()->status);
        Mail::assertNothingSent();
    }

    public function test_envelopes_sent_the_old_way_are_left_to_docusign(): void
    {
        $env = $this->envelope(embedded: false);
        $this->docusignSays('sent', 'sent', 'created');

        $this->followUp($env);
        $this->artisan('agreements:follow-up')->assertSuccessful();

        Mail::assertNothingSent();
    }

    public function test_the_hourly_follow_up_emails_open_agreements(): void
    {
        $this->envelope();
        $this->docusignSays('sent', 'sent', 'created');

        $this->artisan('agreements:follow-up')->expectsOutputToContain('Followed up 1')->assertSuccessful();

        Mail::assertSent(AgreementSignRequestMail::class, fn ($m) => $m->hasTo('dion@example.com'));
    }

    public function test_admin_resend_sends_the_app_s_email_to_whoever_is_up(): void
    {
        $env = $this->envelope();
        $this->docusignSays('sent', 'completed', 'sent');
        Sanctum::actingAs(User::factory()->create());

        $this->postJson("/api/admin/agreements/{$env->id}/resend")
            ->assertOk()
            ->assertJsonPath('message', 'Signing email resent to the investor.');

        Mail::assertSent(AgreementSignRequestMail::class, fn ($m) => $m->hasTo('jane@example.com'));
    }

    // ---- The link in the email --------------------------------------------

    private function linkFor(SigningEnvelope $env, string $role): string
    {
        return SigningLink::issue($env, $role)->token;
    }

    private function builderReturning(callable $fn): void
    {
        $builder = Mockery::mock(EnvelopeBuilder::class);
        $builder->shouldReceive('signingUrl')->andReturnUsing($fn);
        $this->app->instance(EnvelopeBuilder::class, $builder);
    }

    public function test_the_link_opens_signing_with_no_sign_in(): void
    {
        $env = $this->envelope();
        $token = $this->linkFor($env, 'counter_signer');
        $this->builderReturning(fn ($e, $role, $return) => "https://docusign.test/{$role}?return={$return}");

        $this->getJson("/api/sign/{$token}")
            ->assertOk()
            ->assertJsonPath('status', 'ready')
            ->assertJsonPath('url', 'https://docusign.test/counter_signer?return=https://investor.ap.boston/signing/complete');
    }

    public function test_the_investor_s_link_waits_its_turn(): void
    {
        $env = $this->envelope();
        $token = $this->linkFor($env, 'investor');
        $this->builderReturning(fn () => throw new SigningNotYetOpen('Dionysios Kaskarelis signs first.'));

        $this->getJson("/api/sign/{$token}")->assertStatus(409)->assertJsonPath('status', 'waiting');
    }

    public function test_signed_closed_and_unknown_links(): void
    {
        $env = $this->envelope();
        $token = $this->linkFor($env, 'counter_signer');
        $this->builderReturning(fn () => $this->fail('DocuSign should not be asked'));

        $this->getJson('/api/sign/'.str_repeat('a', 64))->assertNotFound()->assertJsonPath('status', 'invalid');

        SigningLink::where('role', 'counter_signer')->update(['signed_at' => now()]);
        $this->getJson("/api/sign/{$token}")->assertOk()->assertJsonPath('status', 'signed');

        SigningLink::where('role', 'counter_signer')->update(['signed_at' => null]);
        $env->update(['status' => SigningEnvelope::STATUS_VOIDED]);
        $this->getJson("/api/sign/{$token}")->assertStatus(410)->assertJsonPath('status', 'closed');
    }

    public function test_the_token_is_stored_encrypted_and_found_by_hash(): void
    {
        $link = SigningLink::issue($this->envelope(), 'investor');
        $raw = \DB::table('signing_links')->first();

        $this->assertNotSame($link->token, $raw->token);
        $this->assertSame(hash('sha256', $link->token), $raw->token_hash);
        $this->assertTrue(SigningLink::findByToken($link->token)->is($link));
    }

    public function test_the_emails_are_editable_templates(): void
    {
        foreach (['agreement_countersign_request', 'agreement_sign_request', 'agreement_completed'] as $key) {
            $this->assertTrue(EmailTemplate::where('key', $key)->exists(), $key);
        }

        $mail = new AgreementSignRequestMail('counter_signer', 'Dionysios Kaskarelis', 'Jane Investor', 'Access Real Estate Fund I', 'https://investor.ap.boston/sign/abc');
        $this->assertSame('Subscription agreement ready for countersignature — Jane Investor', $mail->envelope()->subject);
        $mail->assertSeeInHtml('https://investor.ap.boston/sign/abc');
        $mail->assertSeeInHtml('Dionysios');
    }
}
