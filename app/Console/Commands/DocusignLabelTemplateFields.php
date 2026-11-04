<?php

namespace App\Console\Commands;

use App\Services\DocuSign\Client;
use App\Services\DocuSign\EnvelopeBuilder;
use DocuSign\eSign\Model\Tabs;
use DocuSign\eSign\Model\Text;
use Illuminate\Console\Command;

/**
 * Sets the Data Labels the app fills, for when DocuSign's editor will not show
 * the setting. Mark each field by typing its label as the field's default text
 * (e.g. "units"); this moves that text into the Data Label and clears it, and
 * makes the field optional and the Investor's alone, since the app fills it.
 */
class DocusignLabelTemplateFields extends Command
{
    protected $signature = 'docusign:label-template-fields
                            {template_id : The template to fix}
                            {--dry-run : Show what would change, and change nothing}';

    protected $description = 'Turn fields whose default text is a prefill label into fields with that Data Label';

    public function handle(Client $client): int
    {
        $templateId = (string) $this->argument('template_id');
        $roleName = trim((string) config('docusign.roles.investor'));

        $investor = collect($client->templates()->get($client->accountId(), $templateId)->getRecipients()->getSigners() ?? [])
            ->first(fn ($s) => strcasecmp(trim(preg_replace('/\s+/u', ' ', (string) $s->getRoleName())), $roleName) === 0);

        if (! $investor) {
            $this->error("No \"{$roleName}\" role on this template.");

            return self::FAILURE;
        }

        $recipientId = (string) $investor->getRecipientId();
        $textTabs = $client->templates()->listTabs($client->accountId(), $recipientId, $templateId)->getTextTabs() ?? [];

        $changes = [];
        $found = [];
        foreach ($textTabs as $tab) {
            $label = $this->labelFor($tab);
            if ($label === null) {
                continue;
            }

            $found[$label] = ($found[$label] ?? 0) + 1;
            $changes[] = (new Text())
                ->setTabId($tab->getTabId())
                ->setTabLabel($label)
                ->setValue('')
                ->setOriginalValue('')
                ->setRequired('false')
                ->setShared('false');

            $this->line(sprintf(
                '  page %s: label %s -> "%s"%s',
                $tab->getPageNumber(),
                json_encode((string) $tab->getTabLabel(), JSON_UNESCAPED_UNICODE),
                $label,
                $tab->getValue() ? ' (clearing default text)' : '',
            ));
        }

        foreach (EnvelopeBuilder::PRICED_LABELS as $label) {
            if (! isset($found[$label])) {
                $this->warn("  No Investor text field marked \"{$label}\": give one that default text, or that Data Label.");
            } elseif ($found[$label] > 1) {
                $this->line("  \"{$label}\" is on {$found[$label]} fields; each gets the same value.");
            }
        }

        if (! $changes) {
            $this->error('Nothing to change.');

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $this->info('Dry run: nothing was changed.');

            return self::SUCCESS;
        }

        $client->templates()->updateTabs($client->accountId(), $recipientId, $templateId, (new Tabs())->setTextTabs($changes));
        $this->info(count($changes).' field(s) updated. Check them with docusign:inspect-template.');

        return self::SUCCESS;
    }

    /** The prefill label this field is marked with, by Data Label or by default text. */
    private function labelFor(Text $tab): ?string
    {
        foreach ([$tab->getTabLabel(), $tab->getValue(), $tab->getOriginalValue()] as $candidate) {
            $candidate = strtolower(trim((string) $candidate));
            if (in_array($candidate, EnvelopeBuilder::PRICED_LABELS, true)) {
                return $candidate;
            }
        }

        return null;
    }
}
