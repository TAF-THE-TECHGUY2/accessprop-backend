<?php

namespace Tests\Feature;

use App\Models\Fund;
use App\Models\FundUnitPrice;
use App\Models\Investor;
use App\Models\SigningEnvelope;
use App\Services\DocuSign\EnvelopeBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/** A test send must leave no trace in the app; the counter signer is the configured one. */
class DocusignSendTestCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_needs_a_real_investor_address(): void
    {
        $this->artisan('docusign:send-test', ['--email' => 'YOUR_SECOND_EMAIL'])->assertFailed();
    }

    public function test_it_sends_the_named_template_priced_and_saves_nothing(): void
    {
        config([
            'docusign.counter_signer.email' => 'dion@example.com',
            'docusign.counter_signer.name' => 'Dionysios Kaskarelis',
        ]);
        $fund = Fund::create(['code' => 'aref-i', 'name' => 'Access Real Estate Fund I', 'status' => 'active']);
        FundUnitPrice::create(['fund_id' => $fund->id, 'as_of_date' => now()->subDay()->toDateString(), 'price' => '14.0945809850']);

        $builder = Mockery::mock(EnvelopeBuilder::class)->makePartial();
        $builder->shouldReceive('sendTestEnvelope')
            ->once()
            ->withArgs(fn ($investor, $template, $counterEmail, $counterName, $withPricing) => $template === 'tpl-w9'
                && $counterEmail === 'dion@example.com'
                && $counterName === 'Dionysios Kaskarelis'
                && $investor->email === 'me@example.com'
                && ! $investor->exists
                && $withPricing === true)
            ->andReturnUsing(fn ($investor) => ['envelopeId' => 'env-test', 'pricing' => (new EnvelopeBuilder(app(\App\Services\DocuSign\Client::class)))->quote($investor)]);
        // DocuSign emails nobody in a signing package, so the links are printed.
        $builder->shouldReceive('signingUrl')
            ->once()
            ->withArgs(fn ($envelope, $role) => $role === 'counter_signer' && $envelope->docusign_envelope_id === 'env-test' && ! $envelope->exists)
            ->andReturn('https://docusign.test/sign/dion');
        $this->app->instance(EnvelopeBuilder::class, $builder);

        $this->artisan('docusign:send-test', [
            '--email' => 'me@example.com',
            '--template' => 'tpl-w9',
        ])->expectsOutputToContain('709.4925')
            ->expectsOutputToContain('https://docusign.test/sign/dion')
            ->expectsOutputToContain('--link=env-test --as=investor')
            ->assertSuccessful();

        $this->assertSame(0, Investor::count());
        $this->assertSame(0, SigningEnvelope::count());
    }
}
