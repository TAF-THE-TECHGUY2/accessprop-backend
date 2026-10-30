<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The app emails signers itself rather than DocuSign. Each signer of an
 * envelope has one link: a long random token that opens their DocuSign
 * signing session without a sign-in, usable until they have signed or the
 * envelope closes. The token is kept encrypted so reminders can repeat it;
 * lookups go by its hash.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signing_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('signing_envelope_id')->constrained('signing_envelopes')->cascadeOnDelete();
            $table->string('role', 32);
            $table->string('token_hash', 64)->unique();
            $table->text('token');
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('last_emailed_at')->nullable();
            $table->unsignedSmallInteger('reminders_sent')->default(0);
            $table->timestamp('signed_at')->nullable();
            $table->timestamps();

            $table->unique(['signing_envelope_id', 'role']);
        });

        Schema::table('signing_envelopes', function (Blueprint $table) {
            $table->timestamp('completion_emailed_at')->nullable()->after('completed_at');
        });
    }

    public function down(): void
    {
        Schema::table('signing_envelopes', function (Blueprint $table) {
            $table->dropColumn('completion_emailed_at');
        });
        Schema::dropIfExists('signing_links');
    }
};
