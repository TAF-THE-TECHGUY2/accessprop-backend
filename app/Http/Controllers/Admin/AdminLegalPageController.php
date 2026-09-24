<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LegalPage;
use App\Support\LegalHtml;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Editing the legal pages.
 *
 * Saving and publishing are separate actions on purpose: legal wording gets
 * drafted over several sittings, and a half-finished paragraph should not be
 * what an investor agrees to in the meantime.
 */
class AdminLegalPageController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => LegalPage::orderBy('slug')->get()->map(fn (LegalPage $p) => $this->shape($p)),
        ]);
    }

    public function show(string $slug): JsonResponse
    {
        return response()->json($this->shape($this->find($slug)));
    }

    public function update(Request $request, string $slug): JsonResponse
    {
        $page = $this->find($slug);

        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:200'],
            'bodyHtml' => ['sometimes', 'nullable', 'string', 'max:400000'],
            'published' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('title', $data)) {
            $page->title = $data['title'];
        }

        if (array_key_exists('bodyHtml', $data)) {
            $page->body_html = LegalHtml::clean($data['bodyHtml']);
        }

        if (array_key_exists('published', $data)) {
            // Publishing an empty page would put a blank document in front of
            // an investor under a heading that says Terms of Use.
            if ($data['published'] && trim((string) $page->body_html) === '') {
                return response()->json([
                    'message' => 'Add some content before publishing this page.',
                ], 422);
            }

            $page->published_at = $data['published'] ? now() : null;
        }

        $page->updated_by = $request->user()?->id;
        $page->save();

        return response()->json($this->shape($page->fresh()));
    }

    private function find(string $slug): LegalPage
    {
        abort_unless(in_array($slug, LegalPage::SLUGS, true), 404);

        return LegalPage::where('slug', $slug)->firstOrFail();
    }

    private function shape(LegalPage $page): array
    {
        return [
            'slug' => $page->slug,
            'title' => $page->title,
            'bodyHtml' => $page->body_html,
            'published' => $page->published_at !== null,
            'live' => $page->isLive(),
            'path' => $page->path(),
            'updatedAt' => optional($page->updated_at)->toIso8601String(),
            'publishedAt' => optional($page->published_at)->toIso8601String(),
        ];
    }
}
