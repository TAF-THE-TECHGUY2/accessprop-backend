<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('investor_profile_id')->constrained('investors')->cascadeOnDelete();
            $table->string('partner_name')->default('Crowdfunding Partner');
            $table->string('partner_reference_id')->nullable();
            $table->string('status')->default('pending')->index();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('matched_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_matches');
    }
};
