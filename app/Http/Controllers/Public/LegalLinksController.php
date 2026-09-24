<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\LegalPage;
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
        // The create-account form must render even if these records can't be
        // read, so a failure answers with the bundled defaults rather than a
        // 500 the form would have to handle.
        try {
            $setting = Setting::singleton();
            $terms = $setting->terms_of_use_url;
            $privacy = $setting->privacy_policy_url;
            $pages = LegalPage::whereIn('slug', LegalPage::SLUGS)->get()->keyBy('slug');
        } catch (Throwable) {
            $terms = null;
            $privacy = null;
            $pages = collect();
        }

        return response()->json(
            $this->link($pages->get(LegalPage::SLUG_TERMS), $terms ?: Setting::DEFAULT_TERMS_OF_USE_URL, 'termsOfUse')
            + $this->link($pages->get(LegalPage::SLUG_PRIVACY), $privacy ?: Setting::DEFAULT_PRIVACY_POLICY_URL, 'privacyPolicy')
        );
    }

    /**
     * A page written here wins; otherwise the external URL stands.
     *
     * The `Url` key keeps its original name and meaning — somewhere to send the
     * visitor — so an older bundle keeps working. The `Internal` flag tells a
     * newer one whether that destination is a route of ours, which decides
     * whether it can be a client-side navigation.
     *
     * @return array<string, string|bool>
     */
    private function link(?LegalPage $page, string $fallback, string $key): array
    {
        $live = $page && $page->isLive();

        return [
            $key.'Url' => $live ? $page->path() : $fallback,
            $key.'Internal' => $live,
        ];
    }
}
