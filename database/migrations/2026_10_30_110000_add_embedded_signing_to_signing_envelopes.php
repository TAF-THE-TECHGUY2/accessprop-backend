<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Envelopes whose signers were flagged as signing through the app, so a
 * signing link can be generated for them on demand. They still get DocuSign's
 * email; envelopes sent before this have no links.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('signing_envelopes', function (Blueprint $table) {
            $table->boolean('embedded_signing')->default(false)->after('pricing');
        });
    }

    public function down(): void
    {
        Schema::table('signing_envelopes', function (Blueprint $table) {
            $table->dropColumn('embedded_signing');
        });
    }
};
