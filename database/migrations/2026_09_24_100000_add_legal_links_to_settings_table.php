<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Terms of Use and Privacy Policy links shown on the create-account page.
 *
 * These live on the marketing site, not in this app, so they were hardcoded in
 * the React bundle. Stored settings instead: legal pages get renamed, moved
 * behind a new path, or replaced by counsel, and none of that should need a
 * frontend redeploy.
 *
 * The defaults are the URLs the bundle shipped with, so the link keeps working
 * on an existing database the moment this runs. Note the `www` host -- the
 * apex only redirects `/`, so an apex deep link 404s.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->string('terms_of_use_url', 2048)
                ->default('https://www.ap.boston/terms-of-use')
                ->after('support_email');
            $table->string('privacy_policy_url', 2048)
                ->default('https://www.ap.boston/privacy-policy')
                ->after('terms_of_use_url');
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn(['terms_of_use_url', 'privacy_policy_url']);
        });
    }
};
