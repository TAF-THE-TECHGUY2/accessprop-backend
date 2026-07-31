<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\InvestorPasswordResetMail;
use App\Mail\InvestorWelcomeMail;
use App\Models\EmailTemplate;
use App\Models\Investor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
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
        ]);

        $this->guardAgainstForbiddenSyntax($data);

        $template->fill([
            'subject' => $data['subject'],
            'body_html' => $data['bodyHtml'],
            'body_text' => $data['bodyText'] ?? null,
            'is_active' => (bool) ($data['isActive'] ?? $template->is_active),
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
     */
    public function preview(Request $request, string $key): JsonResponse
    {
        $template = EmailTemplate::where('key', $key)->firstOrFail();

        $data = $request->validate([
            'subject' => ['nullable', 'string', 'max:255'],
            'bodyHtml' => ['nullable', 'string'],
            'bodyText' => ['nullable', 'string'],
        ]);

        $this->guardAgainstForbiddenSyntax($data);

        $draft = new EmailTemplate([
            'key' => $template->key,
            'subject' => $data['subject'] ?? $template->subject,
            'body_html' => $data['bodyHtml'] ?? $template->body_html,
            'body_text' => $data['bodyText'] ?? $template->body_text,
        ]);

        $sample = $this->sampleData($key);

        try {
            return response()->json([
                'subject' => $draft->renderSubject($sample),
                'html' => $draft->renderHtml($sample),
                'text' => $draft->renderText($sample),
                'missingVariables' => $draft->missingVariables($sample),
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'message' => 'Template failed to render: '.$e->getMessage(),
            ], 422);
        }
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

        try {
            Mail::to($data['email'])->send($mailable);
        } catch (Throwable $e) {
            return response()->json([
                'message' => 'Send failed: '.$e->getMessage(),
            ], 502);
        }

        return response()->json([
            'message' => "Test email sent to {$data['email']}.",
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
        ];

        if ($withBody) {
            $base['bodyHtml'] = $t->body_html;
            $base['bodyText'] = $t->body_text;
        }

        return $base;
    }
}
