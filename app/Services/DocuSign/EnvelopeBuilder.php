<?php

namespace App\Services\DocuSign;

use App\Models\Fund;
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

        // The signing packages carry the transaction terms, so they are
        // quoted now and kept with the envelope. The old template does not.
        $pricing = $this->usesTaxPackages() ? $this->quote($investor) : null;

        $envelopeDefinition = $this->buildEnvelopeDefinition(
            templateId: $templateId,
            investor: $investor,
            counterSignerEmail: $counterSignerEmail,
            counterSignerName: $counterSignerName,
            pricing: $pricing,
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
            'pricing' => $pricing,
            'type' => 'subscription_agreement',
            'status' => SigningEnvelope::STATUS_SENT,
            'investor_email' => $investor->email,
            'investor_name' => $investor->name,
            'counter_signer_email' => $counterSignerEmail,
            'counter_signer_name' => $counterSignerName,
            'sent_at' => now(),
        ]);
    }

    /**
     * The W-9 package for a US person, the W-8BEN package otherwise — once
     * both are configured. Until then, the template by accreditation as
     * before, for everyone: with only one set, the other group would quietly
     * get the old package, which carries no tax form at all.
     */
    public function resolveTemplateId(Investor $investor): string
    {
        $usTemplate = (string) config('docusign.templates.subscription_agreement.us_person');
        $nonUsTemplate = (string) config('docusign.templates.subscription_agreement.non_us_person');

        if ($this->usesTaxPackages()) {
            return $investor->isUsTaxPerson() ? $usTemplate : $nonUsTemplate;
        }

        $taxKey = $investor->isUsTaxPerson() ? 'us_person' : 'non_us_person';

        $key = $investor->accreditation_status === 'accredited' ? 'accredited' : 'non_accredited';

        $templateId = (string) config("docusign.templates.subscription_agreement.{$key}");

        if ($templateId === '') {
            throw new RuntimeException("No DocuSign template configured for {$taxKey} or accreditation status: {$key}");
        }

        return $templateId;
    }

    /** Both signing packages configured: they replace the old template. */
    public function usesTaxPackages(): bool
    {
        return (string) config('docusign.templates.subscription_agreement.us_person') !== ''
            && (string) config('docusign.templates.subscription_agreement.non_us_person') !== '';
    }

    /**
     * The transaction terms for the Subscription Agreement, per the Valuation
     * and Unit Pricing Policy:
     *
     *   price per Unit = Base NAV × (1 + premium)
     *   Units          = capital contribution ÷ price per Unit
     *
     * on the latest published Base NAV, unrounded. Rounded only for display:
     * 4 decimal places, as the policy's own illustration shows them.
     *
     * @return array{amount: float, base_nav: string, base_nav_as_of: string, premium_pct: float, price_per_unit: float, units: float, display: array<string, string>}
     */
    public function quote(Investor $investor): array
    {
        $fund = $investor->fund_id ? Fund::find($investor->fund_id) : null;

        if (! $fund) {
            throw new RuntimeException("Investor {$investor->code} has no fund; cannot price the subscription.");
        }

        $nav = $fund->bookValueAsOf(now());

        if (! $nav || (float) $nav->price <= 0) {
            throw new RuntimeException("Fund {$fund->code} has no published Base NAV; cannot price the subscription.");
        }

        $amount = (float) ($investor->investment_commitment ?: $investor->investment_amount);

        if ($amount <= 0) {
            throw new RuntimeException("Investor {$investor->code} has no investment amount; cannot price the subscription.");
        }

        $baseNav = (float) $nav->price;
        $premiumPct = (float) $fund->current_premium_pct;
        $pricePerUnit = $baseNav * (1 + $premiumPct / 100);
        $units = $amount / $pricePerUnit;

        return [
            'fund_id' => $fund->id,
            'amount' => round($amount, 2),
            'base_nav' => (string) $nav->price,
            'base_nav_as_of' => $nav->as_of_date->toDateString(),
            'premium_pct' => $premiumPct,
            'price_per_unit' => round($pricePerUnit, 8),
            'units' => round($units, 6),
            // The PDF prints the $ and % beside each blank, so values only.
            'display' => [
                'investor_full_name' => (string) $investor->name,
                'total_purchase_price' => number_format($amount, 2),
                'subscription_premium_pct' => number_format($premiumPct, 2),
                'price_per_unit' => number_format($pricePerUnit, 4),
                'units' => number_format($units, 4),
            ],
        ];
    }

    /**
     * Values for fields already placed in the template, matched by Data Label.
     * No position: DocuSign fills the template's own field of that label.
     */
    private function labelledValueTabs(array $values): Tabs
    {
        $text = [];

        foreach ($values as $label => $value) {
            $text[] = (new Text())
                ->setTabLabel($label)
                ->setValue((string) $value)
                ->setLocked('true');
        }

        return (new Tabs())->setTextTabs($text);
    }

    public function buildEnvelopeDefinition(
        string $templateId,
        Investor $investor,
        string $counterSignerEmail,
        string $counterSignerName,
        ?array $pricing = null,
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
            // The signing packages have every field placed in the template;
            // the app only fills the labelled ones. The old template had none,
            // so the app placed them on page 1.
            ->setTabs($pricing !== null
                ? $this->labelledValueTabs($pricing['display'])
                : $this->buildTabs('investor', $investor));

        $counterSigner = (new Signer())
            ->setEmail($counterSignerEmail)
            ->setName($counterSignerName)
            ->setRoleName($counterSignerRoleName)
            ->setRecipientId('2')
            ->setRoutingOrder('2');

        if ($pricing === null) {
            $counterSigner->setTabs($this->buildTabs('counter_signer', $investor));
        }

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
            ->setEmailSubject($pricing !== null
                ? 'Access Real Estate Fund I — your subscription documents'
                : 'Access Properties — Membership Interest Purchase Agreement')
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
