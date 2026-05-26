<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('investor_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('investor_id')->constrained()->cascadeOnDelete();
            $table->string('code')->unique();
            $table->string('subject');
            $table->string('preview');
            $table->timestamp('sent_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('investor_messages');
    }
};
