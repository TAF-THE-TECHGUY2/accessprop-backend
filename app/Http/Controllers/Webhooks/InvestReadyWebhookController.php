<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\IntegrationRequest;
use App\Models\Investor;
use App\Services\Integrations\InvestReadyConnectClient;
use App\Services\InvestorProcessingService;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * InvestReady webhook. Per the docs the payload "signals the portal to
 * request the latest information for said user" — so we don't trust the
 * payload, we re-fetch the verification status using the stored access
 * token (refreshing it if needed).
 *
 * TODO: Confirm the signature header name and the exact payload shape from
 * the InvestReady dashboard "Webhook URL" docs. Common fields likely include
 * a user identifier we can match against our IntegrationRequest external_id.
 */
class InvestReadyWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        InvestReadyConnectClient $client,
        InvestorProcessingService $processing,
    ): JsonResponse {
        $rawBody = $request->getContent();
        $signature = $request->header('X-InvestReady-Signature', $request->header('X-Signature', ''));

        if (! $client->verifyWebhookSignature($signature, $rawBody)) {
            Log::warning('InvestReady webhook rejected: invalid signature');

            return response()->json(['message' => 'Invalid signature'], 401);
        }

        $payload = $request->json()->all();

        // TODO: Adjust path once docs confirm the user/verification identifier shape.
        $externalUserId = data_get($payload, 'user_id')
            ?? data_get($payload, 'data.user_id')
            ?? data_get($payload, 'data.id');

        if (! $externalUserId) {
            return response()->json(['message' => 'Missing user identifier'], 422);
        }

        $integration = IntegrationRequest::query()
            ->where('provider', 'investready')
            ->where(function ($q) use ($externalUserId) {
                $q->where('external_id', $externalUserId)
                    ->orWhereJsonContains('response_payload->user_id', $externalUserId);
            })
            ->latest('id')
            ->first();

        if (! $integration) {
            Log::info('InvestReady webhook received for unknown user', [
                'externalUserId' => $externalUserId,
            ]);

            return response()->json(['message' => 'User not found, acknowledged'], 202);
        }

        $investor = Investor::find($integration->investor_profile_id);
        if (! $investor) {
            return response()->json(['message' => 'Investor missing, acknowledged'], 202);
        }

        // Re-fetch the verification status using the stored access token.
        $accessToken = data_get($integration->response_payload, '_oauth.access_token');
        if (! $accessToken) {
            Log::warning('InvestReady webhook: no access token stored for refresh', [
                'investor' => $investor->code,
            ]);

            return response()->json(['message' => 'No access token; cannot refresh'], 202);
        }

        try {
            $verification = $client->fetchVerification($accessToken);
            // TODO: extract status and call processing service to update investor.
            // Reusing completeInvestReadyVerification would re-exchange the code which
            // we don't have; a dedicated refreshVerificationStatus method would be cleaner.
            Log::info('InvestReady webhook re-fetched verification', [
                'investor' => $investor->code,
                'status' => data_get($verification, 'status'),
            ]);
        } catch (RequestException $e) {
            Log::warning('InvestReady webhook fetch failed', [
                'investor' => $investor->code,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json(['message' => 'ok']);
    }
}
