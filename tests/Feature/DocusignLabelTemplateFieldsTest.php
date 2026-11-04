<?php

namespace Tests\Feature;

use App\Models\Fund;
use App\Models\FundUnitPrice;
use App\Models\Investor;
use App\Services\DocuSign\Client;
use App\Services\DocuSign\EnvelopeBuilder;
use DocuSign\eSign\Api\TemplatesApi;
use DocuSign\eSign\Model\EnvelopeTemplate;
use DocuSign\eSign\Model\Recipients;
use DocuSign\eSign\Model\Signer;
use DocuSign\eSign\Model\Tabs;
use DocuSign\eSign\Model\Text;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/** Fields marked by their default text get the matching Data Label. */
class DocusignLabelTemplateFieldsTest extends TestCase
{
    use RefreshDatabase;

    private function templatesApi(array $textTabs): TemplatesApi
    {
        $api = Mockery::mock(TemplatesApi::class);
        $api->shouldReceive('get')->andReturn((new EnvelopeTemplate())->setRecipients((new Recipients())->setSigners([
            (new Signer())->setRoleName('Counter Signer')->setRecipientId('1'),
            (new Signer())->setRoleName('Investor ')->setRecipientId('2'),
        ])));
        $api->shouldReceive('listTabs')->with('acct', '2', 'tpl-w9')->andReturn((new Tabs())->setTextTabs($textTabs));

        $client = Mockery::mock(Client::class);
        $client->shouldReceive('accountId')->andReturn('acct');
        $client->shouldReceive('templates')->andReturn($api);
        $this->app->instance(Client::class, $client);

        return $api;
    }

    private function field(string $id, string $label, string $value): Text
    {
        return (new Text())->setTabId($id)->setTabLabel($label)->setValue($value)->setPageNumber('2')->setShared('true')->setRequired('true');
    }

    public function test_default_text_becomes_the_data_label(): void
    {
        $tabs = [
            $this->field('t1', 'Text 9f2c', 'investor_full_name'),
            $this->field('t2', 'Text 1ab0', ' Units '),
            $this->field('t3', 'Text 77de', 'something the investor types'),
        ];
        $api = $this->templatesApi($tabs);
        $api->shouldReceive('updateTabs')->once()->withArgs(function ($acct, $recipient, $template, Tabs $update) {
            $changed = collect($update->getTextTabs())->mapWithKeys(fn (Text $t) => [$t->getTabId() => $t]);

            return $recipient === '2' && $template === 'tpl-w9'
                && $changed->keys()->all() === ['t1', 't2']
                && $changed['t1']->getTabLabel() === 'investor_full_name'
                && $changed['t2']->getTabLabel() === 'units'
                && $changed['t2']->getValue() === ''
                && $changed['t2']->getShared() === 'false'
                && $changed['t2']->getRequired() === 'false';
        });

        $this->artisan('docusign:label-template-fields', ['template_id' => 'tpl-w9'])
            ->expectsOutputToContain('No Investor text field marked "price_per_unit"')
            ->expectsOutputToContain('2 field(s) updated')
            ->assertSuccessful();
    }

    public function test_a_dry_run_changes_nothing(): void
    {
        $api = $this->templatesApi([$this->field('t1', 'Text 9f2c', 'units')]);
        $api->shouldNotReceive('updateTabs');

        $this->artisan('docusign:label-template-fields', ['template_id' => 'tpl-w9', '--dry-run' => true])
            ->expectsOutputToContain('Dry run')
            ->assertSuccessful();
    }

    public function test_the_labels_are_the_ones_the_app_fills(): void
    {
        $fund = Fund::create(['code' => 'aref-i', 'name' => 'Access Real Estate Fund I', 'status' => 'active']);
        FundUnitPrice::create(['fund_id' => $fund->id, 'as_of_date' => now()->subDay()->toDateString(), 'price' => '14.0945809850']);
        $quote = app(EnvelopeBuilder::class)->quote(new Investor(['code' => 'inv-x', 'name' => 'X', 'fund_id' => $fund->id, 'investment_amount' => 10000]));

        $this->assertSame(EnvelopeBuilder::PRICED_LABELS, array_keys($quote['display']));
    }
}
