<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portal_documents', function (Blueprint $table) {
            $table->id();
            // scope: investor (per-investor doc like K1), fund (shared per fund), global (visible to all)
            $table->string('scope')->default('fund')->index();
            $table->foreignId('investor_id')->nullable()->constrained('investors')->cascadeOnDelete();
            $table->foreignId('fund_id')->nullable()->constrained('funds')->cascadeOnDelete();
            $table->string('category')->index();  // legal | operational | tax | financial
            $table->string('subcategory')->nullable();  // e.g. "Operating Agreement"
            $table->string('title');
            $table->string('file_url', 1000);
            $table->unsignedBigInteger('file_size_bytes')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->timestamp('document_dated_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_documents');
    }
};
