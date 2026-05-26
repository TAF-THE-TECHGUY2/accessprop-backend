<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('investor_activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('investor_profile_id')->constrained('investors')->cascadeOnDelete();
            $table->string('category')->default('processing');
            $table->string('action');
            $table->string('title');
            $table->text('description');
            $table->json('metadata')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('investor_activity_logs');
    }
};
