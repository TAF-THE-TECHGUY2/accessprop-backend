<?php

namespace App\Http\Controllers\Investor;

use App\Http\Controllers\Controller;
use App\Models\Fund;
use App\Models\Investor;
use App\Models\PortalDocument;
use App\Models\SigningEnvelope;
use App\Services\DocuSign\Client as DocuSignClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

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
            ->visibleTo($investor, $fundIds)
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

        return response()->json([
            'data' => $grouped,
            'signed' => $this->signedAgreements($investor),
        ]);
    }

    /**
     * The investor's own fully executed agreements — signed by them and
     * countersigned. Kept apart from the uploaded documents: these are theirs
     * alone, and they come from DocuSign rather than from an admin upload.
     */
    private function signedAgreements(Investor $investor): array
    {
        return SigningEnvelope::query()
            ->where('investor_id', $investor->id)
            ->where('status', SigningEnvelope::STATUS_COMPLETED)
            ->orderByDesc('completed_at')
            ->get()
            ->map(fn (SigningEnvelope $envelope) => [
                'id' => $envelope->id,
                'title' => Str::headline($envelope->type ?: 'subscription_agreement'),
                'signedAt' => optional($envelope->signed_at)->toIso8601String(),
                'completedAt' => optional($envelope->completed_at)->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    public function downloadSigned(Request $request, int $id): Response|JsonResponse
    {
        // Scoped in the query, so another investor's id is simply not found.
        $envelope = SigningEnvelope::query()
            ->where('investor_id', $request->user()->id)
            ->where('status', SigningEnvelope::STATUS_COMPLETED)
            ->findOrFail($id);

        $contents = null;

        if ($envelope->signed_document_disk && $envelope->signed_document_path) {
            $disk = Storage::disk($envelope->signed_document_disk);

            if ($disk->exists($envelope->signed_document_path)) {
                $contents = $disk->get($envelope->signed_document_path);
            }
        }

        // Not archived yet (the download job runs after the completion
        // webhook): fetch it from DocuSign directly, as the admin view does.
        if ($contents === null) {
            try {
                $client = app(DocuSignClient::class);
                $file = $client->envelopes()->getDocument(
                    $client->accountId(),
                    'combined',
                    $envelope->docusign_envelope_id,
                );
                $contents = file_get_contents($file->getPathname()) ?: null;
            } catch (Throwable $e) {
                Log::warning('Investor signed agreement fetch failed', [
                    'envelope_id' => $envelope->docusign_envelope_id,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        if ($contents === null) {
            return response()->json([
                'message' => 'Your signed copy is still being prepared. Please try again in a few minutes.',
            ], 404);
        }

        $filename = Str::slug($envelope->type ?: 'subscription-agreement').'-signed.pdf';

        return response($contents, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
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

    /**
     * Funds whose documents this investor may see.
     *
     * Resolution order matters:
     *
     *   1. investors.fund_id — the fund they subscribed to. Authoritative, and
     *      set at registration, so it works before any units are held.
     *   2. Funds they hold units in — covers anyone who has since invested in
     *      something other than their original subscription.
     *   3. investment_fund_name — legacy fallback only. This is a denormalised
     *      copy of funds.name and it has already drifted once in production,
     *      leaving investors unresolvable. It stays only for rows predating
     *      fund_id and should be removed once those are backfilled.
     */
    public function accessibleFundIds(Investor $investor): Collection
    {
        $fundIds = collect();

        if (! empty($investor->fund_id)) {
            $fundIds->push($investor->fund_id);
        }

        $fundIds = $fundIds->merge($investor->holdings()->pluck('fund_id'));

        if ($fundIds->isEmpty() && ! empty($investor->investment_fund_name)) {
            $legacyFundId = Fund::query()
                ->where('name', $investor->investment_fund_name)
                ->value('id');

            if ($legacyFundId) {
                $fundIds->push($legacyFundId);
            }
        }

        // Last resort for a legacy row with neither a fund_id nor a matching
        // name: fall back to the single open offering so pre-commitment access
        // keeps working. Only safe while exactly one fund is active.
        if ($fundIds->isEmpty()) {
            $active = Fund::query()->where('status', 'active')->pluck('id');

            if ($active->count() === 1) {
                $fundIds->push($active->first());
            }
        }

        return $fundIds->filter()->unique()->values();
    }
}
