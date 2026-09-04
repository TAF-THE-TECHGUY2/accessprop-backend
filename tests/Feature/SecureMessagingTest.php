<?php

namespace Tests\Feature;

use App\Mail\SecureMessageReceivedMail;
use App\Models\Fund;
use App\Models\Investor;
use App\Models\MessageThread;
use App\Models\PortalDocument;
use App\Models\Setting;
use App\Models\ThreadMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SecureMessagingTest extends TestCase
{
    use RefreshDatabase;

    public function test_investor_can_open_a_thread_and_the_team_is_notified(): void
    {
        Mail::fake();
        Setting::singleton()->update(['support_email' => 'investors@ap.boston']);

        $investor = $this->makeInvestor();
        Sanctum::actingAs($investor);

        $this->postJson('/api/investor/portal/threads', [
            'subject' => 'Adding to my position',
            'body' => 'Could you share the process and current availability?',
            'category' => 'general',
        ])
            ->assertCreated()
            ->assertJsonPath('subject', 'Adding to my position')
            ->assertJsonPath('state', 'awaiting_team')
            ->assertJsonPath('openedBy', 'investor')
            ->assertJsonPath('messages.0.authorType', 'investor')
            ->assertJsonPath('messages.0.authorName', 'Preferences Investor');

        Mail::assertSent(SecureMessageReceivedMail::class);
    }

    public function test_a_failed_team_notification_does_not_lose_the_message(): void
    {
        // The message is already committed and visible in the console; losing
        // the email is recoverable, losing what the investor wrote is not.
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('smtp down'));

        $investor = $this->makeInvestor();
        Sanctum::actingAs($investor);

        $this->postJson('/api/investor/portal/threads', [
            'subject' => 'Still delivered',
            'body' => 'This must survive a mail failure.',
        ])->assertCreated();

        $this->assertDatabaseHas('thread_messages', ['body' => 'This must survive a mail failure.']);
    }

    public function test_state_follows_whoever_wrote_last(): void
    {
        Mail::fake();
        $investor = $this->makeInvestor();
        $admin = User::factory()->create();

        Sanctum::actingAs($investor);
        $id = $this->postJson('/api/investor/portal/threads', [
            'subject' => 'Question',
            'body' => 'First message.',
        ])->json('id');

        Sanctum::actingAs($admin);
        $this->postJson("/api/admin/threads/{$id}/messages", ['body' => 'Our answer.'])
            ->assertOk()
            ->assertJsonPath('state', 'awaiting_investor');

        Sanctum::actingAs($investor);
        $this->postJson("/api/investor/portal/threads/{$id}/messages", ['body' => 'Thanks, one more thing.'])
            ->assertOk()
            ->assertJsonPath('state', 'awaiting_team');
    }

    public function test_an_investor_reply_reopens_a_resolved_thread(): void
    {
        Mail::fake();
        $investor = $this->makeInvestor();
        $admin = User::factory()->create();

        Sanctum::actingAs($investor);
        $id = $this->postJson('/api/investor/portal/threads', [
            'subject' => 'Closing this',
            'body' => 'Initial.',
        ])->json('id');

        Sanctum::actingAs($admin);
        $this->postJson("/api/admin/threads/{$id}/resolve")
            ->assertOk()
            ->assertJsonPath('state', 'resolved');

        // The team closes threads; an investor with a follow-up must never be
        // left with nowhere to put it.
        Sanctum::actingAs($investor);
        $this->postJson("/api/investor/portal/threads/{$id}/messages", ['body' => 'Actually, one more.'])
            ->assertOk()
            ->assertJsonPath('state', 'awaiting_team');

        $this->assertNull(MessageThread::find($id)->resolved_at);
    }

    public function test_an_investor_cannot_see_or_reply_to_someone_elses_thread(): void
    {
        Mail::fake();
        $mine = $this->makeInvestor();
        $theirs = $this->makeInvestor('inv-8002', 'other@example.com', 'Other Investor');

        Sanctum::actingAs($theirs);
        $id = $this->postJson('/api/investor/portal/threads', [
            'subject' => 'Private',
            'body' => 'Not for anyone else.',
        ])->json('id');

        Sanctum::actingAs($mine);
        // 404, not 403: someone else's thread must be indistinguishable from
        // one that does not exist.
        $this->getJson("/api/investor/portal/threads/{$id}")->assertNotFound();
        $this->postJson("/api/investor/portal/threads/{$id}/messages", ['body' => 'Intruding.'])
            ->assertNotFound();

        $this->getJson('/api/investor/portal/threads')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_opening_a_thread_marks_the_teams_messages_read(): void
    {
        Mail::fake();
        $investor = $this->makeInvestor();
        $admin = User::factory()->create();

        Sanctum::actingAs($investor);
        $id = $this->postJson('/api/investor/portal/threads', [
            'subject' => 'Unread',
            'body' => 'Mine.',
        ])->json('id');

        Sanctum::actingAs($admin);
        $this->postJson("/api/admin/threads/{$id}/messages", ['body' => 'Ours.'])->assertOk();

        Sanctum::actingAs($investor);
        $this->getJson('/api/investor/portal/threads')
            ->assertOk()
            ->assertJsonPath('data.0.unread', 1);

        $this->getJson("/api/investor/portal/threads/{$id}")->assertOk();

        $this->getJson('/api/investor/portal/threads')
            ->assertOk()
            ->assertJsonPath('data.0.unread', 0);
    }

    public function test_a_referenced_document_the_investor_cannot_see_is_dropped(): void
    {
        Mail::fake();
        $fund = Fund::create(['code' => 'AREF-I', 'name' => 'Access Real Estate Fund I']);
        $other = $this->makeInvestor('inv-8003', 'third@example.com', 'Third Investor');

        // Addressed to someone else entirely.
        $document = PortalDocument::create([
            'title' => 'Their Capital Statement',
            'category' => 'financial',
            'scope' => 'investor',
            'investor_id' => $other->id,
            'fund_id' => $fund->id,
            'file_url' => 'portal/their-statement.pdf',
            'mime_type' => 'application/pdf',
        ]);

        $investor = $this->makeInvestor();
        Sanctum::actingAs($investor);

        // Without the visibility check the thread would read back the title of
        // a document belonging to another investor.
        $this->postJson('/api/investor/portal/threads', [
            'subject' => 'About a document',
            'body' => 'Please explain this one.',
            'category' => 'documents',
            'portalDocumentId' => $document->id,
        ])
            ->assertCreated()
            ->assertJsonPath('messages.0.document', null);
    }

    public function test_admin_inbox_puts_threads_awaiting_the_team_first(): void
    {
        Mail::fake();
        $admin = User::factory()->create();
        $a = $this->makeInvestor();
        $b = $this->makeInvestor('inv-8004', 'fourth@example.com', 'Fourth Investor');

        Sanctum::actingAs($a);
        $answered = $this->postJson('/api/investor/portal/threads', [
            'subject' => 'Answered already',
            'body' => 'Older.',
        ])->json('id');

        Sanctum::actingAs($admin);
        $this->postJson("/api/admin/threads/{$answered}/messages", ['body' => 'Replied.'])->assertOk();

        Sanctum::actingAs($b);
        $this->postJson('/api/investor/portal/threads', [
            'subject' => 'Needs an answer',
            'body' => 'Newer.',
        ])->assertCreated();

        Sanctum::actingAs($admin);
        $this->getJson('/api/admin/threads')
            ->assertOk()
            ->assertJsonPath('awaitingTeam', 1)
            ->assertJsonPath('data.0.subject', 'Needs an answer')
            ->assertJsonPath('data.1.subject', 'Answered already');
    }

    public function test_the_author_name_survives_the_account_being_deleted(): void
    {
        Mail::fake();
        $investor = $this->makeInvestor();
        Sanctum::actingAs($investor);

        $this->postJson('/api/investor/portal/threads', [
            'subject' => 'Kept',
            'body' => 'Correspondence about money.',
        ])->assertCreated();

        $admin = User::factory()->create(['name' => 'Casey Ops']);
        $threadId = MessageThread::firstOrFail()->id;
        Sanctum::actingAs($admin);
        $this->postJson("/api/admin/threads/{$threadId}/messages", ['body' => 'From the team.'])->assertOk();

        $admin->delete();

        $message = ThreadMessage::where('author_type', 'admin')->firstOrFail();
        $this->assertSame('Casey Ops', $message->author_name);
    }

    private function makeInvestor(
        string $code = 'inv-8001',
        string $email = 'prefs@example.com',
        string $name = 'Preferences Investor',
    ): Investor {
        return Investor::create([
            'code' => $code,
            'name' => $name,
            'email' => $email,
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
            'investment_fund_name' => 'Access Real Estate Fund I',
            'investment_wallet_status' => 'Active',
            'investment_expected_yield' => '8%',
        ])->refresh();
    }
}
