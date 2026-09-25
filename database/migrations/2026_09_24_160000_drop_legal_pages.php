<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Drops the hosted legal pages.
 *
 * Terms of Use and Privacy Policy go back to being links: the documents are
 * owned outside this app, and keeping an editable copy here invited a second,
 * quietly diverging version of text investors are agreeing to. The URLs on
 * `settings` are now the only source, edited under Settings -> Legal links.
 *
 * Nothing is lost by dropping the table: the two rows the create migration
 * seeded were never given a body and were never published, so the app has
 * always served the external link. The `up` re-checks that at run time rather
 * than trusting this note, and refuses on a database where someone did write
 * something.
 *
 * `down` recreates the empty shell so a rollback leaves a schema the previous
 * release can boot against. It cannot bring back content, but there is none.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('legal_pages')) {
            return;
        }

        $written = DB::table('legal_pages')
            ->whereNotNull('body_html')
            ->where('body_html', '!=', '')
            ->count();

        if ($written > 0) {
            throw new RuntimeException(
                "Refusing to drop legal_pages: {$written} row(s) hold written content. ".
                'Export it first, then re-run.'
            );
        }

        Schema::drop('legal_pages');
    }

    public function down(): void
    {
        if (Schema::hasTable('legal_pages')) {
            return;
        }

        Schema::create('legal_pages', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('title');
            $table->longText('body_html')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });

        $now = now();
        foreach ([
            ['terms-of-use', 'Terms of Use'],
            ['privacy-policy', 'Privacy Policy'],
        ] as [$slug, $title]) {
            DB::table('legal_pages')->insert([
                'slug' => $slug,
                'title' => $title,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
};
