<?php

namespace Tests\Feature;

use App\Mail\InvestorWelcomeMail;
use App\Models\EmailTemplate;
use App\Models\Investor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvestorWelcomeEmailTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A phrase from the superseded letter. Its absence is what proves the old
     * copy is really gone rather than merely shadowed.
     */
    private const OLD_COPY = 'Diversified Income Fund I';

    public function test_welcome_email_sends_the_current_letter(): void
    {
        $body = $this->renderWelcomeEmail();

        $this->assertStringContainsString('thank you for creating your investor account', $body);
        $this->assertStringContainsString('Access Investment Management', $body);
        $this->assertStringContainsString('Founder and Chief Executive Officer', $body);
        $this->assertStringNotContainsString(self::OLD_COPY, $body);
    }

    /**
     * The stored template wins over the Blade view, so a database seeded before
     * this release still holds the old letter. The migration is the only thing
     * that clears it -- EmailTemplateSeeder skips keys that already exist.
     */
    public function test_migration_replaces_a_stored_copy_of_the_old_letter(): void
    {
        EmailTemplate::create([
            'key' => InvestorWelcomeMail::TEMPLATE_KEY,
            'name' => 'Investor welcome',
            'subject' => 'Welcome to Access Properties',
            'body_html' => '<p>Your participation supports Access Properties Real Estate '.self::OLD_COPY.'.</p>',
            'body_text' => 'Your participation supports Access Properties Real Estate '.self::OLD_COPY.'.',
            'is_active' => true,
        ]);

        // Sanity check: without the migration the stale row is what investors get.
        $this->assertStringContainsString(self::OLD_COPY, $this->renderWelcomeEmail());

        $this->runReplacementMigration();

        $body = $this->renderWelcomeEmail();

        $this->assertStringNotContainsString(self::OLD_COPY, $body);
        $this->assertStringContainsString('thank you for creating your investor account', $body);
    }

    public function test_migration_is_a_no_op_when_no_template_is_stored(): void
    {
        $this->runReplacementMigration();

        $this->assertDatabaseCount('email_templates', 0);
        $this->assertStringContainsString(
            'thank you for creating your investor account',
            $this->renderWelcomeEmail(),
        );
    }

    private function renderWelcomeEmail(): string
    {
        $investor = new Investor([
            'code' => 'inv-1003',
            'name' => 'Abel Sample',
            'email' => 'abel.sample@example.com',
        ]);

        return (new InvestorWelcomeMail($investor))->render();
    }

    private function runReplacementMigration(): void
    {
        $migration = require database_path(
            'migrations/2026_09_23_120000_replace_investor_welcome_email_template.php',
        );

        $migration->up();
    }
}
