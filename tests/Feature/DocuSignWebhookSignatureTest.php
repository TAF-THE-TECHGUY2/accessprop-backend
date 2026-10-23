<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The DocuSign webhook must not trust an unsigned event in production, where
 * a forged "completed" would file an unsigned agreement as signed.
 */
class DocuSignWebhookSignatureTest extends TestCase
{
    private function sendEvent(array $headers = [])
    {
        return $this->call('POST', '/api/webhooks/docusign', [], [], [], array_merge([
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], $headers), json_encode(['envelopeId' => 'nope', 'status' => 'completed']));
    }

    public function test_production_without_a_secret_refuses_every_event(): void
    {
        config(['docusign.webhook_secret' => '']);
        $this->app['env'] = 'production';

        $this->sendEvent()->assertStatus(503);
    }

    public function test_a_bad_signature_is_refused(): void
    {
        config(['docusign.webhook_secret' => 'shh']);

        $this->sendEvent(['HTTP_X_DOCUSIGN_SIGNATURE_1' => 'forged'])->assertStatus(401);
        $this->sendEvent()->assertStatus(401);
    }

    public function test_a_correct_signature_is_accepted(): void
    {
        config(['docusign.webhook_secret' => 'shh']);
        $body = json_encode(['envelopeId' => 'nope', 'status' => 'completed']);
        $sig = base64_encode(hash_hmac('sha256', $body, 'shh', true));

        $this->sendEvent(['HTTP_X_DOCUSIGN_SIGNATURE_1' => $sig])->assertOk();
    }
}
