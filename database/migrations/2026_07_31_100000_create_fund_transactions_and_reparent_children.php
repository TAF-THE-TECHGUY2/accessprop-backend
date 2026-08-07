<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Introduces the fund_transactions ledger (Annex 3) and removes the structural
 * hazard that blocks it.
 *
 * Three changes, deliberately in one migration because they are only safe together:
 *
 *  1. fund_transactions — the new source of truth for positions. fund_holdings
 *     becomes a derived read cache recomputed from this table.
 *
 *  2. distributions and fund_fees are reparented from fund_holding_id to
 *     (fund_id, investor_id). Today both cascade-delete from fund_holdings; once
 *     that table is rebuildable, a rebuild would silently destroy financial
 *     history. Reparenting removes the dependency before the ledger lands.
 *
 *     NOTE: this is an interim safe home, not the final shape. Per Annex 3, fees
 *     are charged at fund level and paid by the fund, with each investor seeing an
 *     attributable share. fund_fees will move again to a fund-level table plus a
 *     fund_fee_allocations snapshot once the attribution basis (units vs capital)
 *     and the fee denominator (total AUM vs investor capital) are confirmed.
 *
 *  3. investors.fund_id — the write path currently picks a fund by string-matching
 *     investors.investment_fund_name and falling back to "first active fund".
 *     That guess is replaced with an explicit FK, resolved once here where a bad
 *     match is visible and correctable rather than silently at runtime.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── 1. The ledger ────────────────────────────────────────────────────
        Schema::create('fund_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('investor_id')->constrained('investors')->cascadeOnDelete();
            $table->foreignId('fund_id')->constrained('funds')->cascadeOnDelete();
            $table->date('transaction_date');

            // subscription  — new capital in
            // reinvestment  — distribution converted to units (mints at its own price)
            // redemption    — units out; `units` is negative
            // adjustment    — corrections, splits, manual reconciliation
            $table->enum('type', ['subscription', 'reinvestment', 'redemption', 'adjustment']);

            // Signed: negative for redemptions.
            $table->decimal('units', 18, 6);

            // Book value at the moment of purchase — (assets + income − expenses
            // − liabilities) ÷ units issued. Published quarterly by the accountant.
            $table->decimal('book_value_at_purchase', 12, 4);

            // Premium over book value for this issuance. Varies per issuance and
            // may be a discount. Null where none was captured.
            $table->decimal('premium_pct', 6, 3)->nullable();

            // Stored, never derived on read: historical rounding stays fixed and
            // the audit trail survives a retroactive premium policy change.
            $table->decimal('price_per_unit', 12, 4);

            $table->decimal('gross_amount', 14, 2);

            // Provenance: stripe payment intent id, docusign envelope, admin
            // override, crowdfund import, backfill.
            $table->string('source')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['investor_id', 'fund_id']);
            $table->index(['fund_id', 'transaction_date']);
        });

        // ── 2. investors.fund_id ─────────────────────────────────────────────
        Schema::table('investors', function (Blueprint $table) {
            $table->unsignedBigInteger('fund_id')->nullable()->after('code');
        });

        // Resolve the existing name string to a real fund, once. Left null where
        // no exact match exists — the write path throws on null rather than
        // guessing, which surfaces the problem instead of burying it.
        DB::statement("
            UPDATE investors i
            JOIN funds f ON f.name = i.investment_fund_name
            SET i.fund_id = f.id
        ");

        Schema::table('investors', function (Blueprint $table) {
            $table->foreign('fund_id')->references('id')->on('funds')->nullOnDelete();
            $table->index('fund_id');
        });

        // ── 3. Reparent distributions ────────────────────────────────────────
        // Columns are added bare, backfilled, then constrained. Adding the FK up
        // front would reject the nullable intermediate state on MySQL.
        Schema::table('distributions', function (Blueprint $table) {
            $table->unsignedBigInteger('fund_id')->nullable()->after('id');
            $table->unsignedBigInteger('investor_id')->nullable()->after('fund_id');
        });

        DB::statement("
            UPDATE distributions d
            JOIN fund_holdings h ON h.id = d.fund_holding_id
            SET d.fund_id = h.fund_id, d.investor_id = h.investor_id
        ");

        // fund_holding_id was NOT NULL with an FK, so every row necessarily
        // joined — there can be no unresolved rows here.
        //
        // Order matters: the (fund_holding_id, paid_at) composite index is what
        // satisfies the foreign key, and MySQL refuses to drop an index a FK
        // depends on. Drop the constraint, then the index, then the column.
        Schema::table('distributions', function (Blueprint $table) {
            $table->dropForeign(['fund_holding_id']);
            $table->dropIndex('distributions_fund_holding_id_paid_at_index');
            $table->dropColumn('fund_holding_id');
        });

        Schema::table('distributions', function (Blueprint $table) {
            $table->unsignedBigInteger('fund_id')->nullable(false)->change();
            $table->unsignedBigInteger('investor_id')->nullable(false)->change();
            $table->foreign('fund_id')->references('id')->on('funds')->cascadeOnDelete();
            $table->foreign('investor_id')->references('id')->on('investors')->cascadeOnDelete();
            $table->index(['fund_id', 'investor_id', 'paid_at']);
        });

        // ── 4. Reparent fund_fees ────────────────────────────────────────────
        Schema::table('fund_fees', function (Blueprint $table) {
            $table->unsignedBigInteger('fund_id')->nullable()->after('id');
            $table->unsignedBigInteger('investor_id')->nullable()->after('fund_id');
        });

        DB::statement("
            UPDATE fund_fees ff
            JOIN fund_holdings h ON h.id = ff.fund_holding_id
            SET ff.fund_id = h.fund_id, ff.investor_id = h.investor_id
        ");

        // Same ordering rule as distributions above.
        Schema::table('fund_fees', function (Blueprint $table) {
            $table->dropForeign(['fund_holding_id']);
            $table->dropColumn('fund_holding_id');
        });

        Schema::table('fund_fees', function (Blueprint $table) {
            $table->unsignedBigInteger('fund_id')->nullable(false)->change();
            $table->unsignedBigInteger('investor_id')->nullable(false)->change();
            $table->foreign('fund_id')->references('id')->on('funds')->cascadeOnDelete();
            $table->foreign('investor_id')->references('id')->on('investors')->cascadeOnDelete();
            $table->index(['fund_id', 'investor_id', 'period_end']);
        });
    }

    public function down(): void
    {
        // Reparent fund_fees back onto fund_holdings.
        Schema::table('fund_fees', function (Blueprint $table) {
            $table->unsignedBigInteger('fund_holding_id')->nullable()->after('id');
        });
        DB::statement("
            UPDATE fund_fees ff
            JOIN fund_holdings h ON h.fund_id = ff.fund_id AND h.investor_id = ff.investor_id
            SET ff.fund_holding_id = h.id
        ");
        // Rows whose holding no longer exists cannot be reparented back.
        DB::statement('DELETE FROM fund_fees WHERE fund_holding_id IS NULL');
        Schema::table('fund_fees', function (Blueprint $table) {
            $table->dropForeign(['fund_id']);
            $table->dropForeign(['investor_id']);
            $table->dropIndex(['fund_id', 'investor_id', 'period_end']);
            $table->dropColumn(['fund_id', 'investor_id']);
            $table->unsignedBigInteger('fund_holding_id')->nullable(false)->change();
            $table->foreign('fund_holding_id')->references('id')->on('fund_holdings')->cascadeOnDelete();
        });

        // Reparent distributions back onto fund_holdings.
        Schema::table('distributions', function (Blueprint $table) {
            $table->unsignedBigInteger('fund_holding_id')->nullable()->after('id');
        });
        DB::statement("
            UPDATE distributions d
            JOIN fund_holdings h ON h.fund_id = d.fund_id AND h.investor_id = d.investor_id
            SET d.fund_holding_id = h.id
        ");
        DB::statement('DELETE FROM distributions WHERE fund_holding_id IS NULL');
        Schema::table('distributions', function (Blueprint $table) {
            $table->dropForeign(['fund_id']);
            $table->dropForeign(['investor_id']);
            $table->dropIndex(['fund_id', 'investor_id', 'paid_at']);
            $table->dropColumn(['fund_id', 'investor_id']);
            $table->unsignedBigInteger('fund_holding_id')->nullable(false)->change();
            $table->foreign('fund_holding_id')->references('id')->on('fund_holdings')->cascadeOnDelete();
            $table->index(['fund_holding_id', 'paid_at']);
        });

        Schema::table('investors', function (Blueprint $table) {
            $table->dropForeign(['fund_id']);
            $table->dropIndex(['fund_id']);
            $table->dropColumn('fund_id');
        });

        Schema::dropIfExists('fund_transactions');
    }
};
