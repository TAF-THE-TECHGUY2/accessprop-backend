<?php

namespace App\Console\Commands;

use App\Models\Fund;
use App\Models\Investor;
use App\Models\SigningEnvelope;
use App\Services\DocuSign\EnvelopeBuilder;
use App\Services\DocuSign\SigningNotYetOpen;
use Illuminate\Console\Command;

/**
 * Sends a real test envelope without touching the database: the investor is
 * held in memory and no envelope is recorded, so nothing appears in the admin
 * and signing it changes nothing in the app. The investor is an address you
 * choose; the counter signer is always the configured one, as for a real send.
 *
 * DocuSign sends signing-package signers no email (the app does, for real
 * agreements), so a test prints the signing links instead. Each one lasts about
 * five minutes; fetch the investor's with --link once the counter signer is done.
 */
class DocusignSendTestEnvelope extends Command
{
    protected $signature = 'docusign:send-test
                            {--email= : Who signs as the investor (required)}
                            {--template= : Template ID to test; defaults to the one a real send would use}
                            {--amount=10000 : Investment amount to price}
                            {--non-us : Test as a non-US person (W-8BEN package)}
                            {--name=Test Investor : Investor name to fill in}
                            {--dry-run : Print the request that would be sent, and send nothing}
                            {--no-prefill : Send without filling the five priced fields, to isolate a template problem}
                            {--draft : Create the envelope as an unsent draft and list the recipients DocuSign gave it}
                            {--link= : Print a fresh signing link for a test envelope already sent (give its envelope ID)}
                            {--as=investor : With --link: investor or counter_signer}';

    protected $description = 'Send a test subscription agreement to addresses you choose, recording nothing';

    public function handle(EnvelopeBuilder $builder): int
    {
        $email = (string) $this->option('email');
        $counterEmail = (string) config('docusign.counter_signer.email');
        $counterName = (string) config('docusign.counter_signer.name');

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('Give --email with a real address you can open: it receives the signing email.');

            return self::FAILURE;
        }

        if (! filter_var($counterEmail, FILTER_VALIDATE_EMAIL)) {
            $this->error('DOCUSIGN_DEFAULT_COUNTER_SIGNER_EMAIL is not set to a valid address.');

            return self::FAILURE;
        }

        $active = Fund::where('status', 'active')->get();
        if ($active->count() !== 1) {
            $this->error('Expected exactly one active fund to price against; found '.$active->count().'.');

            return self::FAILURE;
        }

        $amount = (float) $this->option('amount');
        $usPerson = ! $this->option('non-us');
        $counterFirst = (int) config('docusign.routing_order.counter_signer', 1) < (int) config('docusign.routing_order.investor', 2);

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

        if ($envelopeId = $this->option('link')) {
            $role = (string) $this->option('as');
            if (! in_array($role, ['investor', 'counter_signer'], true)) {
                $this->error('--as must be investor or counter_signer.');

                return self::FAILURE;
            }

            return $this->printLink($builder, $this->testEnvelope($envelopeId, $investor, $counterEmail, $counterName), $role);
        }

        $templateId = (string) ($this->option('template') ?: $builder->resolveTemplateId($investor));
        // A template named outright is one of the new packages under test, so
        // it gets the pricing; otherwise price only if a real send would.
        $withPricing = $this->option('template') !== null || $builder->usesTaxPackages();

        $this->info("Template:        {$templateId}");
        $this->line("Investor signs:  {$email}");
        $this->line("Counter signs:   {$counterName} <{$counterEmail}>");

