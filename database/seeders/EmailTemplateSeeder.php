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
        foreach ($this->templates() as $definition) {
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
                'description' => 'Sent automatically when an investor completes onboarding and their account is created.',
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
        ];
    }

    private function view(string $name): string
    {
        $path = resource_path("views/emails/{$name}.blade.php");

        return is_file($path) ? file_get_contents($path) : '';
    }
}
