<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Rules\VerifiedSendingDomain;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    private const FIELD_MAP = [
        'organizationName' => 'organization_name',
        'apiEnvironment' => 'api_environment',
        'reviewSlaHours' => 'review_sla_hours',
        'notifyOnSubmission' => 'notify_on_submission',
        'notifyOnFunding' => 'notify_on_funding',
        'autoActivateDashboard' => 'auto_activate_dashboard',
        'supportEmail' => 'support_email',
        'defaultCountry' => 'default_country',
        'allowParallelOnboarding' => 'allow_parallel_onboarding',
        'demoPaymentsEnabled' => 'demo_payments_enabled',
        'termsOfUseUrl' => 'terms_of_use_url',
        'privacyPolicyUrl' => 'privacy_policy_url',
        'loginBackUrl' => 'login_back_url',
        'mailFromName' => 'mail_from_name',
        'mailFromAddress' => 'mail_from_address',
        'mailReplyToAddress' => 'mail_reply_to_address',
    ];

    public function show(): JsonResponse
    {
        return response()->json($this->shape(Setting::singleton()));
    }

    public function update(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'organizationName' => ['sometimes', 'string', 'max:255'],
            'apiEnvironment' => ['sometimes', 'string', 'max:255'],
            'reviewSlaHours' => ['sometimes', 'integer', 'min:1', 'max:720'],
            'notifyOnSubmission' => ['sometimes', 'boolean'],
            'notifyOnFunding' => ['sometimes', 'boolean'],
            'autoActivateDashboard' => ['sometimes', 'boolean'],
            'supportEmail' => ['sometimes', 'email'],
            'defaultCountry' => ['sometimes', 'string', 'max:255'],
            'allowParallelOnboarding' => ['sometimes', 'boolean'],
            'demoPaymentsEnabled' => ['sometimes', 'boolean'],
            // Public-facing links: a typo here is visible to every investor
            // on the create-account page, so reject anything that isn't a URL.
            'termsOfUseUrl' => ['sometimes', 'url', 'max:2048'],
            'privacyPolicyUrl' => ['sometimes', 'url', 'max:2048'],
            // Nullable: cleared means hide the link, not link to nothing.
            'loginBackUrl' => ['sometimes', 'nullable', 'url', 'max:2048'],
            // The name every investor sees in their inbox.
            'mailFromName' => ['sometimes', 'string', 'max:255'],
            'mailFromAddress' => ['sometimes', 'email', 'max:255', new VerifiedSendingDomain],
            // Not authenticated, so it may live on any domain.
            'mailReplyToAddress' => ['sometimes', 'nullable', 'email', 'max:255'],
        ]);

        $setting = Setting::singleton();

        $updates = [];
        foreach ($payload as $field => $value) {
            $updates[self::FIELD_MAP[$field]] = $value;
        }

        if (! empty($updates)) {
            $setting->update($updates);
        }

        return response()->json($this->shape($setting->fresh()));
    }

    private function shape(Setting $setting): array
    {
        return [
            'organizationName' => $setting->organization_name,
            'apiEnvironment' => $setting->api_environment,
            'reviewSlaHours' => $setting->review_sla_hours,
            'notifyOnSubmission' => $setting->notify_on_submission,
            'notifyOnFunding' => $setting->notify_on_funding,
            'autoActivateDashboard' => $setting->auto_activate_dashboard,
            'supportEmail' => $setting->support_email,
            'defaultCountry' => $setting->default_country,
            'allowParallelOnboarding' => $setting->allow_parallel_onboarding,
            'demoPaymentsEnabled' => $setting->demo_payments_enabled,
            'termsOfUseUrl' => $setting->terms_of_use_url,
            'privacyPolicyUrl' => $setting->privacy_policy_url,
            'loginBackUrl' => $setting->login_back_url,
            'mailFromName' => $setting->mail_from_name,
            'mailFromAddress' => $setting->mail_from_address,
            'mailReplyToAddress' => $setting->mail_reply_to_address,
            // Read-only: lets the admin UI say which domain is allowed.
            'mailSendingDomain' => VerifiedSendingDomain::sendingDomain(),
        ];
    }
}
