<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Terms of Use and Privacy Policy as pages this app owns and renders.
 *
 * They were links to the marketing site, which meant counsel's wording lived
 * somewhere this app could not see and a change needed someone with access to
 * a different system. Held here, an admin edits them in place.
 *
 * The URL columns on `settings` stay and become the fallback: a page with no
 * body falls back to its external link, so nothing breaks before anyone has
 * written the content, and a fund that would rather host these elsewhere can
 * simply leave the body empty.
 *
 * published_at is separate from the body so a draft can be saved without
 * putting it in front of investors mid-edit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legal_pages', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();      // terms-of-use | privacy-policy
            $table->string('title');
            $table->longText('body_html')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });

        // The two the onboarding checkboxes reference. Created empty, so both
        // fall back to their external links until someone writes them.
        $now = now();
        foreach ([
            ['terms-of-use', 'Terms of Use'],
            ['privacy-policy', 'Privacy Policy'],
        ] as [$slug, $title]) {
            DB::table('legal_pages')->insert([
                'slug' => $slug,
                'title' => $title,
                'body_html' => null,
                'published_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_pages');
    }
};
