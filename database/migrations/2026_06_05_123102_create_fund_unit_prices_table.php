<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fund_unit_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fund_id')->constrained('funds')->cascadeOnDelete();
            $table->decimal('price', 15, 4);
            $table->date('as_of_date');
            $table->string('quarter_label', 16)->nullable();
            $table->timestamps();

            $table->unique(['fund_id', 'as_of_date']);
            $table->index(['fund_id', 'as_of_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fund_unit_prices');
    }
};
