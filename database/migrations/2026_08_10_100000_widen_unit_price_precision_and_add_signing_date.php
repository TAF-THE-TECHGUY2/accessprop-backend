<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Precision and a second date, both required to reconcile with the fund
 * manager's workbook.
 *
 * PRECISION. Unit price is derived from contribution ÷ units, and those
 * quotients are not round: 404,329.34 ÷ 40,400 = 10.008152. At 4 decimal places
 * that stored as 10.0082 and multiplying back gave 404,331.28 — nearly $2 off,
 * and the reconciliation fails. 8 decimal places leaves headroom well past the
 * 6 the spec asks for.
 *
 * DATES. An investment has two: when the operating agreement / MIPA was signed,
 * and when the deposit landed. They are distinct and can differ by weeks. Every
 * holding-period and annualized-return figure is measured from the DEPOSIT date,
 * which is what transaction_date already holds — so the new column is the
 * signing date, and it is nullable because it is unrecorded throughout the
 * manager's own sample.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fund_transactions', function (Blueprint $table) {
            $table->decimal('price_per_unit', 18, 8)->change();
            $table->decimal('book_value_at_purchase', 18, 8)->change();
            $table->date('date_oa_mipa_signed')->nullable()->after('transaction_date');
        });

        // Published book value is quoted to 4dp by the accountant, but a derived
        // entry price can land anywhere, and unit price history is compared
        // against entry prices.
        Schema::table('fund_unit_prices', function (Blueprint $table) {
            $table->decimal('price', 18, 8)->change();
        });

        // average_unit_price is total contribution ÷ total units, which inherits
        // the same non-round quotient.
        Schema::table('fund_holdings', function (Blueprint $table) {
            $table->decimal('average_unit_price', 18, 8)->change();
        });
    }

    public function down(): void
    {
        Schema::table('fund_holdings', function (Blueprint $table) {
            $table->decimal('average_unit_price', 15, 4)->change();
        });

        Schema::table('fund_unit_prices', function (Blueprint $table) {
            $table->decimal('price', 15, 4)->change();
        });

        Schema::table('fund_transactions', function (Blueprint $table) {
            $table->dropColumn('date_oa_mipa_signed');
            $table->decimal('book_value_at_purchase', 12, 4)->change();
            $table->decimal('price_per_unit', 12, 4)->change();
        });
    }
};
