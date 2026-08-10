<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * book_value_at_purchase becomes nullable.
 *
 * When units purchased is the input, the entry price is contribution ÷ units and
 * no published book value is needed to record the position. The fund manager's
 * own sample includes a deposit dated before the first published quarter, so
 * requiring one made a legitimate historical entry impossible.
 *
 * Null now means what it should: no book value was published at that date, so
 * there is no premium to compute against. Recording the derived price in this
 * column instead would assert a valuation the accountant never issued.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fund_transactions', function (Blueprint $table) {
            $table->decimal('book_value_at_purchase', 18, 8)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('fund_transactions', function (Blueprint $table) {
            $table->decimal('book_value_at_purchase', 18, 8)->nullable(false)->change();
        });
    }
};
