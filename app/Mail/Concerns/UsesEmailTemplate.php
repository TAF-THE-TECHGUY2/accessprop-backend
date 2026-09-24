<?php

namespace App\Mail\Concerns;

use App\Models\EmailTemplate;
use App\Models\Setting;
use App\Support\MailSender;
use Illuminate\Mail\Mailables\Address;
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

    private ?Setting $resolvedSenderSettings = null;

    private bool $senderSettingsLoaded = false;

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


    /**
     * Who the email comes from, resolved once per send:
     *
     *   this template's override -> platform setting -> config('mail.from')
     *
     * The config fallback is what keeps mail working on a database that is
     * unreachable or not yet migrated.
     */
    protected function templateFrom(string $key): ?Address
    {
        $template = $this->emailTemplate($key);
        $settings = $this->senderSettings();

        $address = $this->firstFilled(
            $template?->from_address,
            $settings?->mail_from_address,
            config('mail.from.address'),
        );

        if ($address === null) {
            return null;
        }

        $name = $this->firstFilled(
            $template?->from_name,
            $settings?->mail_from_name,
            config('mail.from.name'),
        );

        return new Address($address, $name ?? '');
    }

    /**
     * Where replies go. Same precedence; $fallback is the mailable's own
     * hardcoded default, kept for a database that can't be read.
     */
    protected function templateReplyTo(string $key, ?string $fallback = null, string $name = ''): array
    {
        $template = $this->emailTemplate($key);
        $settings = $this->senderSettings();

        $address = $this->firstFilled(
            $template?->reply_to_address,
            $settings?->mail_reply_to_address,
            $fallback,
        );

        return $address === null ? [] : [new Address($address, $name)];
    }

    private function senderSettings(): ?Setting
    {
        if (! $this->senderSettingsLoaded) {
            $this->senderSettingsLoaded = true;
            $this->resolvedSenderSettings = Setting::current();
        }

        return $this->resolvedSenderSettings;
    }

    /** Shared with non-template mail, so both resolve identically. */
    private function firstFilled(?string ...$values): ?string
    {
        return MailSender::firstFilled(...$values);
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