        if ($this->option('draft')) {
            $ids = $builder->templateRecipientIds($templateId);
            $definition = $builder->buildEnvelopeDefinition(
                templateId: $templateId,
                investor: $investor,
                counterSignerEmail: $counterEmail,
                counterSignerName: $counterName,
                pricing: null,
                recipientIds: $ids,
                templateRoleNames: array_keys($ids),
                package: $withPricing,
            );
            // Unsent: DocuSign only insists on an email for each recipient at
            // send time, so the draft is created and shows who it contains.
            $definition->setStatus('created');

            $client = app(\App\Services\DocuSign\Client::class);
            $created = $client->envelopes()->createEnvelope($client->accountId(), $definition);
            $envelopeId = $created->getEnvelopeId();
            $recipients = $client->envelopes()->listRecipients($client->accountId(), $envelopeId);

            $this->line('');
            $this->info("Draft envelope {$envelopeId} created (not sent). Its recipients:");
            $kinds = [
                'signer' => $recipients->getSigners(),
                'carbon copy' => $recipients->getCarbonCopies(),
                'certified delivery' => $recipients->getCertifiedDeliveries(),
                'agent' => $recipients->getAgents(),
                'editor' => $recipients->getEditors(),
                'intermediary' => $recipients->getIntermediaries(),
                'in-person signer' => $recipients->getInPersonSigners(),
                'witness' => $recipients->getWitnesses(),
                'notary' => $recipients->getNotaries(),
                'seal' => $recipients->getSeals(),
            ];
            foreach ($kinds as $kind => $list) {
                foreach ($list ?? [] as $r) {
                    $this->line(sprintf(
                        '  - %s: role %s, recipientId=%s, routingOrder=%s, name=%s, email=%s',
                        $kind,
                        json_encode($r->getRoleName() ?? '', JSON_UNESCAPED_UNICODE),
                        $r->getRecipientId(),
                        $r->getRoutingOrder(),
                        $r->getName() ?: '(blank)',
                        method_exists($r, 'getEmail') && $r->getEmail() ? $r->getEmail() : '(blank)',
                    ));
                }
            }
            $this->line('');
            $this->line('Delete the draft afterwards: DocuSign > Agreements > Drafts.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $ids = $builder->templateRecipientIds($templateId);
            $this->line('');
            $this->line('Roles in the template (exact): '.json_encode($ids, JSON_UNESCAPED_UNICODE));
            $definition = $builder->buildEnvelopeDefinition(
                templateId: $templateId,
                investor: $investor,
                counterSignerEmail: $counterEmail,
                counterSignerName: $counterName,
                pricing: $withPricing && ! $this->option('no-prefill') && config('docusign.prefill_fields') ? $builder->quote($investor) : null,
                recipientIds: $ids,
                templateRoleNames: array_keys($ids),
                package: $withPricing,
            );
            $this->line(json_encode(
                \DocuSign\eSign\ObjectSerializer::sanitizeForSerialization($definition),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            ));
            $this->info('Dry run: nothing was sent.');

            return self::SUCCESS;
        }

        $result = $builder->sendTestEnvelope(
            $investor, $templateId, $counterEmail, $counterName, $withPricing,
            prefill: ! $this->option('no-prefill'),
        );

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

        if (! $withPricing) {
            $this->line($counterFirst
                ? "{$counterName} signs first; {$email} gets the signing email after."
                : "{$email} signs first; {$counterName} gets the countersigning email after.");

            return self::SUCCESS;
        }

        // A signing package: DocuSign emails nobody, so hand over the links.
        $first = $counterFirst ? 'counter_signer' : 'investor';
        $second = $counterFirst ? 'investor' : 'counter_signer';
        $this->printLink($builder, $this->testEnvelope($result['envelopeId'], $investor, $counterEmail, $counterName), $first);
        $this->line('');
        $this->line('When that is signed, get the other signer\'s link with:');
        $this->line(sprintf(
            '  php artisan docusign:send-test --email=%s --name=%s --link=%s --as=%s',
            escapeshellarg($email), escapeshellarg((string) $this->option('name')), $result['envelopeId'], $second,
        ));

        return self::SUCCESS;
    }

    /** An unsaved envelope record, enough to ask DocuSign for a signing link. */
    private function testEnvelope(string $envelopeId, Investor $investor, string $counterEmail, string $counterName): SigningEnvelope
    {
        $envelope = new SigningEnvelope([
            'docusign_envelope_id' => $envelopeId,
            'investor_email' => $investor->email,
            'investor_name' => $investor->name,
            'counter_signer_email' => $counterEmail,
            'counter_signer_name' => $counterName,
        ]);
        $envelope->embedded_signing = true;
        $envelope->setRelation('investor', $investor);

        return $envelope;
    }

    private function printLink(EnvelopeBuilder $builder, SigningEnvelope $envelope, string $role): int
    {
        $who = $role === 'counter_signer' ? $envelope->counter_signer_name : $envelope->investor_name;
        $returnUrl = rtrim((string) config('app.frontend_url'), '/').'/signing/complete';

        try {
            $url = $builder->signingUrl($envelope, $role, $returnUrl);
        } catch (SigningNotYetOpen $e) {
            $this->warn($e->getMessage());

            return self::FAILURE;
        }

        $this->line('');
        $this->info("Signing link for {$who} (open within 5 minutes, once):");
        $this->line($url);

        return self::SUCCESS;
    }
}
