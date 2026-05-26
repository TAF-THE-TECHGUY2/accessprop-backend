<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('investor_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('investor_id')->constrained()->cascadeOnDelete();
            $table->string('code')->unique();
            $table->text('body');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('investor_notes');
    }
};
