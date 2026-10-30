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
use DocuSign\eSign\Model\RecipientViewRequest;
use DocuSign\eSign\Model\Tabs;
use DocuSign\eSign\Model\TemplateRole;
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
        $package = $this->usesTaxPackages();
        $pricing = $package && config('docusign.prefill_fields') ? $this->quote($investor) : null;

        $envelopeId = $this->createEnvelope($this->buildEnvelopeDefinition(
            templateId: $templateId,
            investor: $investor,
            counterSignerEmail: $counterSignerEmail,
            counterSignerName: $counterSignerName,
            pricing: $pricing,
            recipientIds: $ids = $this->templateRecipientIds($templateId),
            templateRoleNames: array_keys($ids),
            package: $package,
        ));

        return SigningEnvelope::create([
            'investor_id' => $investor->id,
            'docusign_envelope_id' => $envelopeId,
            'template_id' => $templateId,
            'pricing' => $pricing,
            'embedded_signing' => $package,
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
     * A test send: the same envelope a real one would be, to addresses of the
     * caller's choosing, recorded nowhere. The webhook ignores envelopes it has
     * no record of, so signing it changes nothing in the app.
     */
    public function sendTestEnvelope(
        Investor $investor,
        string $templateId,
        string $counterSignerEmail,
        string $counterSignerName,
        bool $withPricing,
        bool $prefill = true,
    ): array {
        // $withPricing marks a signing package; whether its fields are filled
        // follows the same switch as a real send, unless told not to.
        $pricing = $withPricing && $prefill && config('docusign.prefill_fields')
            ? $this->quote($investor)
            : null;

        $envelopeId = $this->createEnvelope($this->buildEnvelopeDefinition(
            templateId: $templateId,
            investor: $investor,
            counterSignerEmail: $counterSignerEmail,
            counterSignerName: $counterSignerName,
            pricing: $pricing,
            recipientIds: $ids = $this->templateRecipientIds($templateId),
            templateRoleNames: array_keys($ids),
            package: $withPricing,
        ));

        return ['envelopeId' => $envelopeId, 'pricing' => $pricing];
    }

    /**
     * Each role's recipient ID in the template, by role name.
     *
     * DocuSign merges the app's signers into the template's roles by recipient
     * ID. The app numbered them Investor 1 and Counter Signer 2, which matched
     * the old template by luck; a template whose roles were added the other
     * way round got the Counter Signer's address on the Investor's role and an
     * empty one on the other ("'email' not set for recipient with recipientId
     * 1"). Reading the IDs from the template makes the order irrelevant.
     *
     * @return array<string, string>
     */
    public function templateRecipientIds(string $templateId): array
    {
        $ids = [];
        $signers = $this->client->templates()
            ->listRecipients($this->client->accountId(), $templateId)
            ->getSigners() ?? [];

        foreach ($signers as $signer) {
            if ($signer->getRoleName() !== null && $signer->getRecipientId() !== null) {
                $ids[$signer->getRoleName()] = (string) $signer->getRecipientId();
            }
        }

        return $ids;
    }

    private function createEnvelope(EnvelopeDefinition $definition): string
    {
        $created = $this->client->envelopes()->createEnvelope($this->client->accountId(), $definition);
        $envelopeId = $created->getEnvelopeId();

        if (! $envelopeId) {
            throw new RuntimeException('DocuSign did not return an envelope ID.');
        }

        return $envelopeId;
    }

    /**
     * The W-9 package for a US person, the W-8BEN package otherwise. Once
     * either package is configured they are the only templates used: an
     * investor whose package is not set up yet is refused with a message
     * saying so, never sent the old template, which has no tax form. The old
     * template is used only while neither package exists.
     */
    public function resolveTemplateId(Investor $investor): string
    {
        if ($this->usesTaxPackages()) {
            $us = $investor->isUsTaxPerson();
            $templateId = (string) config('docusign.templates.subscription_agreement.'.($us ? 'us_person' : 'non_us_person'));

            if ($templateId === '') {
                throw new SigningPackageMissing(sprintf(
                    'The %s signing package is not set up yet, so this investor (%s) cannot be sent an agreement. Set %s on the server.',
                    $us ? 'W-9' : 'W-8BEN',
                    $us ? 'U.S. person' : 'not a U.S. person',
                    $us ? 'DOCUSIGN_TEMPLATE_ID_US_PERSON' : 'DOCUSIGN_TEMPLATE_ID_NON_US_PERSON',
                ));
            }

            return $templateId;
        }

        $taxKey = $investor->isUsTaxPerson() ? 'us_person' : 'non_us_person';

        $key = $investor->accreditation_status === 'accredited' ? 'accredited' : 'non_accredited';

        $templateId = (string) config("docusign.templates.subscription_agreement.{$key}");

        if ($templateId === '') {
            throw new RuntimeException("No DocuSign template configured for {$taxKey} or accreditation status: {$key}");
        }

        return $templateId;
    }

    /** Either signing package configured: the packages replace the old template. */
    public function usesTaxPackages(): bool
    {
        return (string) config('docusign.templates.subscription_agreement.us_person') !== ''
            || (string) config('docusign.templates.subscription_agreement.non_us_person') !== '';
    }

    /** Identifies each signer to DocuSign as signing through the app. */
    public static function clientUserIdFor(string $role, string $investorCode): string
    {
        return $role === 'counter_signer' ? 'counter-signer' : 'investor-'.$investorCode;
    }

    /**
     * A one-time signing link for one signer of an envelope, valid about five
     * minutes. Only for envelopes sent with embedded signing.
     */
    public function signingUrl(SigningEnvelope $envelope, string $role, string $returnUrl): string
    {
        if (! $envelope->embedded_signing) {
            throw new RuntimeException('This agreement was sent before signing links existed; the signer must use the DocuSign email.');
        }

        $investorCode = (string) $envelope->investor?->code;
        [$email, $name] = $role === 'counter_signer'
            ? [$envelope->counter_signer_email, $envelope->counter_signer_name]
            : [$envelope->investor_email, $envelope->investor_name];

        $request = (new RecipientViewRequest())
            ->setAuthenticationMethod('none')
            ->setClientUserId(self::clientUserIdFor($role, $investorCode))
            ->setEmail($email)
            ->setUserName($name)
            ->setReturnUrl($returnUrl);

        try {
            return (string) $this->client->envelopes()
                ->createRecipientView($this->client->accountId(), $envelope->docusign_envelope_id, $request)
                ->getUrl();
        } catch (\DocuSign\eSign\Client\ApiException $e) {
            $body = $e->getResponseBody();
            $code = is_object($body) ? ($body->errorCode ?? null) : (is_string($body) && preg_match('/"errorCode"\s*:\s*"([A-Z_]+)"/', $body, $m) ? $m[1] : null);

            // Signing order: the other signer has not finished yet.
            if ($code === 'RECIPIENT_NOT_IN_SEQUENCE') {
                throw new SigningNotYetOpen(sprintf(
                    '%s signs first. This link becomes available once they have signed.',
                    $role === 'counter_signer' ? $envelope->investor_name : $envelope->counter_signer_name,
                ));
            }

            throw $e;
        }
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
        array $recipientIds = [],
        array $templateRoleNames = [],
        ?bool $package = null,
    ): EnvelopeDefinition {
        // A signing package (fields placed in the template) is sent as the
        // template with its roles, filled or not. Defaults to "priced" for
        // callers that only pass pricing.
        $package ??= $pricing !== null;
        // The template's own spelling of each role, when it differs from the
        // configured one only by spacing or case. DocuSign matches roles by
        // exact name, and a stray non-breaking space typed into a role name in
        // its editor is invisible but leaves that role unmatched and without
        // an email.
        $investorRoleName = $this->matchRoleName((string) config('docusign.roles.investor'), $templateRoleNames);
        $counterSignerRoleName = $this->matchRoleName((string) config('docusign.roles.counter_signer'), $templateRoleNames);
        $investorOrder = (string) config('docusign.routing_order.investor', '2');
        $counterSignerOrder = (string) config('docusign.routing_order.counter_signer', '1');

        // The signing packages are sent as the template with its roles filled
        // in, DocuSign's plain form for one template: roles are matched by
        // name, fields by Data Label, and signing order is the template's own.
        // The merged (composite) form below matched the old template, but the
        // production W-9 template was refused with "'email' not set for
        // recipient with recipientId 1" even with the template's own IDs.
        if ($package) {
            return (new EnvelopeDefinition())
                ->setTemplateId($templateId)
                ->setTemplateRoles([
                    (new TemplateRole())
                        ->setRoleName($investorRoleName)
                        ->setName($investor->name)
                        ->setEmail($investor->email)
                        // Signs through the app as well as by email, so a
                        // link can be generated for them.
                        ->setClientUserId(self::clientUserIdFor('investor', (string) $investor->code))
                        ->setEmbeddedRecipientStartUrl('SIGN_AT_DOCUSIGN')
                        ->setRoutingOrder($investorOrder)
                        ->setTabs($pricing !== null ? $this->labelledValueTabs($pricing['display']) : null),
                    (new TemplateRole())
                        ->setRoleName($counterSignerRoleName)
                        ->setName($counterSignerName)
                        ->setEmail($counterSignerEmail)
                        ->setClientUserId(self::clientUserIdFor('counter_signer', (string) $investor->code))
                        ->setEmbeddedRecipientStartUrl('SIGN_AT_DOCUSIGN')
                        ->setRoutingOrder($counterSignerOrder),
                ])
                ->setEmailSubject('Access Real Estate Fund I — your subscription documents')
                ->setEmailBlurb("Hi {$investor->name},\n\nYour subscription documents for Access Real Estate Fund I are ready to sign. Please review and sign at your earliest convenience.\n\nIf you have any questions, reply to this email.\n\nThank you,\nAccess Properties")
                ->setStatus('sent');
        }

        $investorSigner = (new Signer())
            ->setEmail($investor->email)
            ->setName($investor->name)
            ->setRoleName($investorRoleName)
            ->setRecipientId($recipientIds[$investorRoleName] ?? '1')
            ->setRoutingOrder($investorOrder)
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
            ->setRecipientId($recipientIds[$counterSignerRoleName] ?? '2')
            ->setRoutingOrder($counterSignerOrder);

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

    private function matchRoleName(string $configured, array $templateRoleNames): string
    {
        $normalise = fn (string $name) => mb_strtolower(trim(preg_replace('/[\s\x{00A0}\x{2007}\x{202F}]+/u', ' ', $name)));

        foreach ($templateRoleNames as $name) {
            if ($normalise((string) $name) === $normalise($configured)) {
                return (string) $name;
            }
        }

        return $configured;
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
