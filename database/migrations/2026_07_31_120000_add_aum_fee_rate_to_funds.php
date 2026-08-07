<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Annual AUM fee rate, so the portal can disclose it.
 *
 * Annex 3 lists the fee line as "Assets Under Management (AUM) Fees ()" with the
 * rate missing. Disclosure requires three figures — the rate, the amount charged
 * this period, and the cumulative total — and only the rate had nowhere to live.
 *
 * The rate itself is settled: 1% per year, charged quarterly. What remains open
 * is how each investor's share is attributed (units held vs capital invested)
 * and what the fee is charged against (gross assets vs net). Those decide how
 * fund_fee_allocations is computed, not what rate is displayed, so this column
 * is safe ahead of those answers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('funds', function (Blueprint $table) {
            $table->decimal('aum_fee_annual_pct', 6, 3)->default(1.000)->after('current_premium_pct');
        });
    }

    public function down(): void
    {
        Schema::table('funds', function (Blueprint $table) {
            $table->dropColumn('aum_fee_annual_pct');
        });
    }
};
