<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\IntegrationRequest;
use App\Models\Investor;
use App\Services\InvestorProcessingService;
use App\Services\Integrations\PersonaClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PersonaWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        PersonaClient $persona,
        InvestorProcessingService $processing,
    ): JsonResponse {
        $rawBody = $request->getContent();
        $signature = $request->header('Persona-Signature', '');

        if (! $persona->verifyWebhookSignature($signature, $rawBody)) {
            Log::warning('Persona webhook rejected: invalid signature');

            return response()->json(['message' => 'Invalid signature'], 401);
        }

        $payload = $request->json()->all();
        $eventName = data_get($payload, 'data.attributes.name');
        $inquiryId = data_get($payload, 'data.attributes.payload.data.id')
            ?? data_get($payload, 'data.attributes.payload.data.attributes.inquiry-id');
        $status = data_get($payload, 'data.attributes.payload.data.attributes.status');
        $referenceId = data_get($payload, 'data.attributes.payload.data.attributes.reference-id');

        if (! $inquiryId) {
            return response()->json(['message' => 'Missing inquiry id'], 422);
        }

        $integration = IntegrationRequest::query()
            ->where('provider', 'persona')
            ->where('external_id', $inquiryId)
            ->first();

        $investor = null;
        if ($integration) {
            $investor = Investor::find($integration->investor_profile_id);
        }
        if (! $investor && $referenceId) {
            $investor = Investor::where('code', $referenceId)->first();
        }

        if (! $investor) {
            Log::info('Persona webhook received for unknown investor', [
                'event' => $eventName,
                'inquiryId' => $inquiryId,
            ]);

            return response()->json(['message' => 'Investor not found, acknowledged'], 202);
        }

        $processing->applyPersonaInquiryResult($investor, $inquiryId, $status);

        return response()->json(['message' => 'ok']);
    }
}
