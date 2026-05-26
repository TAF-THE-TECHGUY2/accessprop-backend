<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('organization_name');
            $table->string('api_environment');
            $table->unsignedInteger('review_sla_hours');
            $table->boolean('notify_on_submission')->default(true);
            $table->boolean('notify_on_funding')->default(true);
            $table->boolean('auto_activate_dashboard')->default(false);
            $table->string('support_email');
            $table->string('default_country');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
