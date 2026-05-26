<?php

namespace App\Console\Commands;

use App\Services\DocuSign\Client;
use Illuminate\Console\Command;

class DocusignInspectTemplate extends Command
{
    protected $signature = 'docusign:inspect-template {template_id?}';

    protected $description = 'Print roles + tab labels for a DocuSign template';

    public function handle(Client $client): int
    {
        $templateId = $this->argument('template_id')
            ?? (string) config('docusign.templates.subscription_agreement.accredited');

        if ($templateId === '') {
            $this->error('No template ID provided and none configured.');

            return self::FAILURE;
        }

        $tpl = $client->templates()->get($client->accountId(), $templateId);

        $this->info("Template: {$tpl->getName()}");
        $this->line("ID: {$tpl->getTemplateId()}");
        $this->line('');

        foreach ($tpl->getRecipients()->getSigners() ?? [] as $signer) {
            $this->line(sprintf(
                '  Role: %s  (routingOrder=%s)',
                $signer->getRoleName(),
                $signer->getRoutingOrder()
            ));

            $tabs = $signer->getTabs();
            if (! $tabs) {
                $this->line('    (no tabs assigned)');

                continue;
            }

            $print = function (string $type, $items) {
                foreach (($items ?? []) as $t) {
                    $this->line(sprintf('    %s: %s', $type, $t->getTabLabel() ?? '(unlabeled)'));
                }
            };

            $print('text', $tabs->getTextTabs());
            $print('sign', $tabs->getSignHereTabs());
            $print('date', $tabs->getDateSignedTabs());
            $print('init', $tabs->getInitialHereTabs());
            $print('checkbox', $tabs->getCheckboxTabs());
        }

        return self::SUCCESS;
    }
}
