<?php

namespace App\Services\DocuSign;

use App\Models\Investor;
use App\Models\SigningEnvelope;
use DocuSign\eSign\Model\CompositeTemplate;
use DocuSign\eSign\Model\DateSigned;
use DocuSign\eSign\Model\EnvelopeDefinition;
use DocuSign\eSign\Model\InlineTemplate;
use DocuSign\eSign\Model\Recipients;
use DocuSign\eSign\Model\ServerTemplate;
use DocuSign\eSign\Model\SignHere;
use DocuSign\eSign\Model\Signer;
use DocuSign\eSign\Model\Tabs;
use DocuSign\eSign\Model\Text;
use Illuminate\Support\Str;
use RuntimeException;

class EnvelopeBuilder
{
    public function __construct(private Client $client) {}

    public function sendSubscriptionAgreement(Investor $investor): SigningEnvelope
    {
        $templateId = $this->resolveTemplateId($investor);
        $counterSignerEmail = (string) config('docusign.counter_signer.email');
        $counterSignerName = (string) config('docusign.counter_signer.name');

        if ($counterSignerEmail === '') {
            throw new RuntimeException('DOCUSIGN_DEFAULT_COUNTER_SIGNER_EMAIL is not configured.');
        }

        $envelopeDefinition = $this->buildEnvelopeDefinition(
            templateId: $templateId,
            investor: $investor,
            counterSignerEmail: $counterSignerEmail,
            counterSignerName: $counterSignerName,
        );

        $created = $this->client->envelopes()->createEnvelope(
            $this->client->accountId(),
            $envelopeDefinition
        );

        $envelopeId = $created->getEnvelopeId();

        if (! $envelopeId) {
            throw new RuntimeException('DocuSign did not return an envelope ID.');
        }

        return SigningEnvelope::create([
            'investor_id' => $investor->id,
            'docusign_envelope_id' => $envelopeId,
            'template_id' => $templateId,
            'type' => 'subscription_agreement',
            'status' => SigningEnvelope::STATUS_SENT,
            'investor_email' => $investor->email,
            'investor_name' => $investor->name,
            'counter_signer_email' => $counterSignerEmail,
            'counter_signer_name' => $counterSignerName,
            'sent_at' => now(),
        ]);
    }

    private function resolveTemplateId(Investor $investor): string
    {
        $key = $investor->accreditation_status === 'accredited' ? 'accredited' : 'non_accredited';

        $templateId = (string) config("docusign.templates.subscription_agreement.{$key}");

        if ($templateId === '') {
            throw new RuntimeException("No DocuSign template configured for accreditation status: {$key}");
        }

        return $templateId;
    }

    private function buildEnvelopeDefinition(
        string $templateId,
        Investor $investor,
        string $counterSignerEmail,
        string $counterSignerName,
    ): EnvelopeDefinition {
        $investorRoleName = (string) config('docusign.roles.investor');
        $counterSignerRoleName = (string) config('docusign.roles.counter_signer');

        $investorSigner = (new Signer())
            ->setEmail($investor->email)
            ->setName($investor->name)
            ->setRoleName($investorRoleName)
            ->setRecipientId('1')
            ->setRoutingOrder('1')
            ->setClientUserId(null)
            ->setTabs($this->buildTabs('investor', $investor));

        $counterSigner = (new Signer())
            ->setEmail($counterSignerEmail)
            ->setName($counterSignerName)
            ->setRoleName($counterSignerRoleName)
            ->setRecipientId('2')
            ->setRoutingOrder('2')
            ->setTabs($this->buildTabs('counter_signer', $investor));

        $compositeTemplate = (new CompositeTemplate())
            ->setCompositeTemplateId('1')
            ->setServerTemplates([
                (new ServerTemplate())->setSequence('1')->setTemplateId($templateId),
            ])
            ->setInlineTemplates([
                (new InlineTemplate())
                    ->setSequence('2')
                    ->setRecipients(
                        (new Recipients())->setSigners([$investorSigner, $counterSigner])
                    ),
            ]);

        return (new EnvelopeDefinition())
            ->setEmailSubject('Access Properties — Membership Interest Purchase Agreement')
            ->setEmailBlurb("Hi {$investor->name},\n\nYour subscription agreement for Access Properties is ready for signing. Please review and sign at your earliest convenience.\n\nIf you have any questions, reply to this email.\n\nThank you,\nAccess Properties")
            ->setCompositeTemplates([$compositeTemplate])
            ->setStatus('sent');
    }

    private function buildTabs(string $role, Investor $investor): Tabs
    {
        $tabConfigs = (array) config("docusign.tabs.{$role}", []);

        $signHere = [];
        $dateSigned = [];
        $text = [];

        $values = $this->investorPrefillValues($investor);

        foreach ($tabConfigs as $tab) {
            $shared = $this->sharedTabAttributes($tab);

            switch ($tab['type']) {
                case 'signature':
                    $signHere[] = (new SignHere())->setTabLabel($tab['data_label'])
                        ->setPageNumber($shared['page'])
                        ->setXPosition($shared['x'])
                        ->setYPosition($shared['y'])
                        ->setDocumentId('1');
                    break;

                case 'date_signed':
                    $dateSigned[] = (new DateSigned())->setTabLabel($tab['data_label'])
                        ->setPageNumber($shared['page'])
                        ->setXPosition($shared['x'])
                        ->setYPosition($shared['y'])
                        ->setDocumentId('1');
                    break;

                case 'text':
                    $valueKey = $tab['value_key'] ?? $tab['data_label'];
                    $value = $values[$valueKey] ?? '';

                    $text[] = (new Text())
                        ->setTabLabel($tab['data_label'])
                        ->setValue((string) $value)
                        ->setLocked($tab['read_only'] ?? true ? 'true' : 'false')
                        ->setRequired($tab['required'] ?? true ? 'true' : 'false')
                        ->setPageNumber($shared['page'])
                        ->setXPosition($shared['x'])
                        ->setYPosition($shared['y'])
                        ->setWidth((string) ($tab['width'] ?? 200))
                        ->setDocumentId('1');
                    break;
            }
        }

        $tabs = new Tabs();
        if ($signHere) $tabs->setSignHereTabs($signHere);
        if ($dateSigned) $tabs->setDateSignedTabs($dateSigned);
        if ($text) $tabs->setTextTabs($text);

        return $tabs;
    }

    private function sharedTabAttributes(array $tab): array
    {
        return [
            'page' => (string) ($tab['page'] ?? 1),
            'x' => (string) ($tab['x'] ?? 100),
            'y' => (string) ($tab['y'] ?? 100),
        ];
    }

    private function investorPrefillValues(Investor $investor): array
    {
        $address = trim(implode(', ', array_filter([
            $investor->address_line1,
            $investor->address_line2,
            $investor->address_city,
            $investor->address_state,
            $investor->address_postal_code,
            $investor->address_country,
        ])));

        $amount = $investor->investment_amount !== null
            ? '$'.number_format((float) $investor->investment_amount, 2)
            : '';

        return [
            'name' => $investor->name,
            'email' => $investor->email,
            'address' => $address,
            'investment_amount' => $amount,
            'accreditation_status' => Str::headline((string) $investor->accreditation_status),
            'code' => strtoupper((string) $investor->code),
        ];
    }
}
