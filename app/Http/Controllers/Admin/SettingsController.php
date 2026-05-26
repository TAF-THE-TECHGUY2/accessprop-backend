<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
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
        ];
    }
}
