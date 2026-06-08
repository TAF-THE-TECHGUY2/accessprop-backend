<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('funding_instructions', function (Blueprint $table) {
            $table->string('provider')->nullable()->after('status');
            $table->string('provider_intent_id')->nullable()->after('provider');
            $table->string('provider_client_secret', 500)->nullable()->after('provider_intent_id');
            $table->unsignedBigInteger('amount_cents')->nullable()->after('provider_client_secret');
            $table->string('currency', 3)->nullable()->after('amount_cents');
            $table->json('provider_payload')->nullable()->after('currency');
            $table->index('provider_intent_id');
        });
    }

    public function down(): void
    {
        Schema::table('funding_instructions', function (Blueprint $table) {
            $table->dropIndex(['provider_intent_id']);
            $table->dropColumn([
                'provider',
                'provider_intent_id',
                'provider_client_secret',
                'amount_cents',
                'currency',
                'provider_payload',
            ]);
        });
    }
};
