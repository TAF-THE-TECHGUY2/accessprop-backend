<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('investor_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('investor_id')->constrained()->cascadeOnDelete();
            $table->string('code')->unique();
            $table->string('type');
            $table->string('file_name');
            $table->timestamp('submitted_at');
            $table->string('status')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('investor_documents');
    }
};
