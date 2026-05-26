<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('investor_profile_id')->constrained('investors')->cascadeOnDelete();
            $table->string('provider');
            $table->string('type');
            $table->string('status')->index();
            $table->string('external_id')->nullable();
            $table->text('external_url')->nullable();
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_requests');
    }
};
