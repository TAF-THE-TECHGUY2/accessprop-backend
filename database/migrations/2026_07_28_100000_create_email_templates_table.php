<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_templates', function (Blueprint $table) {
            $table->id();
            // Stable identifier the Mailable looks up by, e.g. investor_welcome.
            $table->string('key')->unique();
            $table->string('name');
            $table->string('description', 500)->nullable();
            $table->string('subject');
            $table->longText('body_html');
            $table->longText('body_text')->nullable();
            // Variables the template may use: [{name, description, required}]
            $table->json('variables')->nullable();
            // Falls back to the bundled Blade view when disabled.
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_templates');
    }
};
