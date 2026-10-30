<?php

use Database\Seeders\EmailTemplateSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the signing emails the app now sends instead of DocuSign's, so they are
 * editable in the admin. Only these keys, and only if missing: every other
 * template, and any admin edits, is left as it is.
 */
return new class extends Migration
{
    private const KEYS = ['agreement_countersign_request', 'agreement_sign_request', 'agreement_completed'];

    public function up(): void
    {
        if (Schema::hasTable('email_templates')) {
            (new EmailTemplateSeeder)->seed(self::KEYS);
        }
    }

    public function down(): void
    {
        \Illuminate\Support\Facades\DB::table('email_templates')->whereIn('key', self::KEYS)->delete();
    }
};
