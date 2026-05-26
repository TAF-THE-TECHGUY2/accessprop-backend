<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Services\DocuSign\EnvelopeStatusHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class DocuSignWebhookController extends Controller
{
    public function __invoke(Request $request, EnvelopeStatusHandler $handler): JsonResponse
    {
        $rawBody = $request->getContent();
        $configuredSecret = (string) config('docusign.webhook_secret');

        // HMAC verification is optional locally (no secret set) but required in
        // any environment where the secret is configured. DocuSign Connect sends
        // signature header `X-DocuSign-Signature-1` containing a base64-encoded
        // HMAC-SHA256 of the raw body using the shared secret.
        if ($configuredSecret !== '') {
            $signatureHeader = $request->header('X-DocuSign-Signature-1');

            if (! $this->signatureMatches($rawBody, (string) $signatureHeader, $configuredSecret)) {
                Log::warning('DocuSign webhook rejected: invalid signature', [
                    'has_header' => $signatureHeader !== null,
                ]);

                return response()->json(['message' => 'Invalid signature'], 401);
            }
        }

        $payload = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($payload)) {
            return response()->json(['message' => 'Invalid payload'], 400);
        }

        try {
            $handler->handle($payload);
        } catch (\Throwable $e) {
            Log::error('DocuSign webhook handler error', [
                'error' => $e->getMessage(),
                'envelope_id' => $payload['data']['envelopeId'] ?? null,
            ]);

            // Return 200 so DocuSign doesn't endlessly retry on application bugs.
            // We've logged the error for investigation.
            return response()->json(['message' => 'Internal error, acknowledged'], 200);
        }

        return response()->json(['message' => 'ok']);
    }

    private function signatureMatches(string $rawBody, string $providedHeader, string $secret): bool
    {
        if ($providedHeader === '') {
            return false;
        }

        $computed = base64_encode(hash_hmac('sha256', $rawBody, $secret, true));

        return hash_equals($computed, $providedHeader);
    }
}
