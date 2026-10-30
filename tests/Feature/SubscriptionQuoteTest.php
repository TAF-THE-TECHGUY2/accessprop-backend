<?php

namespace Tests\Feature;

use App\Models\Fund;
use App\Models\FundUnitPrice;
use App\Models\Investor;
use App\Services\DocuSign\EnvelopeBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The transaction terms filled into the Subscription Agreement, checked against
 * Appendix A of the fund's Valuation and Unit Pricing Policy: September 30, 2026
 * Base NAV $14.0945809850, $10,000 subscription.
 */
class SubscriptionQuoteTest extends TestCase
{
    use RefreshDatabase;

    private Fund $fund;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fund = Fund::create(['code' => 'aref-i', 'name' => 'Access Real Estate Fund I', 'status' => 'active']);
        FundUnitPrice::create(['fund_id' => $this->fund->id, 'as_of_date' => '2026-09-30', 'price' => '14.0945809850']);

        config([
            'docusign.templates.subscription_agreement.us_person' => 'tpl-w9',
            'docusign.templates.subscription_agreement.non_us_person' => 'tpl-w8',
        ]);
    }

    private function investor(float $amount = 10000): Investor
    {
        return Investor::create([
            'code' => 'inv-7101',
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => 'password123',
            'phone' => '+1 617 555 0100',
            'country' => 'United States',
            'joined_at' => now(),
            'fund_id' => $this->fund->id,
            'accreditation_status' => 'accredited',
            'kyc_status' => 'approved',
            'investment_status' => 'awaiting_documents',
            'dashboard_status' => 'active',
            'address_line1' => '1 Beacon St',
            'address_city' => 'Boston',
            'address_state' => 'MA',
            'address_postal_code' => '02108',
            'address_country' => 'United States',
            'personal_investor_type' => 'Individual',
            'personal_residency' => Investor::US_PERSON,
            'investment_fund_name' => 'Access Real Estate Fund I',
            'investment_amount' => $amount,
            'investment_commitment' => $amount,
            'investment_wallet_status' => 'unfunded',
            'investment_expected_yield' => '8%',
        ]);
    }

    public static function policyAppendix(): array
    {
        // premium %, price per Unit, Units for $10,000 — as printed in Appendix A.
        return [
            '0%' => [0, '14.0946', '709.4925'],
            '1%' => [1, '14.2355', '702.4679'],
            '2%' => [2, '14.3765', '695.5809'],
            '3%' => [3, '14.5174', '688.8277'],
            '4%' => [4, '14.6584', '682.2044'],
            '5%' => [5, '14.7993', '675.7072'],
        ];
    }

    #[DataProvider('policyAppendix')]
    public function test_the_quote_matches_the_valuation_policy(float $premium, string $price, string $units): void
    {
        $this->fund->update(['current_premium_pct' => $premium]);

        $quote = app(EnvelopeBuilder::class)->quote($this->investor());

        $this->assertSame('10,000.00', $quote['display']['total_purchase_price']);
        $this->assertSame(number_format($premium, 2), $quote['display']['subscription_premium_pct']);
        $this->assertSame($price, $quote['display']['price_per_unit']);
        $this->assertSame($units, $quote['display']['units']);
        $this->assertSame('Ada Lovelace', $quote['display']['investor_full_name']);
        $this->assertSame('2026-09-30', $quote['base_nav_as_of']);
    }

    public function test_the_packages_are_sent_as_the_template_with_its_roles_filled(): void
    {
        $definition = app(EnvelopeBuilder::class)->buildEnvelopeDefinition(
            templateId: 'tpl-w9',
            investor: $investor = $this->investor(25000),
            counterSignerEmail: 'dion@example.com',
            counterSignerName: 'Dion',
            pricing: app(EnvelopeBuilder::class)->quote($investor),
        );

        $this->assertSame('tpl-w9', $definition->getTemplateId());
        $this->assertNull($definition->getCompositeTemplates());

        [$investorRole, $counterRole] = $definition->getTemplateRoles();

        // Matched to the template's roles by name; no recipient IDs involved.
        $this->assertSame('Investor', $investorRole->getRoleName());
        $this->assertSame('ada@example.com', $investorRole->getEmail());
        // The fund countersigns first.
        $this->assertSame('2', $investorRole->getRoutingOrder());
        $this->assertSame('Counter Signer', $counterRole->getRoleName());
        $this->assertSame('dion@example.com', $counterRole->getEmail());
        $this->assertSame('1', $counterRole->getRoutingOrder());

        // Fields filled by Data Label only; nothing positioned, no signatures.
        $tabs = $investorRole->getTabs();
        $this->assertSame(
            ['investor_full_name', 'total_purchase_price', 'subscription_premium_pct', 'price_per_unit', 'units'],
            array_map(fn ($t) => $t->getTabLabel(), $tabs->getTextTabs()),
        );
        foreach ($tabs->getTextTabs() as $tab) {
            $this->assertNull($tab->getXPosition());
            $this->assertNull($tab->getPageNumber());
        }
        $this->assertNull($tabs->getSignHereTabs());
        $this->assertNull($counterRole->getTabs());
    }

    public function test_the_old_template_still_gets_its_placed_fields(): void
    {
        $definition = app(EnvelopeBuilder::class)->buildEnvelopeDefinition(
            templateId: 'tpl-legacy',
            investor: $this->investor(),
            counterSignerEmail: 'dion@example.com',
            counterSignerName: 'Dion',
        );

        $signers = $definition->getCompositeTemplates()[0]->getInlineTemplates()[0]->getRecipients()->getSigners();
        $this->assertNotEmpty($signers[0]->getTabs()->getSignHereTabs());
        $this->assertNotEmpty($signers[1]->getTabs()->getSignHereTabs());
    }

    public function test_a_fund_with_no_published_nav_refuses_to_price(): void
    {
        FundUnitPrice::query()->delete();

        $this->expectExceptionMessage('no published Base NAV');
        app(EnvelopeBuilder::class)->quote($this->investor());
    }

    public function test_roles_take_the_template_s_exact_spelling(): void
    {
        // A non-breaking space and a trailing space, as typed in DocuSign's editor.
        $definition = app(EnvelopeBuilder::class)->buildEnvelopeDefinition(
            templateId: 'tpl-w9',
            investor: $investor = $this->investor(),
            counterSignerEmail: 'dion@example.com',
            counterSignerName: 'Dion',
            pricing: app(EnvelopeBuilder::class)->quote($investor),
            templateRoleNames: ["Counter\u{00A0}Signer", 'investor '],
        );

        [$investorRole, $counterRole] = $definition->getTemplateRoles();
        $this->assertSame('investor ', $investorRole->getRoleName());
        $this->assertSame("Counter\u{00A0}Signer", $counterRole->getRoleName());
    }

    public function test_with_prefilling_off_a_package_goes_out_unfilled(): void
    {
        $this->assertFalse(config('docusign.prefill_fields'));

        $definition = app(EnvelopeBuilder::class)->buildEnvelopeDefinition(
            templateId: 'tpl-w9',
            investor: $this->investor(),
            counterSignerEmail: 'dion@example.com',
            counterSignerName: 'Dion',
            pricing: null,
            package: true,
        );

        // Still the template with its roles — not the old page-1 placements.
        $this->assertSame('tpl-w9', $definition->getTemplateId());
        $this->assertNull($definition->getCompositeTemplates());
        [$investorRole, $counterRole] = $definition->getTemplateRoles();
        $this->assertSame('ada@example.com', $investorRole->getEmail());
        $this->assertNull($investorRole->getTabs());
        $this->assertNull($counterRole->getTabs());
    }
}
