<?php

namespace App\Console\Commands;

use App\Services\DocuSign\Client;
use App\Services\DocuSign\EnvelopeBuilder;
use DocuSign\eSign\Model\Tabs;
use DocuSign\eSign\Model\Text;
use Illuminate\Console\Command;

/**
 * Sets up the fields the app fills, for when DocuSign's editor will not.
 *
 * A field is recognised by its Data Label or, failing that, by default text
 * typed into it (e.g. "units"). Each one is given that Data Label, emptied,
 * made optional and the role's alone, and moved to the role the app fills
 * (docusign.prefill_role, or --role): the counter signer by default, since
 * DocuSign shows the first signer's fields to the second but not the reverse.
 */
class DocusignLabelTemplateFields extends Command
{
    protected $signature = 'docusign:label-template-fields
                            {template_id : The template to fix}
                            {--role= : Whose fields they should be: counter_signer or investor (default: the configured prefill role)}
                            {--dry-run : Show what would change, and change nothing}';

    protected $description = 'Label, and move to the right signer, the fields the app fills on a template';

    /** Copied when a field moves to the other signer, so it lands where it was. */
    private const PLACEMENT = [
        'DocumentId', 'PageNumber', 'XPosition', 'YPosition', 'Width', 'Height',
        'Font', 'FontSize', 'FontColor', 'Bold', 'Italic', 'Underline',
        'AnchorString', 'AnchorXOffset', 'AnchorYOffset', 'AnchorUnits', 'AnchorIgnoreIfNotPresent',
    ];

    public function handle(Client $client): int
    {
        $templateId = (string) $this->argument('template_id');
        $target = (string) ($this->option('role') ?: config('docusign.prefill_role', 'counter_signer'));

        if (! in_array($target, ['counter_signer', 'investor'], true)) {
            $this->error('--role must be counter_signer or investor.');

            return self::FAILURE;
        }

        $signers = $client->templates()->get($client->accountId(), $templateId)->getRecipients()->getSigners() ?? [];
        $recipients = [];
        foreach (['counter_signer', 'investor'] as $role) {
            $name = trim((string) config("docusign.roles.{$role}"));
            $signer = collect($signers)->first(fn ($s) => strcasecmp(trim(preg_replace('/\s+/u', ' ', (string) $s->getRoleName())), $name) === 0);

            if (! $signer) {
                $this->error("No \"{$name}\" role on this template.");

                return self::FAILURE;
            }
            $recipients[$role] = (string) $signer->getRecipientId();
        }

        $update = [];   // on the target role already: relabel in place
        $create = [];   // on the other role: recreate on the target...
        $delete = [];   // ...and remove from where it was
        $found = [];

        foreach ($recipients as $role => $recipientId) {
            $tabs = $client->templates()->listTabs($client->accountId(), $recipientId, $templateId)->getTextTabs() ?? [];

            foreach ($tabs as $tab) {
                $label = $this->labelFor($tab);
                if ($label === null) {
                    continue;
                }
                $found[$label] = ($found[$label] ?? 0) + 1;

                if ($role === $target) {
                    if ($tab->getTabLabel() === $label && ! $tab->getValue() && $tab->getRequired() !== 'true' && $tab->getShared() !== 'true') {
                        $this->line("  page {$tab->getPageNumber()}: \"{$label}\" is already set up");

                        continue;
                    }
                    $update[] = $this->setUp((new Text())->setTabId($tab->getTabId()), $label);
                    $this->line(sprintf('  page %s: label %s -> "%s"', $tab->getPageNumber(), json_encode((string) $tab->getTabLabel(), JSON_UNESCAPED_UNICODE), $label));
                } else {
                    $copy = new Text();
                    foreach (self::PLACEMENT as $property) {
                        $copy->{'set'.$property}($tab->{'get'.$property}());
                    }
                    $create[] = $this->setUp($copy, $label);
                    $delete[] = (new Text())->setTabId($tab->getTabId());
                    $this->line(sprintf('  page %s: "%s" moves from the %s to the %s', $tab->getPageNumber(), $label, $this->roleName($role), $this->roleName($target)));
                }
            }
        }

        foreach (EnvelopeBuilder::PRICED_LABELS as $label) {
            if (! isset($found[$label])) {
                $this->warn("  No text field marked \"{$label}\": give one that default text, or that Data Label.");
            } elseif ($found[$label] > 1) {
                $this->line("  \"{$label}\" is on {$found[$label]} fields; each gets the same value.");
            }
        }

        if (! $update && ! $create) {
            $this->info('Nothing to change.');

            return $found ? self::SUCCESS : self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $this->info('Dry run: nothing was changed.');

            return self::SUCCESS;
        }

        if ($update) {
            $client->templates()->updateTabs($client->accountId(), $recipients[$target], $templateId, (new Tabs())->setTextTabs($update));
        }

        if ($create) {
            // Created before the originals go, so a failure leaves them in place.
            $other = $target === 'investor' ? 'counter_signer' : 'investor';
            $client->templates()->createTabs($client->accountId(), $recipients[$target], $templateId, (new Tabs())->setTextTabs($create));
            $client->templates()->deleteTabs($client->accountId(), $recipients[$other], $templateId, (new Tabs())->setTextTabs($delete));
        }

        $this->info(count($update) + count($create).' field(s) updated. Check them with docusign:inspect-template.');

        return self::SUCCESS;
    }

    private function setUp(Text $tab, string $label): Text
    {
        return $tab->setTabLabel($label)
            ->setValue('')
            ->setOriginalValue('')
            ->setRequired('false')
            ->setShared('false');
    }

    private function roleName(string $role): string
    {
        return $role === 'counter_signer' ? 'Counter Signer' : 'Investor';
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
