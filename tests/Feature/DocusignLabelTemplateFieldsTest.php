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

    /** Counter Signer is recipient 1, Investor recipient 2. */
    private function templatesApi(array $investorTabs, array $counterTabs = []): TemplatesApi
    {
        $api = Mockery::mock(TemplatesApi::class);
        $api->shouldReceive('get')->andReturn((new EnvelopeTemplate())->setRecipients((new Recipients())->setSigners([
            (new Signer())->setRoleName('Counter Signer')->setRecipientId('1'),
            (new Signer())->setRoleName('Investor ')->setRecipientId('2'),
        ])));
        $api->shouldReceive('listTabs')->with('acct', '1', 'tpl-w9')->andReturn((new Tabs())->setTextTabs($counterTabs));
        $api->shouldReceive('listTabs')->with('acct', '2', 'tpl-w9')->andReturn((new Tabs())->setTextTabs($investorTabs));

        $client = Mockery::mock(Client::class);
        $client->shouldReceive('accountId')->andReturn('acct');
        $client->shouldReceive('templates')->andReturn($api);
        $this->app->instance(Client::class, $client);

        return $api;
    }

    private function field(string $id, string $label, string $value): Text
    {
        return (new Text())->setTabId($id)->setTabLabel($label)->setValue($value)
            ->setDocumentId('96295969')->setPageNumber('1')->setXPosition('310')->setYPosition('402')->setWidth('120')
            ->setShared('true')->setRequired('true');
    }

    public function test_the_investor_s_fields_move_to_the_counter_signer(): void
    {
        $api = $this->templatesApi([
            $this->field('t1', 'investor_full_name', ''),
            $this->field('t2', 'Text 1ab0', ' Units '),
            $this->field('t3', 'Text 77de', 'something the investor types'),
        ]);
        $api->shouldReceive('createTabs')->once()->withArgs(function ($acct, $recipient, $template, Tabs $tabs) {
            $made = collect($tabs->getTextTabs())->keyBy(fn (Text $t) => $t->getTabLabel());

            return $recipient === '1'
                && $made->keys()->all() === ['investor_full_name', 'units']
                && $made['units']->getTabId() === null
                && $made['units']->getDocumentId() === '96295969'
                && $made['units']->getPageNumber() === '1'
                && $made['units']->getXPosition() === '310'
                && $made['units']->getYPosition() === '402'
                && $made['units']->getWidth() === '120'
                && $made['units']->getValue() === ''
                && $made['units']->getShared() === 'false'
                && $made['units']->getRequired() === 'false';
        })->ordered();
        $api->shouldReceive('deleteTabs')->once()->withArgs(fn ($acct, $recipient, $template, Tabs $tabs) => $recipient === '2'
            && collect($tabs->getTextTabs())->map->getTabId()->all() === ['t1', 't2'])->ordered();
        $api->shouldNotReceive('updateTabs');

        $this->artisan('docusign:label-template-fields', ['template_id' => 'tpl-w9'])
            ->expectsOutputToContain('"units" moves from the Investor to the Counter Signer')
            ->expectsOutputToContain('No text field marked "price_per_unit"')
            ->expectsOutputToContain('2 field(s) updated')
            ->assertSuccessful();
    }

    public function test_default_text_becomes_the_data_label_in_place(): void
    {
        $api = $this->templatesApi([$this->field('t1', 'Text 9f2c', 'investor_full_name')]);
        $api->shouldReceive('updateTabs')->once()->withArgs(fn ($acct, $recipient, $template, Tabs $tabs) => $recipient === '2'
            && $tabs->getTextTabs()[0]->getTabId() === 't1'
            && $tabs->getTextTabs()[0]->getTabLabel() === 'investor_full_name'
            && $tabs->getTextTabs()[0]->getValue() === '');
        $api->shouldNotReceive('createTabs');

        $this->artisan('docusign:label-template-fields', ['template_id' => 'tpl-w9', '--role' => 'investor'])
            ->assertSuccessful();
    }

    public function test_fields_already_set_up_are_left_alone(): void
    {
        $done = fn ($id, $label) => (new Text())->setTabId($id)->setTabLabel($label)->setValue('')->setRequired('false')->setShared('false')->setPageNumber('1');
        $api = $this->templatesApi([], array_map($done, ['a', 'b', 'c', 'd', 'e'], EnvelopeBuilder::PRICED_LABELS));
        $api->shouldNotReceive('updateTabs');
        $api->shouldNotReceive('createTabs');

        $this->artisan('docusign:label-template-fields', ['template_id' => 'tpl-w9'])
            ->expectsOutputToContain('Nothing to change')
            ->assertSuccessful();
    }

    public function test_a_dry_run_changes_nothing(): void
    {
        $api = $this->templatesApi([$this->field('t1', 'units', '')]);
        $api->shouldNotReceive('createTabs');
        $api->shouldNotReceive('deleteTabs');

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
