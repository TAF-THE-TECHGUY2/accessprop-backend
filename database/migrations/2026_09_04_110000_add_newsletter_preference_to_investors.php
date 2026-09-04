<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The investor's newsletter preference.
 *
 * Onboarding has always asked for this — the registration endpoint validates a
 * `receiveUpdates` boolean — and then discarded it, because there was nowhere
 * to put it. So this is not a new question, it is the answer finally being
 * kept.
 *
 * Defaults to true for existing rows. That is the state the portal has
 * effectively been in: everyone receives updates today, so defaulting to false
 * would silently unsubscribe the whole book on deploy. New registrations get
 * whatever the investor actually chose.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('investors', function (Blueprint $table) {
            $table->boolean('newsletter_opted_in')->default(true)->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('investors', function (Blueprint $table) {
            $table->dropColumn('newsletter_opted_in');
        });
    }
};
