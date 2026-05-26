<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_confirmations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('investor_profile_id')->constrained('investors')->cascadeOnDelete();
            $table->string('status')->default('pending')->index();
            $table->decimal('amount', 15, 2)->default(0);
            $table->string('reference')->nullable();
            $table->text('proof_file_url')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_confirmations');
    }
};
