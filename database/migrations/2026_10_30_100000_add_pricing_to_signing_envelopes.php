<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The transaction terms an agreement was sent with: Base NAV, premium, price
 * per Unit, amount and Units. The Subscription Agreement presents these for
 * signature and the Valuation and Unit Pricing Policy requires them recorded,
 * so they are kept exactly as quoted rather than recomputed later from a NAV
 * that may have moved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('signing_envelopes', function (Blueprint $table) {
            $table->json('pricing')->nullable()->after('template_id');
        });
    }

    public function down(): void
    {
        Schema::table('signing_envelopes', function (Blueprint $table) {
            $table->dropColumn('pricing');
        });
    }
};
