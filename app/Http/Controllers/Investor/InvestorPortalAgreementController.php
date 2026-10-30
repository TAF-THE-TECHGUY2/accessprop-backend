<?php

namespace App\Http\Controllers\Investor;

use App\Http\Controllers\Controller;
use App\Models\SigningEnvelope;
use App\Services\DocuSign\EnvelopeBuilder;
use App\Services\DocuSign\SigningNotYetOpen;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * The investor's own open agreement, and a link to sign it from the portal
 * rather than from the DocuSign email.
 */
class InvestorPortalAgreementController extends Controller
{
    private const CLOSED = [
        SigningEnvelope::STATUS_COMPLETED,
        SigningEnvelope::STATUS_VOIDED,
        SigningEnvelope::STATUS_DECLINED,
    ];

    public function __construct(private EnvelopeBuilder $builder) {}

    public function show(Request $request): JsonResponse
    {
        $envelope = SigningEnvelope::query()
            ->where('investor_id', $request->user()->id)
            ->whereNotIn('status', self::CLOSED)
            ->latest('id')
            ->first();

        return response()->json(['data' => $envelope ? [
            'id' => $envelope->id,
            'status' => $envelope->status,
            'canSignInPortal' => (bool) $envelope->embedded_signing,
            'sentAt' => optional($envelope->sent_at)->toIso8601String(),
        ] : null]);
    }

    public function signingLink(Request $request, int $id): JsonResponse
    {
        // Scoped in the query: another investor's id is simply not found.
        $envelope = SigningEnvelope::with('investor')
            ->where('investor_id', $request->user()->id)
            ->whereNotIn('status', self::CLOSED)
            ->findOrFail($id);

        $returnUrl = rtrim((string) config('app.frontend_url'), '/').'/dashboard?signing=returned';

        try {
            $url = $this->builder->signingUrl($envelope, 'investor', $returnUrl);
        } catch (SigningNotYetOpen) {
            return response()->json([
                'message' => 'Your documents are with the fund for its signature first. You will be able to sign as soon as it has — we will also email you.',
            ], Response::HTTP_CONFLICT);
        } catch (Throwable $e) {
            return response()->json([
                'message' => 'Signing is not available right now. Please use the link in your DocuSign email, or try again shortly.',
            ], Response::HTTP_BAD_GATEWAY);
        }

        return response()->json(['url' => $url]);
    }
}
