<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\InvestorPasswordResetMail;
use App\Mail\InvestorWelcomeMail;
use App\Models\EmailLog;
use App\Models\EmailTemplate;
use App\Models\Investor;
use App\Models\Setting;
use App\Rules\VerifiedSendingDomain;
use App\Support\MailSender;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class EmailTemplateController extends Controller
{
    public function index(): JsonResponse
    {
        $items = EmailTemplate::query()
            ->orderBy('name')
            ->get()
            ->map(fn (EmailTemplate $t) => $this->shape($t, withBody: false));

        return response()->json(['data' => $items]);
    }

    public function show(string $key): JsonResponse
    {
        $template = EmailTemplate::where('key', $key)->firstOrFail();

        return response()->json($this->shape($template, withBody: true));
    }

    public function update(Request $request, string $key): JsonResponse
    {
        $template = EmailTemplate::where('key', $key)->firstOrFail();

        $data = $request->validate([
            'subject' => ['required', 'string', 'max:255'],
            'bodyHtml' => ['required', 'string'],
            'bodyText' => ['nullable', 'string'],
            'isActive' => ['nullable', 'boolean'],
            // Blank means inherit the platform default from Settings.
            'fromName' => ['nullable', 'string', 'max:255'],
            'fromAddress' => ['nullable', 'email', 'max:255', new VerifiedSendingDomain],
            'replyToAddress' => ['nullable', 'email', 'max:255'],
        ]);

        $this->guardAgainstForbiddenSyntax($data);

        $template->fill([
            'subject' => $data['subject'],
            'body_html' => $data['bodyHtml'],
            'body_text' => $data['bodyText'] ?? null,
            'is_active' => (bool) ($data['isActive'] ?? $template->is_active),
            'from_name' => $this->blankToNull($data['fromName'] ?? null),
            'from_address' => $this->blankToNull($data['fromAddress'] ?? null),
            'reply_to_address' => $this->blankToNull($data['replyToAddress'] ?? null),
            'updated_by' => $request->user()->id ?? null,
        ]);

        // Reject a template that fails to compile before it reaches an investor.
        try {
            $sample = $this->sampleData($key);
            $template->renderSubject($sample);
            $template->renderHtml($sample);
            $template->renderText($sample);
        } catch (Throwable $e) {
            throw ValidationException::withMessages([
                'bodyHtml' => 'Template failed to render: '.$e->getMessage(),
            ]);
        }

        $template->save();

        return response()->json($this->shape($template->fresh(), withBody: true));
    }

    /**
     * Renders the supplied (possibly unsaved) template against sample data so
     * the admin can see the result before committing it.
     *
     * The sender is resolved and returned alongside the body. An email is
     * addressed as much as it is written, and the preview previously showed
     * only the subject — so an admin who changed the From address had no way to
     * see the change take effect, and no way to notice a blank field silently
     * inheriting the platform default.
     */
    public function preview(Request $request, string $key): JsonResponse
    {
        $template = EmailTemplate::where('key', $key)->firstOrFail();

        $data = $request->validate([
            'subject' => ['nullable', 'string', 'max:255'],
            'bodyHtml' => ['nullable', 'string'],
            'bodyText' => ['nullable', 'string'],
            // The unsaved sender fields, so the preview reflects the editor
            // rather than the last save.
            'fromName' => ['nullable', 'string', 'max:255'],
            'fromAddress' => ['nullable', 'email', 'max:255'],
            'replyToAddress' => ['nullable', 'email', 'max:255'],
        ]);

        $this->guardAgainstForbiddenSyntax($data);

        // `??` cannot tell a field the editor didn't send from one the admin
        // deliberately emptied: clearing the plain-text box sends null, which
        // fell back to the stored copy and previewed text that was no longer
        // there. Presence of the key is the question, not its value.
        $draft = new EmailTemplate([
            'key' => $template->key,
            'subject' => $request->has('subject') ? (string) $data['subject'] : $template->subject,
            'body_html' => $request->has('bodyHtml') ? (string) $data['bodyHtml'] : $template->body_html,
            'body_text' => $request->has('bodyText') ? $data['bodyText'] : $template->body_text,
        ]);

        $sample = $this->sampleData($key);

        try {
            return response()->json([
                'subject' => $draft->renderSubject($sample),
                'html' => $draft->renderHtml($sample),
                'text' => $draft->renderText($sample),
                'missingVariables' => $draft->missingVariables($sample),
                'sender' => $this->resolvedSender($template, $data),
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'message' => 'Template failed to render: '.$e->getMessage(),
            ], 422);
        }
    }

    /**
     * Who this email will actually come from, following the same precedence a
     * real send does: the value in the editor, then what is saved on the
     * template, then the platform default, then config.
     *
     * `inherited` flags a field the admin left blank, so the preview can say
     * where the address came from rather than just showing one.
     */
    private function resolvedSender(EmailTemplate $template, array $draft): array
    {
        $settings = Setting::current();

        $fromAddress = MailSender::firstFilled(
            $draft['fromAddress'] ?? null,
            $template->from_address,
            $settings?->mail_from_address,
            config('mail.from.address'),
        );

        $fromName = MailSender::firstFilled(
            $draft['fromName'] ?? null,
            $template->from_name,
            $settings?->mail_from_name,
            config('mail.from.name'),
        );

        $replyTo = MailSender::firstFilled(
            $draft['replyToAddress'] ?? null,
            $template->reply_to_address,
            $settings?->mail_reply_to_address,
        );

        return [
            'fromName' => $fromName,
            'fromAddress' => $fromAddress,
            'replyToAddress' => $replyTo,
            'inherited' => [
                'fromName' => MailSender::firstFilled($draft['fromName'] ?? null, $template->from_name) === null,
                'fromAddress' => MailSender::firstFilled($draft['fromAddress'] ?? null, $template->from_address) === null,
                'replyToAddress' => MailSender::firstFilled($draft['replyToAddress'] ?? null, $template->reply_to_address) === null,
            ],
        ];
    }

    /**
     * Sends the saved template to an arbitrary address using the real Mailable,
     * so what arrives matches exactly what an investor would receive.
     */
    public function test(Request $request, string $key): JsonResponse
    {
        EmailTemplate::where('key', $key)->firstOrFail();

        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        $investor = $this->sampleInvestor();

        $mailable = match ($key) {
            'investor_welcome' => new InvestorWelcomeMail($investor),
            'investor_password_reset' => new InvestorPasswordResetMail(
                $investor,
                rtrim(config('app.frontend_url', 'https://investor.ap.boston'), '/').'/reset-password?token=sample-token',
            ),
            default => null,
        };

        if (! $mailable) {
            return response()->json([
                'message' => "No test sender is configured for template '{$key}'.",
            ], 422);
        }

        $error = null;

        try {
            Mail::to($data['email'])->send($mailable);
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }

        // Recorded either way. A test send left no trace anywhere in the admin,
        // so "did that actually go out?" had no answer short of the mail
        // provider's own dashboard.
        $this->log($key, $data['email'], $mailable->envelope()->subject, $error === null);

        if ($error !== null) {
            return response()->json(['message' => 'Send failed: '.$error], 502);
        }

        return response()->json([
            'message' => "Test email sent to {$data['email']}. It will show in Email logs.",
        ]);
    }

    /**
     * Restores a template from the Blade file bundled with the release.
     */
    public function reset(Request $request, string $key): JsonResponse
    {
        $template = EmailTemplate::where('key', $key)->firstOrFail();

        $views = match ($key) {
            'investor_welcome' => ['investor-welcome', 'investor-welcome-text', InvestorWelcomeMail::DEFAULT_SUBJECT],
            'investor_password_reset' => ['investor-password-reset', 'investor-password-reset-text', InvestorPasswordResetMail::DEFAULT_SUBJECT],
            default => null,
        };

        if (! $views) {
            return response()->json([
                'message' => "No bundled default exists for template '{$key}'.",
            ], 422);
        }

        [$htmlView, $textView, $subject] = $views;

        $template->update([
            'subject' => $subject,
            'body_html' => $this->bladeSource($htmlView),
            'body_text' => $this->bladeSource($textView),
            'updated_by' => $request->user()->id ?? null,
        ]);

        return response()->json($this->shape($template->fresh(), withBody: true));
    }

    private function log(string $key, string $recipient, string $subject, bool $sent): void
    {
        EmailLog::create([
            'code' => 'eml-test-'.$key.'-'.now()->timestamp.'-'.Str::lower(Str::random(6)),
            'recipient' => $recipient,
            'type' => $key.'_test',
            'subject' => $subject,
            'status' => $sent ? 'sent' : 'failed',
            'sent_at' => now(),
        ]);
    }

    private function guardAgainstForbiddenSyntax(array $data): void
    {
        foreach (['subject', 'bodyHtml', 'bodyText'] as $field) {
            if (EmailTemplate::containsForbiddenSyntax($data[$field] ?? null)) {
                throw ValidationException::withMessages([
                    $field => 'Templates may not contain PHP or Blade include/extends directives.',
                ]);
            }
        }
    }

    /**
     * A non-persisted investor so previews never leak real investor data and
     * work on an empty database.
     */
    private function sampleInvestor(): Investor
    {
        return new Investor([
            'code' => 'inv-1000',
            'name' => 'Alex Sample',
            'email' => 'sample.investor@example.com',
        ]);
    }

    private function sampleData(string $key): array
    {
        $investor = $this->sampleInvestor();

        return match ($key) {
            'investor_password_reset' => (new InvestorPasswordResetMail(
                $investor,
                'https://investor.ap.boston/reset-password?token=sample-token',
            ))->templateData(),
            default => (new InvestorWelcomeMail($investor))->templateData(),
        };
    }

    private function bladeSource(string $name): string
    {
        $path = resource_path("views/emails/{$name}.blade.php");

        return is_file($path) ? file_get_contents($path) : '';
    }

    /** An empty box in the editor means "inherit", not "send with no name". */
    private function blankToNull(?string $value): ?string
    {
        return $value !== null && trim($value) !== '' ? trim($value) : null;
    }

    /** The platform default a blank override falls back to. */
    private function inheritedSender(): array
    {
        $setting = Setting::current();

        return [
            'fromName' => $setting?->mail_from_name ?: config('mail.from.name'),
            'fromAddress' => $setting?->mail_from_address ?: config('mail.from.address'),
            'replyToAddress' => $setting?->mail_reply_to_address,
            'sendingDomain' => VerifiedSendingDomain::sendingDomain(),
        ];
    }

    private function shape(EmailTemplate $t, bool $withBody): array
    {
        $base = [
            'key' => $t->key,
            'name' => $t->name,
            'description' => $t->description,
            'subject' => $t->subject,
            'variables' => $t->variables ?? [],
            'isActive' => (bool) $t->is_active,
            'updatedAt' => optional($t->updated_at)->toIso8601String(),
            'fromName' => $t->from_name,
            'fromAddress' => $t->from_address,
            'replyToAddress' => $t->reply_to_address,
            // What this template resolves to when its overrides are blank, so
            // the editor can show the inherited value as placeholder text.
            'inherited' => $this->inheritedSender(),
        ];

        if ($withBody) {
            $base['bodyHtml'] = $t->body_html;
            $base['bodyText'] = $t->body_text;
        }

        return $base;
    }
}
