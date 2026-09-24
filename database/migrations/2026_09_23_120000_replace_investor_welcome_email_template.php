<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Replaces the stored investor welcome email with the rewritten letter.
 *
 * Editing the Blade views alone is not enough. UsesEmailTemplate prefers the
 * active email_templates row and only falls back to the bundled view when no
 * row exists, so every environment that has run EmailTemplateSeeder still holds
 * a verbatim copy of the superseded letter and would keep sending it through
 * Mailgun. EmailTemplateSeeder cannot fix that either -- it skips keys that
 * already exist so admin edits survive re-runs.
 *
 * The replacement copy is read from the Blade files bundled with this release,
 * which is the same source the admin "reset to default" action uses, so the
 * stored row and the fallback view cannot drift apart.
 *
 * This deliberately discards any admin edits made to this template: the old
 * letter named a fund that no longer exists ("Access Properties Real Estate
 * Diversified Income Fund I") and the wrong officer title, so keeping a local
 * edit would mean keeping the wrong text.
 */
return new class extends Migration
{
    private const KEY = 'investor_welcome';

    private const SUBJECT = 'Welcome to Access Properties';

    public function up(): void
    {
        if (! Schema::hasTable('email_templates')) {
            return;
        }

        $html = $this->bladeSource('investor-welcome');
        $text = $this->bladeSource('investor-welcome-text');

        // An empty read would blank the live template, which is worse than
        // leaving the old copy in place for an operator to notice.
        if ($html === '') {
            echo "  skipped: resources/views/emails/investor-welcome.blade.php is missing or empty\n";

            return;
        }

        $updated = DB::table('email_templates')
            ->where('key', self::KEY)
            ->update([
                'subject' => self::SUBJECT,
                'body_html' => $html,
                'body_text' => $text,
                'is_active' => true,
                'updated_at' => now(),
            ]);

        if ($updated === 0) {
            // No row yet (fresh database). EmailTemplateSeeder will create one
            // from the same Blade files, so there is nothing to overwrite.
            echo "  no stored investor_welcome template to replace\n";

            return;
        }

        echo "  replaced stored investor_welcome template\n";
    }

    public function down(): void
    {
        // The superseded letter is not recoverable -- it was removed from the
        // Blade views in the same change, so there is no earlier text to write
        // back. Rolling back leaves the new letter in place.
    }

    private function bladeSource(string $name): string
    {
        $path = resource_path("views/emails/{$name}.blade.php");

        return is_file($path) ? (string) file_get_contents($path) : '';
    }
};
