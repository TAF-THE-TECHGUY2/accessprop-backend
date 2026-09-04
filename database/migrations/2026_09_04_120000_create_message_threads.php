<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two-way secure messaging between an investor and the team.
 *
 * Deliberately not built on investor_messages, which is a write-only log of
 * system notifications: it is written in two places and read nowhere except as
 * a count on the admin investor page. It stays as it is.
 *
 * Three choices worth stating:
 *
 * No status column. "Awaiting response" is derived from whoever wrote last —
 * a stored status has to be maintained by every write path and eventually
 * disagrees with the messages themselves. resolved_at is stored because it is
 * genuinely not derivable, and a new investor message clears it, so an
 * investor is never blocked from following up on something closed.
 *
 * author_name is denormalised and author_id is nullable. This is
 * correspondence about money, so the displayed author has to survive the
 * account being deleted. Authorship also spans two auth models — investors and
 * users — so it cannot be a single foreign key, and a polymorphic one with no
 * FK is what let dangling personal_access_tokens authenticate as whichever
 * investor later took the id. A string discriminator plus a kept name avoids
 * both problems.
 *
 * A call request is a thread with category = call_request rather than its own
 * table: same lifecycle, same audit trail, same inbox.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_threads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('investor_id')->constrained()->cascadeOnDelete();
            $table->string('subject');
            $table->string('category')->default('general')->index(); // general | call_request | documents
            $table->string('opened_by');                             // investor | admin
            $table->timestamp('resolved_at')->nullable();
            $table->unsignedBigInteger('resolved_by')->nullable();
            // Denormalised so the inbox can order threads without a subquery
            // over every message.
            $table->timestamp('last_message_at')->index();
            $table->timestamps();

            $table->index(['investor_id', 'last_message_at']);
        });

        Schema::create('thread_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('thread_id')->constrained('message_threads')->cascadeOnDelete();
            $table->string('author_type');                  // investor | admin
            $table->unsignedBigInteger('author_id')->nullable();
            $table->string('author_name');
            $table->text('body');
            // Lets "Ask about documents" point at a document the investor
            // already has, without an upload path, storage or virus scanning.
            $table->foreignId('portal_document_id')->nullable()
                ->constrained('portal_documents')->nullOnDelete();
            // Read by the recipient, not the author.
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['thread_id', 'created_at']);
            $table->index(['thread_id', 'author_type', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('thread_messages');
        Schema::dropIfExists('message_threads');
    }
};
