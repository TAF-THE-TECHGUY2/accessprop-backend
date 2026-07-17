<?php

namespace App\Http\Controllers\Investor;

use App\Http\Controllers\Controller;
use App\Models\Fund;
use App\Models\Investor;
use App\Models\PortalDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InvestorPortalDocumentsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $investor = $request->user();

        // Documents visible to this investor:
        //   - scope=global (everyone)
        //   - scope=fund AND fund matches a holding or the investor's selected
        //     offering (new investors have no holding before commitment)
        //   - scope=investor AND investor_id = this investor
        $fundIds = $this->accessibleFundIds($investor);

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

    public function download(Request $request, int $id): RedirectResponse|JsonResponse|StreamedResponse
    {
        $investor = $request->user();
        $document = PortalDocument::findOrFail($id);

        // Authorise: must be global, or fund-scoped on a fund the investor holds,
        // or investor-scoped to this investor.
        $allowed = $document->scope === 'global'
            || ($document->scope === 'investor' && $document->investor_id === $investor->id)
            || ($document->scope === 'fund' && $this->accessibleFundIds($investor)->contains($document->fund_id));

        if (! $allowed) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if (! Str::startsWith($document->file_url, ['http://', 'https://'])) {
            if (! Storage::disk('local')->exists($document->file_url)) {
                return response()->json(['message' => 'Document file not found.'], 404);
            }

            $filename = (Str::slug(pathinfo($document->title, PATHINFO_FILENAME)) ?: 'offering-document').'.pdf';

            return Storage::disk('local')->download(
                $document->file_url,
                $filename,
                ['Content-Type' => $document->mime_type ?: 'application/pdf'],
            );
        }

        // Legacy/demo records can still point to an externally hosted document.
        return redirect()->away($document->file_url);
    }

    private function accessibleFundIds(Investor $investor): Collection
    {
        $fundIds = $investor->holdings()->pluck('fund_id');

        if (! empty($investor->investment_fund_name)) {
            $selectedFundId = Fund::query()
                ->where('name', $investor->investment_fund_name)
                ->value('id');

            if ($selectedFundId) {
                $fundIds->push($selectedFundId);
            }
        }

        // Registration currently defaults every investor to the active
        // offering by name. Keep pre-commitment access working if an admin has
        // renamed that fund since the investor registered.
        if ($fundIds->isEmpty()) {
            $activeFundId = Fund::query()
                ->where('status', 'active')
                ->orderBy('id')
                ->value('id');

            if ($activeFundId) {
                $fundIds->push($activeFundId);
            }
        }

        return $fundIds->unique()->values();
    }
}
