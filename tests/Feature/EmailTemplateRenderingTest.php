<?php

namespace Tests\Feature;

use App\Models\EmailTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmailTemplateRenderingTest extends TestCase
{
    use RefreshDatabase;

    /** A real Blade file whose name an admin could plausibly type into a field. */
    private string $probe;

    protected function setUp(): void
    {
        parent::setUp();

        $this->probe = resource_path('views/render_guard_probe.blade.php');
        file_put_contents($this->probe, 'CONTENTS OF A REAL VIEW FILE');
    }

    protected function tearDown(): void
    {
        if (is_file($this->probe)) {
            unlink($this->probe);
        }

        parent::tearDown();
    }

    /**
     * Blade::render() resolves a string that matches a view name to that FILE,
     * and deleteCachedView then unlinks it. An admin saving a subject of
     * "welcome" used to render resources/views/welcome.blade.php into the
     * subject line and delete it from disk.
     */
    public function test_a_subject_matching_a_view_name_is_treated_as_text(): void
    {
        $template = new EmailTemplate([
            'subject' => 'render_guard_probe',
            'body_html' => '<p>Hi</p>',
        ]);

        $this->assertSame('render_guard_probe', $template->renderSubject([]));
        $this->assertFileExists($this->probe);
        $this->assertSame('CONTENTS OF A REAL VIEW FILE', file_get_contents($this->probe));
    }

    public function test_a_body_matching_a_view_name_is_treated_as_text(): void
    {
        $template = new EmailTemplate([
            'subject' => 'Subject',
            'body_html' => 'render_guard_probe',
        ]);

        $this->assertSame('render_guard_probe', $template->renderHtml([]));
        $this->assertFileExists($this->probe);
    }

    public function test_the_guard_does_not_alter_ordinary_rendered_output(): void
    {
        $template = new EmailTemplate([
            'subject' => 'Welcome, {{ $firstName }}',
            'body_html' => '<p>Hello {{ $firstName }} &mdash; {{ strtoupper($code) }}</p>',
            'body_text' => 'Hello {{ $firstName }}',
        ]);

        $data = ['firstName' => 'Abel', 'code' => 'inv-1003'];

        // Byte-for-byte: the guard comment must leave no residue.
        $this->assertSame('Welcome, Abel', $template->renderSubject($data));
        $this->assertSame('<p>Hello Abel &mdash; INV-1003</p>', $template->renderHtml($data));
        $this->assertSame('Hello Abel', $template->renderText($data));
    }
}
