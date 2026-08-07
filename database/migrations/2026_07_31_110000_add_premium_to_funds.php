<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-issuance premium over book value.
 *
 * Two places deliberately, not one:
 *
 *   funds.current_premium_pct       — the live setting new subscriptions use
 *   fund_transactions.premium_pct   — frozen at purchase, never updated
 *
 * Reading the premium back off the fund at display time would silently rewrite
 * every historical entry price the moment the rate changes. The transaction is
 * the record; the fund is only the source for the next subscription.
 *
 * fund_premium_changes exists because this is a pricing decision with money
 * attached — when two investors who subscribed weeks apart paid different
 * prices, the audit trail is what answers why.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('funds', function (Blueprint $table) {
            // Signed: a discount to book value is legitimate, and was plausibly
            // used at inception to compensate early investors for higher risk.
            $table->decimal('current_premium_pct', 6, 3)->default(0)->after('minimum_investment');
        });

        Schema::create('fund_premium_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fund_id')->constrained('funds')->cascadeOnDelete();
            $table->decimal('old_premium_pct', 6, 3)->nullable();
            $table->decimal('new_premium_pct', 6, 3);
            $table->string('reason')->nullable();
            $table->unsignedBigInteger('changed_by')->nullable();
            $table->timestamp('changed_at');
            $table->timestamps();

            $table->index(['fund_id', 'changed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fund_premium_changes');

        Schema::table('funds', function (Blueprint $table) {
            $table->dropColumn('current_premium_pct');
        });
    }
};
