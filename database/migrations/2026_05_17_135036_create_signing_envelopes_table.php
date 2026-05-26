<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signing_envelopes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('investor_id')->constrained('investors')->cascadeOnDelete();
            $table->string('docusign_envelope_id')->unique();
            $table->string('template_id');
            $table->string('type')->default('subscription_agreement')->index();
            $table->string('status')->index();
            $table->string('investor_email');
            $table->string('investor_name');
            $table->string('counter_signer_email')->nullable();
            $table->string('counter_signer_name')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->text('decline_reason')->nullable();
            $table->string('signed_document_disk')->nullable();
            $table->string('signed_document_path')->nullable();
            $table->json('last_event_payload')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signing_envelopes');
    }
};
