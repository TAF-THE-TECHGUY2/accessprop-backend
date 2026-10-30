<?php

namespace App\Console\Commands;

use App\Models\Fund;
use App\Models\Investor;
use App\Services\DocuSign\EnvelopeBuilder;
use Illuminate\Console\Command;

/**
 * Sends a real test envelope without touching the database: the investor is
 * held in memory and no envelope is recorded, so nothing appears in the admin
 * and signing it changes nothing in the app. Both signers are addresses you
 * choose, so nobody is asked to countersign a test.
 */
class DocusignSendTestEnvelope extends Command
{
    protected $signature = 'docusign:send-test
                            {--email= : Who signs as the investor (required)}
                            {--counter-signer-email= : Who signs as the counter signer (required; not the real one)}
                            {--template= : Template ID to test; defaults to the one a real send would use}
                            {--amount=10000 : Investment amount to price}
                            {--non-us : Test as a non-US person (W-8BEN package)}
                            {--name=Test Investor : Investor name to fill in}';

    protected $description = 'Send a test subscription agreement to addresses you choose, recording nothing';

    public function handle(EnvelopeBuilder $builder): int
    {
        $email = (string) $this->option('email');
        $counterEmail = (string) $this->option('counter-signer-email');

        if ($email === '' || $counterEmail === '') {
            $this->error('Give both --email and --counter-signer-email. Use addresses you control: they will be asked to sign.');

            return self::FAILURE;
        }

        $active = Fund::where('status', 'active')->get();
        if ($active->count() !== 1) {
            $this->error('Expected exactly one active fund to price against; found '.$active->count().'.');

            return self::FAILURE;
        }

        $amount = (float) $this->option('amount');
        $usPerson = ! $this->option('non-us');

        // In memory only: never saved.
        $investor = new Investor([
            'code' => 'inv-test',
            'name' => (string) $this->option('name'),
            'email' => $email,
            'country' => $usPerson ? 'United States' : 'United Kingdom',
            'fund_id' => $active->first()->id,
            'accreditation_status' => 'accredited',
            'personal_residency' => $usPerson ? Investor::US_PERSON : Investor::NON_US_PERSON,
            'investment_amount' => $amount,
            'investment_commitment' => $amount,
            'address_line1' => '1 Test Street',
            'address_city' => 'Boston',
            'address_state' => 'MA',
            'address_postal_code' => '02108',
            'address_country' => $usPerson ? 'United States' : 'United Kingdom',
        ]);

        $templateId = (string) ($this->option('template') ?: $builder->resolveTemplateId($investor));
        // A template named outright is one of the new packages under test, so
        // it gets the pricing; otherwise price only if a real send would.
        $withPricing = $this->option('template') !== null || $builder->usesTaxPackages();

        $this->info("Template:        {$templateId}");
        $this->line("Investor signs:  {$email}");
        $this->line("Counter signs:   {$counterEmail}");

        $result = $builder->sendTestEnvelope($investor, $templateId, $counterEmail, 'Test Counter Signer', $withPricing);

        if ($result['pricing']) {
            $p = $result['pricing'];
            $this->line('');
            $this->line('Filled in on the Subscription Agreement:');
            foreach ($p['display'] as $label => $value) {
                $this->line(sprintf('  %-26s %s', $label, $value));
            }
            $this->line("  (Base NAV {$p['base_nav']} as of {$p['base_nav_as_of']}, premium {$p['premium_pct']}%)");
        }

        $this->line('');
        $this->info("Sent. Envelope {$result['envelopeId']}. Nothing was recorded in the app.");
        $this->line('Sign as the investor first; the counter signer gets their email after.');

        return self::SUCCESS;
    }
}
