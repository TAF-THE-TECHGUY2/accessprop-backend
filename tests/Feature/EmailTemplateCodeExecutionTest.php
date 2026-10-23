<?php

namespace Tests\Feature;

use App\Models\EmailTemplate;
use App\Models\Investor;
use App\Models\User;
use App\Support\SafeTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Admin-edited templates are filled in, never compiled. Through Blade, any
 * `{{ expr }}` was PHP, and the preview endpoint ran it on the server.
 */
class EmailTemplateCodeExecutionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::factory()->create());

        EmailTemplate::create([
            'key' => 'investor_welcome',
            'name' => 'Investor welcome',
            'subject' => 'Welcome',
            'body_html' => '<p>Hi {{ $firstName }}</p>',
            'is_active' => true,
        ]);
    }

    public static function payloads(): array
    {
        return [
            'function call' => ['{{ php_uname("s") }}'],
            'system' => ['{{ system("id") }}'],
            'raw echo' => ['{!! file_get_contents(base_path(".env")) !!}'],
            'if directive' => ['@if(true){{ $firstName }}@endif'],
            'foreach directive' => ['@foreach(scandir("/") as $f){{ $f }}@endforeach'],
            'php directive' => ['@php echo 1; @endphp'],
            'method on variable' => ['{{ $investor->delete() }}'],
            'hidden attribute' => ['{{ $investor->password }}'],
            'two-factor secret' => ['{{ $investor->two_factor_secret }}'],
            'unknown function' => ['{{ shell_exec($firstName) }}'],
        ];
    }

    #[DataProvider('payloads')]
    public function test_the_preview_refuses_anything_but_plain_variables(string $payload): void
    {
        $this->postJson('/api/admin/email-templates/investor_welcome/preview', [
            'bodyHtml' => "<p>{$payload}</p>",
        ])->assertStatus(422);
    }

    #[DataProvider('payloads')]
    public function test_saving_refuses_it_too(string $payload): void
    {
        $this->putJson('/api/admin/email-templates/investor_welcome', [
            'subject' => 'Welcome',
            'bodyHtml' => "<p>{$payload}</p>",
        ])->assertStatus(422);
    }

    #[DataProvider('payloads')]
    public function test_a_stored_template_holding_it_does_not_run(string $payload): void
    {
        $this->expectException(InvalidArgumentException::class);

        SafeTemplate::render($payload, [
            'firstName' => 'Ada',
            'investor' => new Investor(['email' => 'ada@example.com', 'password' => 'x']),
        ]);
    }

    public function test_the_syntax_templates_actually_use_still_renders(): void
    {
        $out = SafeTemplate::render(
            'Hi {{ $firstName }} ({{ strtoupper($investorCode) }}) {{ $investor->email }}{{-- gone --}}',
            [
                'firstName' => '<b>Ada</b>',
                'investorCode' => 'inv-1006',
                'investor' => new Investor(['email' => 'ada@example.com']),
            ],
        );

        $this->assertSame('Hi &lt;b&gt;Ada&lt;/b&gt; (INV-1006) ada@example.com', $out);
    }

    public function test_an_email_address_in_the_copy_is_not_mistaken_for_a_directive(): void
    {
        $this->assertSame(
            'Write to hello@ap.boston or support@include.example.',
            SafeTemplate::render('Write to hello@ap.boston or support@include.example.', []),
        );
    }
}
