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

/** A test send must leave no trace in the app and must not involve the real counter signer. */
class DocusignSendTestCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_needs_both_signers_named(): void
    {
        $this->artisan('docusign:send-test', ['--email' => 'me@example.com'])->assertFailed();
    }

    public function test_it_sends_the_named_template_priced_and_saves_nothing(): void
    {
        $fund = Fund::create(['code' => 'aref-i', 'name' => 'Access Real Estate Fund I', 'status' => 'active']);
        FundUnitPrice::create(['fund_id' => $fund->id, 'as_of_date' => now()->subDay()->toDateString(), 'price' => '14.0945809850']);

        $builder = Mockery::mock(EnvelopeBuilder::class)->makePartial();
        $builder->shouldReceive('sendTestEnvelope')
            ->once()
            ->withArgs(fn ($investor, $template, $counterEmail, $counterName, $withPricing) => $template === 'tpl-w9'
                && $counterEmail === 'second@example.com'
                && $investor->email === 'me@example.com'
                && ! $investor->exists
                && $withPricing === true)
            ->andReturnUsing(fn ($investor) => ['envelopeId' => 'env-test', 'pricing' => (new EnvelopeBuilder(app(\App\Services\DocuSign\Client::class)))->quote($investor)]);
        $this->app->instance(EnvelopeBuilder::class, $builder);

        $this->artisan('docusign:send-test', [
            '--email' => 'me@example.com',
            '--counter-signer-email' => 'second@example.com',
            '--template' => 'tpl-w9',
        ])->expectsOutputToContain('709.4925')->assertSuccessful();

        $this->assertSame(0, Investor::count());
        $this->assertSame(0, SigningEnvelope::count());
    }
}
