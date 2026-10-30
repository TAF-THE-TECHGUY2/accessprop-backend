<?php

namespace Database\Seeders;

use App\Models\EmailTemplate;
use Illuminate\Database\Seeder;

class EmailTemplateSeeder extends Seeder
{
    /**
     * Imports the bundled Blade views as the initial editable rows.
     * Safe to re-run: existing rows are left untouched so admin edits survive.
     */
    public function run(): void
    {
        $this->seed();
    }

    /** @param  array<int, string>|null  $only  just these keys; null for all */
    public function seed(?array $only = null): void
    {
        foreach ($this->templates() as $definition) {
            if ($only !== null && ! in_array($definition['key'], $only, true)) {
                continue;
            }

            if (EmailTemplate::where('key', $definition['key'])->exists()) {
                continue;
            }

            EmailTemplate::create($definition);
        }
    }

    private function templates(): array
    {
        return [
            [
                'key' => 'investor_welcome',
                'name' => 'Investor welcome',
                'description' => 'Sent automatically when an investor creates their account.',
                'subject' => 'Welcome to Access Properties',
                'body_html' => $this->view('investor-welcome'),
                'body_text' => $this->view('investor-welcome-text'),
                'variables' => [
                    ['name' => 'firstName', 'description' => "Investor's first name", 'required' => true],
                    ['name' => 'investorCode', 'description' => 'Reference code, e.g. inv-1006', 'required' => false],
                    ['name' => 'investor', 'description' => 'Full investor record — use $investor->email', 'required' => false],
                ],
                'is_active' => true,
            ],
            [
                'key' => 'investor_password_reset',
                'name' => 'Password reset',
                'description' => 'Sent when an investor requests a password reset link.',
                'subject' => 'Reset your Access Properties password',
                'body_html' => $this->view('investor-password-reset'),
                'body_text' => $this->view('investor-password-reset-text'),
                'variables' => [
                    ['name' => 'firstName', 'description' => "Investor's first name", 'required' => true],
                    ['name' => 'resetUrl', 'description' => 'One-time password reset link', 'required' => true],
                    ['name' => 'investor', 'description' => 'Full investor record — use $investor->email', 'required' => false],
                ],
                'is_active' => true,
            ],
            [
                'key' => 'agreement_countersign_request',
                'name' => 'Agreement: countersign request',
                'description' => "Sent to the counter signer when a subscription agreement is ready, with a signing link (no sign-in). Reminders reuse it with \"Reminder:\" before the subject.",
                'subject' => 'Subscription agreement ready for countersignature — {{ $investorName }}',
                'body_html' => $this->view('agreement-countersign-request'),
                'body_text' => $this->view('agreement-countersign-request-text'),
                'variables' => [
                    ['name' => 'firstName', 'description' => "Counter signer's first name", 'required' => false],
                    ['name' => 'investorName', 'description' => "The investor's full name", 'required' => false],
                    ['name' => 'fundName', 'description' => 'Fund the investor is subscribing to', 'required' => false],
                    ['name' => 'signUrl', 'description' => 'Signing link (opens DocuSign, no sign-in)', 'required' => true],
                ],
                'is_active' => true,
            ],
            [
                'key' => 'agreement_sign_request',
                'name' => 'Agreement: investor sign request',
                'description' => "Sent to the investor once their documents are countersigned and ready, with a signing link (no sign-in). Reminders reuse it with \"Reminder:\" before the subject.",
                'subject' => 'Your subscription documents are ready to sign',
                'body_html' => $this->view('agreement-sign-request'),
                'body_text' => $this->view('agreement-sign-request-text'),
                'variables' => [
                    ['name' => 'firstName', 'description' => "Investor's first name", 'required' => false],
                    ['name' => 'investorName', 'description' => "The investor's full name", 'required' => false],
                    ['name' => 'fundName', 'description' => 'Fund the investor is subscribing to', 'required' => false],
                    ['name' => 'signUrl', 'description' => 'Signing link (opens DocuSign, no sign-in)', 'required' => true],
                ],
                'is_active' => true,
            ],
            [
                'key' => 'agreement_completed',
                'name' => 'Agreement: fully signed',
                'description' => 'Sent to the investor once everyone has signed.',
                'subject' => 'Your subscription documents are signed',
                'body_html' => $this->view('agreement-completed'),
                'body_text' => $this->view('agreement-completed-text'),
                'variables' => [
                    ['name' => 'firstName', 'description' => "Investor's first name", 'required' => false],
                    ['name' => 'fundName', 'description' => 'Fund the investor is subscribing to', 'required' => false],
                    ['name' => 'portalUrl', 'description' => 'Investor portal sign-in page', 'required' => false],
                ],
                'is_active' => true,
            ],
        ];
    }

    private function view(string $name): string
    {
        $path = resource_path("views/emails/{$name}.blade.php");

        return is_file($path) ? file_get_contents($path) : '';
    }
}
