<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two-factor sign-in is required for investors unless they decline it, and
 * declining means accepting a stated risk. When they accepted it is kept here;
 * the wording, IP and browser are on the investor's activity log.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('investors', function (Blueprint $table) {
            $table->timestamp('two_factor_opted_out_at')->nullable()->after('two_factor_last_timestep');
        });
    }

    public function down(): void
    {
        Schema::table('investors', function (Blueprint $table) {
            $table->dropColumn('two_factor_opted_out_at');
        });
    }
};
