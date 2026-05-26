<?php

namespace App\Console\Commands;

use App\Models\Investor;
use App\Services\DocuSign\EnvelopeBuilder;
use Illuminate\Console\Command;

class DocusignSendTestEnvelope extends Command
{
    protected $signature = 'docusign:send-test
                            {--email= : Override investor email (otherwise uses --investor or fake fixture)}
                            {--investor= : Investor code or ID to send to (otherwise creates an in-memory fake)}';

    protected $description = 'Send a test DocuSign envelope for the subscription agreement';

    public function handle(EnvelopeBuilder $builder): int
    {
        $investor = $this->resolveInvestor();

        $this->info("Sending subscription agreement envelope to: {$investor->email} ({$investor->name})");
        $this->line("  Investor ID: {$investor->id}");
        $this->line("  Accreditation: {$investor->accreditation_status}");

        $envelope = $builder->sendSubscriptionAgreement($investor);

        $this->line('');
        $this->info('✅ Envelope sent.');
        $this->line("  DocuSign Envelope ID: {$envelope->docusign_envelope_id}");
        $this->line("  Status: {$envelope->status}");
        $this->line("  DB row ID: {$envelope->id}");
        $this->line('');
        $this->line('Check the investor inbox for the DocuSign signing email.');

        return self::SUCCESS;
    }

    private function resolveInvestor(): Investor
    {
        $identifier = $this->option('investor');

        if ($identifier !== null && $identifier !== '') {
            $investor = is_numeric($identifier)
                ? Investor::find((int) $identifier)
                : Investor::where('code', $identifier)->first();

            if (! $investor) {
                $this->error("Investor not found: {$identifier}");

                exit(1);
            }

            if ($emailOverride = $this->option('email')) {
                $investor->email = $emailOverride;
            }

            return $investor;
        }

        // Find or create a fake test investor (smoke testing without registering)
        $email = $this->option('email') ?: 'tafara@modus10.co.za';

        $investor = Investor::firstWhere('email', $email);

        if ($investor) {
            return $investor;
        }

        $this->warn("No investor exists with email {$email}. Creating a test investor record.");

        return Investor::create([
            'code' => 'inv-test-'.now()->timestamp,
            'name' => 'Tafara Smoke Test',
            'email' => $email,
            'password' => 'smoke-test-only',
            'phone' => null,
            'country' => 'South Africa',
            'joined_at' => now(),
            'investment_amount' => 25000,
            'accreditation_status' => 'non_accredited',
            'kyc_status' => 'pending',
            'accreditation_verification_status' => 'not_started',
            'investment_status' => 'awaiting_kyc',
            'dashboard_status' => 'pending',
            'document_signing_status' => 'not_started',
            'address_line1' => '123 Test Street',
            'address_line2' => null,
            'address_city' => 'Cape Town',
            'address_state' => 'WC',
            'address_postal_code' => '8001',
            'address_country' => 'South Africa',
            'personal_investor_type' => 'Individual',
            'personal_residency' => 'Non-U.S. Person',
            'experience' => 'new',
            'investment_fund_name' => 'Access Properties Diversified Income Fund I',
            'investment_commitment' => 25000,
            'investment_funded' => 0,
            'investment_wallet_status' => 'KYC required',
            'investment_expected_yield' => '8.0% target',
        ]);
    }
}
