<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Communication;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminCommunicationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $items = Communication::query()
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($c) => $this->shape($c));

        return response()->json(['data' => $items]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'string', 'in:update,newsletter'],
            'title' => ['required', 'string', 'max:255'],
            'summary' => ['nullable', 'string', 'max:500'],
            'bodyHtml' => ['required', 'string'],
            'audience' => ['nullable', 'string', 'max:100'],
            'isPublished' => ['nullable', 'boolean'],
            'publishedAt' => ['nullable', 'date'],
        ]);

        $isPublished = (bool) ($data['isPublished'] ?? false);

        $comm = Communication::create([
            'type' => $data['type'],
            'title' => $data['title'],
            'summary' => $data['summary'] ?? null,
            'body_html' => $data['bodyHtml'],
            'audience' => $data['audience'] ?? 'all',
            'is_published' => $isPublished,
            'published_at' => $isPublished
                ? ($data['publishedAt'] ?? now())
                : null,
            'created_by' => $request->user()->id ?? null,
        ]);

        return response()->json($this->shape($comm), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $comm = Communication::findOrFail($id);

        $data = $request->validate([
            'type' => ['sometimes', 'string', 'in:update,newsletter'],
            'title' => ['sometimes', 'string', 'max:255'],
            'summary' => ['sometimes', 'nullable', 'string', 'max:500'],
            'bodyHtml' => ['sometimes', 'string'],
            'audience' => ['sometimes', 'string', 'max:100'],
            'isPublished' => ['sometimes', 'boolean'],
            'publishedAt' => ['sometimes', 'nullable', 'date'],
        ]);

        $map = [
            'type' => 'type',
            'title' => 'title',
            'summary' => 'summary',
            'bodyHtml' => 'body_html',
            'audience' => 'audience',
            'isPublished' => 'is_published',
            'publishedAt' => 'published_at',
        ];
        $updates = [];
        foreach ($data as $k => $v) {
            $updates[$map[$k]] = $v;
        }
        // If transitioning to published and no publish date set, stamp it now.
        if (isset($updates['is_published']) && $updates['is_published'] && empty($comm->published_at) && empty($updates['published_at'])) {
            $updates['published_at'] = now();
        }

        if (! empty($updates)) {
            $comm->update($updates);
        }

        return response()->json($this->shape($comm->fresh()));
    }

    public function destroy(int $id): JsonResponse
    {
        Communication::findOrFail($id)->delete();

        return response()->json(['message' => 'deleted']);
    }

    private function shape(Communication $c): array
    {
        return [
            'id' => $c->id,
            'type' => $c->type,
            'title' => $c->title,
            'summary' => $c->summary,
            'bodyHtml' => $c->body_html,
            'audience' => $c->audience,
            'isPublished' => $c->is_published,
            'publishedAt' => optional($c->published_at)->toIso8601String(),
            'createdAt' => optional($c->created_at)->toIso8601String(),
            'updatedAt' => optional($c->updated_at)->toIso8601String(),
        ];
    }
}
