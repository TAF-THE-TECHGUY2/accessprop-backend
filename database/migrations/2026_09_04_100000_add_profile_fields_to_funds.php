<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Descriptive fields the portal's fund card shows: a one-line tagline under the
 * name, and the Investment Focus and Market attribute rows.
 *
 * All nullable with no backfill. The portal omits an attribute row whose value
 * is null rather than rendering a dash, so a fund nobody has described yet
 * shows nothing instead of an em dash that reads as "this fund has no market".
 *
 * `description` already exists and holds long-form prose used elsewhere;
 * `tagline` is deliberately separate rather than reusing it, because the card
 * needs a single line and truncating prose to fit produces a sentence fragment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('funds', function (Blueprint $table) {
            $table->string('tagline')->nullable()->after('name');
            $table->string('investment_focus')->nullable()->after('description');
            $table->string('market')->nullable()->after('investment_focus');
        });
    }

    public function down(): void
    {
        Schema::table('funds', function (Blueprint $table) {
            $table->dropColumn(['tagline', 'investment_focus', 'market']);
        });
    }
};
