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

        // Every recipient, not only signers: a template can also carry
        // copy-receivers, viewers or blank placeholders, and DocuSign refuses
        // an envelope where any of them has no email.
        $recipients = $tpl->getRecipients();
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
        $this->line('All recipients:');
        foreach ($kinds as $kind => $list) {
            foreach ($list ?? [] as $r) {
                $this->line(sprintf(
                    '  - %s: role "%s", recipientId=%s, routingOrder=%s, name=%s, email=%s',
                    $kind,
                    $r->getRoleName() ?? '',
                    $r->getRecipientId(),
                    $r->getRoutingOrder(),
                    $r->getName() !== null && $r->getName() !== '' ? $r->getName() : '(blank)',
                    method_exists($r, 'getEmail') && $r->getEmail() ? $r->getEmail() : '(blank)',
                ));
            }
        }
        $this->line('');

        foreach ($tpl->getRecipients()->getSigners() ?? [] as $signer) {
            $this->line(sprintf(
                '  Role: %s  (recipientId=%s, routingOrder=%s)',
                $signer->getRoleName(),
                $signer->getRecipientId(),
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
