<?php

namespace App\Http\Controllers\Investor;

use App\Http\Controllers\Controller;
use App\Models\PortalDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class InvestorPortalDocumentsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $investor = $request->user();

        // Documents visible to this investor:
        //   - scope=global (everyone)
        //   - scope=fund AND fund matches one of the investor's holdings
        //   - scope=investor AND investor_id = this investor
        $fundIds = $investor->holdings()->pluck('fund_id');

        $documents = PortalDocument::query()
            ->where(function ($q) use ($investor, $fundIds) {
                $q->where('scope', 'global')
                    ->orWhere(function ($q2) use ($fundIds) {
                        $q2->where('scope', 'fund')->whereIn('fund_id', $fundIds);
                    })
                    ->orWhere(function ($q3) use ($investor) {
                        $q3->where('scope', 'investor')->where('investor_id', $investor->id);
                    });
            })
            ->orderBy('category')
            ->orderByDesc('document_dated_at')
            ->get();

        $grouped = [
            'legal' => [],
            'operational' => [],
            'tax' => [],
            'financial' => [],
        ];

        foreach ($documents as $doc) {
            $bucket = $doc->category;
            if (! isset($grouped[$bucket])) {
                $grouped[$bucket] = [];
            }
            $grouped[$bucket][] = [
                'id' => $doc->id,
                'title' => $doc->title,
                'subcategory' => $doc->subcategory,
                'sizeBytes' => $doc->file_size_bytes,
                'mimeType' => $doc->mime_type,
                'documentDatedAt' => optional($doc->document_dated_at)->toIso8601String(),
                'scope' => $doc->scope,
            ];
        }

        return response()->json(['data' => $grouped]);
    }

    public function download(Request $request, int $id): RedirectResponse|JsonResponse
    {
        $investor = $request->user();
        $document = PortalDocument::findOrFail($id);

        // Authorise: must be global, or fund-scoped on a fund the investor holds,
        // or investor-scoped to this investor.
        $allowed = $document->scope === 'global'
            || ($document->scope === 'investor' && $document->investor_id === $investor->id)
            || ($document->scope === 'fund' && $investor->holdings()->where('fund_id', $document->fund_id)->exists());

        if (! $allowed) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        // Files live behind file_url. In production this would be a signed S3
        // URL; for now we just redirect to whatever URL is stored.
        return redirect()->away($document->file_url);
    }
}
