<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
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
            ->get();

        if ($written->isNotEmpty() && ! env('LEGAL_PAGES_DROP_CONFIRMED')) {
            throw new RuntimeException(sprintf(
                "Refusing to drop legal_pages: %d row(s) hold written content (%s).\n".
                "This text is what investors were shown. Read it, make sure the pages the\n".
                "Settings URLs point at say the same thing, then re-run with:\n\n".
                "    LEGAL_PAGES_DROP_CONFIRMED=1 php artisan migrate --force\n\n".
                'A copy is written to storage/app/legal-pages-backup/ before anything is dropped.',
                $written->count(),
                $written->pluck('slug')->implode(', '),
            ));
        }

        // Confirmed, and there is text: keep a copy on disk. A dropped table is
        // not worth trusting a shell flag over, and if the copy can't be
        // written the drop does not happen.
        if ($written->isNotEmpty()) {
            $this->backup($written);
        }

        Schema::drop('legal_pages');
    }

    /**
     * @param  Collection<int, object>  $rows
     */
    private function backup($rows): void
    {
        $dir = storage_path('app/legal-pages-backup');

        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new RuntimeException("Cannot create {$dir} to back up legal_pages. Not dropping.");
        }

        foreach ($rows as $row) {
            $file = $dir.'/'.$row->slug.'.html';

            if (@file_put_contents($file, (string) $row->body_html) === false) {
                throw new RuntimeException("Cannot write {$file}. Not dropping legal_pages.");
            }

            echo "  saved {$file}\n";
        }
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
