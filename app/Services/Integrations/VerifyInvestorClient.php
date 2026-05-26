<?php

namespace App\Services\Integrations;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class VerifyInvestorClient
{
    public function __construct(
        private readonly ?string $apiKey,
        private readonly string $baseUrl,
        private readonly string $verificationType,
        private readonly ?string $webhookSecret,
    ) {
    }

    public static function fromConfig(): self
    {
        return new self(
            apiKey: config('services.verifyinvestor.api_key'),
            baseUrl: rtrim((string) config('services.verifyinvestor.base_url'), '/'),
            verificationType: (string) config('services.verifyinvestor.verification_type', 'third_party_letter'),
            webhookSecret: config('services.verifyinvestor.webhook_secret'),
        );
    }

    public function isConfigured(): bool
    {
        return ! empty($this->apiKey);
    }

    public function verificationType(): string
    {
        return $this->verificationType;
    }

    /**
     * Create a verification request for a given investor.
     *
     * Returns the JSON body from verifyinvestor.com. Caller can read the
     * verification id and hosted URL from this payload.
     */
    public function createVerificationRequest(array $payload): array
    {
        $response = $this->request()
            ->post($this->baseUrl.'/verification_requests', $payload)
            ->throw();

        return $response->json();
    }

    public function fetchVerificationRequest(string $id): array
    {
        $response = $this->request()
            ->get($this->baseUrl.'/verification_requests/'.$id)
            ->throw();

        return $response->json();
    }

    /**
     * Verify the signature header on incoming webhook payloads.
     *
     * verifyinvestor.com sends an HMAC-SHA256 of the raw body using the shared
     * webhook secret in the `X-VerifyInvestor-Signature` header.
     */
    public function verifyWebhookSignature(string $signatureHeader, string $rawBody): bool
    {
        if (empty($this->webhookSecret) || $signatureHeader === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $rawBody, $this->webhookSecret);
        $provided = trim(preg_replace('/^sha256=/i', '', $signatureHeader));

        return hash_equals($expected, $provided);
    }

    private function request(): PendingRequest
    {
        return Http::acceptJson()
            ->asJson()
            ->withHeaders([
                'Authorization' => 'Token token='.$this->apiKey,
            ]);
    }
}
