<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whole Units only, switched from the admin Settings page. Null until someone
 * saves it there, and until then PRICING_WHOLE_UNITS in .env decides.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->boolean('whole_units_only')->nullable()->after('demo_payments_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn('whole_units_only');
        });
    }
};
