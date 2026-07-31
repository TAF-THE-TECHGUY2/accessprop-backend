<?php

namespace App\Mail\Concerns;

use App\Models\EmailTemplate;
use Illuminate\Mail\Mailables\Content;
use Throwable;

/**
 * Lets a Mailable draw its subject and body from an admin-editable
 * email_templates row, falling back to the bundled Blade views when no active
 * row exists or when rendering the stored template fails.
 */
trait UsesEmailTemplate
{
    private ?EmailTemplate $resolvedTemplate = null;

    private bool $templateLookupDone = false;

    protected function emailTemplate(string $key): ?EmailTemplate
    {
        if (! $this->templateLookupDone) {
            $this->templateLookupDone = true;

            try {
                $this->resolvedTemplate = EmailTemplate::forKey($key);
            } catch (Throwable) {
                // Table missing (pre-migration) or DB unreachable — use Blade.
                $this->resolvedTemplate = null;
            }
        }

        return $this->resolvedTemplate;
    }

    protected function templateSubject(string $key, string $fallback, array $data): string
    {
        $template = $this->emailTemplate($key);

        if (! $template) {
            return $fallback;
        }

        try {
            return $template->renderSubject($data);
        } catch (Throwable) {
            return $fallback;
        }
    }

    protected function templateContent(
        string $key,
        string $fallbackView,
        ?string $fallbackTextView,
        array $data,
    ): Content {
        $template = $this->emailTemplate($key);

        if ($template) {
            try {
                $html = $template->renderHtml($data);
                $text = $template->renderText($data);

                return new Content(
                    htmlString: $html,
                    text: $text !== null ? 'emails.raw-text' : null,
                    with: $data + ['rawText' => $text ?? ''],
                );
            } catch (Throwable) {
                // Stored template is broken — fall through to the Blade view so
                // the investor still receives a working email.
            }
        }

        return new Content(
            view: $fallbackView,
            text: $fallbackTextView,
            with: $data,
        );
    }
}
