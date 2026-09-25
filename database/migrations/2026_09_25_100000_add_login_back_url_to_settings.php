<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The "Back" link under the Sign in button.
 *
 * The investor portal lives on its own subdomain, so someone who lands on the
 * sign-in page by mistake has no way back to the main site. This is where that
 * link points, editable in the admin panel alongside the legal links.
 *
 * Nullable on purpose: cleared means no link at all, rather than a link to
 * nowhere. That is the difference between this and the legal URLs, where a
 * blank value falls back to a bundled default because the consent checkboxes
 * must always have somewhere to point.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->string('login_back_url', 2048)
                ->nullable()
                ->default('https://www.ap.boston')
                ->after('privacy_policy_url');
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn('login_back_url');
        });
    }
};
