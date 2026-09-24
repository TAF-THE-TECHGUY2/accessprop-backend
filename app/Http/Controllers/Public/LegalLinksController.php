<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Throwable;

/**
 * The legal links the create-account page renders, for visitors who have no
 * account yet.
 *
 * Deliberately its own endpoint rather than a public view of /settings: the
 * rest of that record (support address, SLA hours, demo payment mode) is
 * operational detail that has no business being readable by anonymous callers.
 */
class LegalLinksController extends Controller
{
    public function __invoke(): JsonResponse
    {
        // The create-account form must render even if this record can't be
        // read, so a failure answers with the bundled defaults rather than a
        // 500 the form would have to handle.
        try {
            $setting = Setting::singleton();
            $terms = $setting->terms_of_use_url;
            $privacy = $setting->privacy_policy_url;
        } catch (Throwable) {
            $terms = null;
            $privacy = null;
        }

        return response()->json([
            'termsOfUseUrl' => $terms ?: Setting::DEFAULT_TERMS_OF_USE_URL,
            'privacyPolicyUrl' => $privacy ?: Setting::DEFAULT_PRIVACY_POLICY_URL,
        ]);
    }
}
