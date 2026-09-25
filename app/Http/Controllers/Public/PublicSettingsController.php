<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Throwable;

/**
 * The handful of admin-editable values the public pages need before anyone has
 * signed in: the legal links on the create-account form and the Back link on
 * the sign-in page.
 *
 * Deliberately its own endpoint rather than a public view of /settings: the
 * rest of that record (support address, SLA hours, demo payment mode, sender
 * identity) is operational detail that has no business being readable by
 * anonymous callers. Anything added here is a deliberate publication.
 */
class PublicSettingsController extends Controller
{
    public function __invoke(): JsonResponse
    {
        // These pages must render even if the record can't be read, so a
        // failure answers with the bundled defaults rather than a 500 the
        // forms would have to handle.
        try {
            $setting = Setting::singleton();
            $terms = $setting->terms_of_use_url;
            $privacy = $setting->privacy_policy_url;
            $back = $setting->login_back_url;
        } catch (Throwable) {
            $terms = null;
            $privacy = null;
            $back = Setting::DEFAULT_LOGIN_BACK_URL;
        }

        return response()->json([
            // Blank falls back: the consent checkboxes must always have
            // somewhere to point.
            'termsOfUseUrl' => $terms ?: Setting::DEFAULT_TERMS_OF_USE_URL,
            'privacyPolicyUrl' => $privacy ?: Setting::DEFAULT_PRIVACY_POLICY_URL,
            // Blank means the admin wants no Back link, so it stays blank and
            // the sign-in page renders nothing.
            'loginBackUrl' => $back ?: null,
        ]);
    }
}
