<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('funding_instructions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('investor_profile_id')->constrained('investors')->cascadeOnDelete();
            $table->string('status')->default('draft')->index();
            $table->text('instructions')->nullable();
            $table->text('delivery_channel')->nullable();
            $table->text('external_url')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('funding_instructions');
    }
};
