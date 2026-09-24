<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\LegalPage;
use Illuminate\Http\JsonResponse;

/**
 * Serves a legal page to anyone, signed in or not.
 *
 * Public by necessity: the create-account form links to these before the
 * visitor has an account, and a terms page behind a login is no terms page at
 * all.
 *
 * Only live pages are served. An unpublished or empty one answers 404 so the
 * frontend can fall back to the external link rather than render a blank page
 * headed "Terms of Use".
 */
class LegalPageController extends Controller
{
    public function show(string $slug): JsonResponse
    {
        $page = LegalPage::where('slug', $slug)->first();

        if (! $page || ! $page->isLive()) {
            return response()->json(['message' => 'That page is not available.'], 404);
        }

        return response()->json([
            'slug' => $page->slug,
            'title' => $page->title,
            'bodyHtml' => $page->body_html,
            'updatedAt' => optional($page->published_at)->toIso8601String(),
        ]);
    }
}
