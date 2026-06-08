<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communications', function (Blueprint $table) {
            $table->id();
            $table->string('type')->default('update')->index();   // update | newsletter
            $table->string('title');
            $table->string('summary', 500)->nullable();
            $table->longText('body_html');
            $table->string('audience')->default('all')->index();  // all | accredited | non_accredited | fund:{code}
            $table->boolean('is_published')->default(false)->index();
            $table->timestamp('published_at')->nullable()->index();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communications');
    }
};
