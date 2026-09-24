<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Makes the sender identity ("Access Properties" <hello@mg.ap.boston>,
 * reply-to hello@ap.boston) editable instead of frozen in .env.
 *
 * Two levels, because both are genuinely wanted:
 *
 *  - settings.*        the platform default, used by every email.
 *  - email_templates.* a per-template override; NULL means "inherit". A
 *                      password reset can answer to a different mailbox from a
 *                      welcome letter without duplicating the whole identity.
 *
 * Defaults are the values .env was already sending with, so nothing changes
 * appearance the moment this runs. MAIL_FROM_* stays as the last-resort
 * fallback for a database that cannot be read.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->string('mail_from_name')->default('Access Properties')->after('privacy_policy_url');
            $table->string('mail_from_address')->default('hello@mg.ap.boston')->after('mail_from_name');
            $table->string('mail_reply_to_address')->nullable()->default('hello@ap.boston')->after('mail_from_address');
        });

        Schema::table('email_templates', function (Blueprint $table) {
            // NULL = inherit the platform default above.
            $table->string('from_name')->nullable()->after('subject');
            $table->string('from_address')->nullable()->after('from_name');
            $table->string('reply_to_address')->nullable()->after('from_address');
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn(['mail_from_name', 'mail_from_address', 'mail_reply_to_address']);
        });

        Schema::table('email_templates', function (Blueprint $table) {
            $table->dropColumn(['from_name', 'from_address', 'reply_to_address']);
        });
    }
};
