<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * The guard on the legal_pages drop.
 *
 * It earned its keep on the first production deploy: two rows there held real
 * Terms and Privacy wording that a plain `Schema::drop` would have destroyed
 * without a word.
 */
class DropLegalPagesMigrationTest extends TestCase
{
    use RefreshDatabase;

    private string $backupDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->backupDir = storage_path('app/legal-pages-backup');
        File::deleteDirectory($this->backupDir);

        // RefreshDatabase has already run the drop, so put the table back.
        // down() recreates exactly the shell the create migration seeded.
        $this->migration()->down();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->backupDir);
        putenv('LEGAL_PAGES_DROP_CONFIRMED');

        parent::tearDown();
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_09_24_160000_drop_legal_pages.php');
    }

    private function plantContent(): void
    {
        DB::table('legal_pages')
            ->where('slug', 'terms-of-use')
            ->update(['body_html' => '<h1>Terms</h1><p>Wording an investor agreed to.</p>']);
    }

    public function test_it_refuses_to_drop_a_table_holding_written_content(): void
    {
        $this->plantContent();

        try {
            $this->migration()->up();
            $this->fail('Expected the migration to refuse.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Refusing to drop legal_pages', $e->getMessage());
            // The message has to tell the operator how to proceed, or the
            // refusal is just a wall.
            $this->assertStringContainsString('LEGAL_PAGES_DROP_CONFIRMED=1', $e->getMessage());
        }

        $this->assertTrue(Schema::hasTable('legal_pages'), 'The table must survive a refusal.');
        $this->assertDirectoryDoesNotExist($this->backupDir);
    }

    public function test_confirming_writes_a_backup_before_dropping(): void
    {
        $this->plantContent();
        putenv('LEGAL_PAGES_DROP_CONFIRMED=1');

        $this->migration()->up();

        $this->assertFalse(Schema::hasTable('legal_pages'));
        $this->assertFileExists($this->backupDir.'/terms-of-use.html');
        $this->assertStringContainsString(
            'Wording an investor agreed to.',
            file_get_contents($this->backupDir.'/terms-of-use.html'),
        );
    }

    public function test_an_empty_table_drops_without_ceremony(): void
    {
        // The ordinary case: nobody ever wrote anything, so there is nothing to
        // confirm and nothing to back up.
        $this->migration()->up();

        $this->assertFalse(Schema::hasTable('legal_pages'));
        $this->assertDirectoryDoesNotExist($this->backupDir);
    }
}
