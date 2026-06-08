<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('funds', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('fund_type')->default('Diversified Income');
            $table->string('status')->default('active')->index();
            $table->date('inception_date')->nullable();
            $table->string('target_yield')->nullable();
            $table->decimal('minimum_investment', 15, 2)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('funds');
    }
};
