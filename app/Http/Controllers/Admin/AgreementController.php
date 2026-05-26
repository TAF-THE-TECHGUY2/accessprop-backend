<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Investor;
use App\Models\SigningEnvelope;
use App\Services\DocuSign\Client as DocuSignClient;
use App\Services\DocuSign\EnvelopeBuilder;
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

        $investor->update(['document_signing_status' => 'awaiting_investor']);

        return response()->json($this->presentEnvelope($envelope), Response::HTTP_CREATED);
    }

    public function resend(int $envelopeId): JsonResponse
    {
        $envelope = SigningEnvelope::findOrFail($envelopeId);

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
