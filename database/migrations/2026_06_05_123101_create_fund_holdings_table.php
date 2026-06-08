<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fund_holdings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('investor_id')->constrained('investors')->cascadeOnDelete();
            $table->foreignId('fund_id')->constrained('funds')->cascadeOnDelete();
            $table->decimal('units', 18, 6)->default(0);
            $table->decimal('amount_invested', 15, 2)->default(0);
            $table->decimal('average_unit_price', 15, 4)->default(0);
            $table->timestamp('first_invested_at')->nullable();
            $table->timestamps();

            $table->unique(['investor_id', 'fund_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fund_holdings');
    }
};
