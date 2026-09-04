<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The accountant's quarterly fee figure, and the allocations derived from it.
 *
 * The fund manager's instruction: the accountant computes the fee from the
 * fund's gross asset value after quarter end, that number is entered by hand,
 * and the website divides it pro rata by ownership for the quarter without
 * recomputing or overriding it. So the total is stored as declared, and each
 * investor's share points back at it.
 *
 * Storing the declaration rather than only the per-investor rows buys three
 * things: the investor can be shown their share of a stated fund total instead
 * of a bare number, a changed holding can be re-allocated from the same
 * declared figure, and the unique key below makes charging the same quarter
 * twice impossible rather than merely unlikely — which matters when the figure
 * arrives by hand, once a quarter, from outside the system.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fund_fee_declarations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fund_id')->constrained()->cascadeOnDelete();
            $table->string('fee_type')->index();            // aum | performance
            $table->date('period_start');
            $table->date('period_end');
            // Exactly as the accountant supplied it. Never derived here.
            $table->decimal('total_amount', 18, 2);
            // Optional context: what the fee was charged on. Recorded for the
            // audit trail, never used to recompute the total.
            $table->decimal('gross_asset_value', 18, 2)->nullable();
            // How ownership was measured, so a later change to the method is
            // visible on the historical rows rather than silently retroactive.
            $table->string('basis')->default('time_weighted_units');
            $table->string('notes')->nullable();
            $table->unsignedBigInteger('declared_by')->nullable();
            $table->timestamps();

            $table->unique(['fund_id', 'fee_type', 'period_start', 'period_end'], 'fund_fee_period_unique');
        });

        Schema::table('fund_fees', function (Blueprint $table) {
            $table->foreignId('fee_declaration_id')->nullable()->after('id')
                ->constrained('fund_fee_declarations')->cascadeOnDelete();
            // The share this allocation was computed from, kept so an investor
            // can be told why their figure is what it is.
            $table->decimal('ownership_pct', 12, 6)->nullable()->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('fund_fees', function (Blueprint $table) {
            $table->dropForeign(['fee_declaration_id']);
            $table->dropColumn(['fee_declaration_id', 'ownership_pct']);
        });

        Schema::dropIfExists('fund_fee_declarations');
    }
};
