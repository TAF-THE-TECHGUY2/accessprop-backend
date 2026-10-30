<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Investor;
use App\Models\SigningEnvelope;
use App\Services\DocuSign\Client as DocuSignClient;
use App\Services\DocuSign\EnvelopeBuilder;
use App\Services\DocuSign\SigningNotYetOpen;
use App\Services\DocuSign\SigningPackageMissing;
use DocuSign\eSign\Model\EnvelopeUpdateSummary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class AgreementController extends Controller
{
    public function __construct(
        private EnvelopeBuilder $builder,
        private DocuSignClient $client,
    ) {}

    public function index(string $code): JsonResponse
    {
        $investor = $this->findInvestor($code);

        $envelopes = $investor->signingEnvelopes()
            ->orderByDesc('sent_at')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (SigningEnvelope $e) => $this->presentEnvelope($e));

        return response()->json($envelopes);
    }

    public function send(string $code): JsonResponse
    {
        $investor = $this->findInvestor($code);

        try {
            $envelope = $this->builder->sendSubscriptionAgreement($investor);
        } catch (SigningPackageMissing $e) {
            return response()->json(['message' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (Throwable $e) {
            Log::error('Failed to send subscription agreement', [
                'investor_code' => $investor->code,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to send agreement.',
                'detail' => $e->getMessage(),
            ], Response::HTTP_BAD_GATEWAY);
        }

        // Neutral: who signs first is a setting, and the envelope-level events
        // DocuSign sends do not say which signer is up.
        $investor->update(['document_signing_status' => 'out_for_signature']);

        return response()->json($this->presentEnvelope($envelope), Response::HTTP_CREATED);
    }

    /**
     * A fresh one-time signing link for one signer, for an admin to open or
     * pass on. Valid about five minutes.
     */
    public function signingLink(Request $request, int $envelopeId): JsonResponse
    {
        $data = $request->validate(['role' => ['required', 'in:investor,counter_signer']]);
        $envelope = SigningEnvelope::with('investor')->findOrFail($envelopeId);

        if (in_array($envelope->status, [SigningEnvelope::STATUS_COMPLETED, SigningEnvelope::STATUS_VOIDED, SigningEnvelope::STATUS_DECLINED], true)) {
            return response()->json(['message' => 'This agreement is no longer open for signing.'], Response::HTTP_CONFLICT);
        }

        $returnUrl = rtrim((string) config('app.frontend_url'), '/').'/admin/investors/'.$envelope->investor?->code.'?signing=returned';

        try {
            $url = $this->builder->signingUrl($envelope, $data['role'], $returnUrl);
        } catch (SigningNotYetOpen $e) {
            return response()->json(['message' => $e->getMessage()], Response::HTTP_CONFLICT);
        } catch (Throwable $e) {
            return response()->json(['message' => 'Could not get a signing link.', 'detail' => $e->getMessage()], Response::HTTP_BAD_GATEWAY);
        }

        return response()->json(['url' => $url, 'expiresInSeconds' => 300]);
    }

    public function resend(int $envelopeId): JsonResponse
    {
        $envelope = SigningEnvelope::findOrFail($envelopeId);

        // Signers the app emails itself: resend the app's email, since
        // DocuSign sends them nothing.
        if ($envelope->embedded_signing) {
            try {
                $role = app(\App\Services\DocuSign\AgreementNotifier::class)->resend($envelope);
            } catch (Throwable $e) {
                return response()->json(['message' => 'Failed to resend.', 'detail' => $e->getMessage()], Response::HTTP_BAD_GATEWAY);
            }

            return response()->json([
                'message' => $role === null
                    ? 'Nobody is waiting to sign.'
                    : 'Signing email resent to the '.($role === 'counter_signer' ? 'counter signer' : 'investor').'.',
            ]);
        }

        try {
            $update = (new EnvelopeUpdateSummary())->setEnvelopeId($envelope->docusign_envelope_id);

            $this->client->envelopes()->update(
                $this->client->accountId(),
                $envelope->docusign_envelope_id,
                (new \DocuSign\eSign\Model\Envelope())->setStatus('sent'),
                ['resend_envelope' => 'true']
            );
        } catch (Throwable $e) {
            return response()->json([
                'message' => 'Failed to resend envelope.',
                'detail' => $e->getMessage(),
            ], Response::HTTP_BAD_GATEWAY);
        }

        return response()->json($this->presentEnvelope($envelope->fresh()));
    }

    public function void(Request $request, int $envelopeId): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:200'],
        ]);

        $envelope = SigningEnvelope::findOrFail($envelopeId);

        try {
            $voidEnvelope = (new \DocuSign\eSign\Model\Envelope())
                ->setStatus('voided')
                ->setVoidedReason($data['reason']);

            $this->client->envelopes()->update(
                $this->client->accountId(),
                $envelope->docusign_envelope_id,
                $voidEnvelope
            );
        } catch (Throwable $e) {
            return response()->json([
                'message' => 'Failed to void envelope.',
                'detail' => $e->getMessage(),
            ], Response::HTTP_BAD_GATEWAY);
        }

        $envelope->update([
            'status' => SigningEnvelope::STATUS_VOIDED,
            'voided_at' => now(),
            'decline_reason' => $data['reason'],
        ]);

        return response()->json($this->presentEnvelope($envelope));
    }

    public function download(int $envelopeId): Response
    {
        $envelope = SigningEnvelope::findOrFail($envelopeId);

        if ($envelope->status !== SigningEnvelope::STATUS_COMPLETED) {
            return response()->json([
                'message' => 'Envelope is not completed yet.',
            ], Response::HTTP_BAD_REQUEST);
        }

        $filename = "{$envelope->investor->code}-subscription-agreement.pdf";

        if ($envelope->signed_document_disk && $envelope->signed_document_path) {
            $disk = Storage::disk($envelope->signed_document_disk);
            if ($disk->exists($envelope->signed_document_path)) {
                $contents = $disk->get($envelope->signed_document_path);

                return response($contents, 200, [
                    'Content-Type' => 'application/pdf',
                    'Content-Disposition' => "attachment; filename=\"{$filename}\"",
                ]);
            }
        }

        try {
            $tempFile = $this->client->envelopes()->getDocument(
                $this->client->accountId(),
                'combined',
                $envelope->docusign_envelope_id
            );
        } catch (Throwable $e) {
            return response()->json([
                'message' => 'Failed to fetch signed PDF.',
                'detail' => $e->getMessage(),
            ], Response::HTTP_BAD_GATEWAY);
        }

        $contents = file_get_contents($tempFile->getPathname());

        return response($contents, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    private function findInvestor(string $code): Investor
    {
        return Investor::where('code', $code)->firstOrFail();
    }

    private function presentEnvelope(SigningEnvelope $envelope): array
    {
        return [
            'embeddedSigning' => (bool) $envelope->embedded_signing,
            'id' => $envelope->id,
            'envelopeId' => $envelope->docusign_envelope_id,
            'type' => $envelope->type,
            'status' => $envelope->status,
            'investorEmail' => $envelope->investor_email,
            'investorName' => $envelope->investor_name,
            'counterSignerEmail' => $envelope->counter_signer_email,
            'counterSignerName' => $envelope->counter_signer_name,
            'sentAt' => optional($envelope->sent_at)->toIso8601String(),
            'deliveredAt' => optional($envelope->delivered_at)->toIso8601String(),
            'signedAt' => optional($envelope->signed_at)->toIso8601String(),
            'completedAt' => optional($envelope->completed_at)->toIso8601String(),
            'voidedAt' => optional($envelope->voided_at)->toIso8601String(),
            'declineReason' => $envelope->decline_reason,
            'hasSignedDocument' => $envelope->status === SigningEnvelope::STATUS_COMPLETED,
        ];
    }
}
