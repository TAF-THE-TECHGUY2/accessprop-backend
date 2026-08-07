<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Demo payment mode.
 *
 * Lets the funding step complete without Stripe, so the product can be shown
 * end to end — units minted, holdings built, portal populated — on an
 * environment with no payment credentials and no real money moving.
 *
 * Defaults to false and is deliberately a stored setting rather than an env
 * flag: it must be visible and auditable in the admin UI, because switching it
 * on means the platform will record capital that was never received.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->boolean('demo_payments_enabled')->default(false)->after('allow_parallel_onboarding');
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn('demo_payments_enabled');
        });
    }
};
