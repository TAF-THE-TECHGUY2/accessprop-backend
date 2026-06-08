<?php

namespace App\Http\Controllers\Investor;

use App\Http\Controllers\Controller;
use App\Models\Communication;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InvestorPortalCommunicationsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $investor = $request->user();

        $audienceTags = ['all'];
        if ($investor->accreditation_status === 'accredited') {
            $audienceTags[] = 'accredited';
        } elseif ($investor->accreditation_status === 'non_accredited') {
            $audienceTags[] = 'non_accredited';
        }
        // Fund-scoped audience tags like "fund:apdif-1"
        foreach ($investor->holdings()->with('fund')->get() as $h) {
            $audienceTags[] = 'fund:'.$h->fund->code;
        }

        $items = Communication::query()
            ->where('is_published', true)
            ->whereIn('audience', $audienceTags)
            ->orderByDesc('published_at')
            ->limit(50)
            ->get()
            ->map(fn ($c) => [
                'id' => $c->id,
                'type' => $c->type,
                'title' => $c->title,
                'summary' => $c->summary,
                'publishedAt' => optional($c->published_at)->toIso8601String(),
            ]);

        return response()->json(['data' => $items]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $investor = $request->user();

        $comm = Communication::query()
            ->where('id', $id)
            ->where('is_published', true)
            ->firstOrFail();

        // Audience check (same logic as index).
        $audienceTags = ['all'];
        if ($investor->accreditation_status === 'accredited') {
            $audienceTags[] = 'accredited';
        } elseif ($investor->accreditation_status === 'non_accredited') {
            $audienceTags[] = 'non_accredited';
        }
        foreach ($investor->holdings()->with('fund')->get() as $h) {
            $audienceTags[] = 'fund:'.$h->fund->code;
        }

        if (! in_array($comm->audience, $audienceTags, true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return response()->json([
            'id' => $comm->id,
            'type' => $comm->type,
            'title' => $comm->title,
            'summary' => $comm->summary,
            'body' => $comm->body_html,
            'audience' => $comm->audience,
            'publishedAt' => optional($comm->published_at)->toIso8601String(),
        ]);
    }
}
