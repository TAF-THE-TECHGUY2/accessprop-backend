<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fund_fees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fund_holding_id')->constrained('fund_holdings')->cascadeOnDelete();
            $table->string('fee_type')->index();
            $table->decimal('amount', 15, 2);
            $table->date('period_start');
            $table->date('period_end');
            $table->text('description')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fund_fees');
    }
};
