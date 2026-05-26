<?php

namespace App\Services\Integrations;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class PersonaClient
{
    public function __construct(
        private readonly ?string $apiKey,
        private readonly string $apiVersion,
        private readonly string $baseUrl,
        private readonly ?string $templateId,
        private readonly ?string $environmentId,
        private readonly ?string $webhookSecret,
    ) {
    }

    public static function fromConfig(): self
    {
        return new self(
            apiKey: config('services.persona.api_key'),
            apiVersion: config('services.persona.api_version', '2023-01-05'),
            baseUrl: rtrim((string) config('services.persona.base_url'), '/'),
            templateId: config('services.persona.template_id'),
            environmentId: config('services.persona.environment_id'),
            webhookSecret: config('services.persona.webhook_secret'),
        );
    }

    public function isConfigured(): bool
    {
        return ! empty($this->apiKey);
    }

    public function templateId(): ?string
    {
        return $this->templateId;
    }

    public function environmentId(): ?string
    {
        return $this->environmentId;
    }

    /**
     * Create an inquiry server-side. Returns the inquiry id and a one-time hosted link.
     *
     * Persona docs:
     *  - POST /api/v1/inquiries        creates an inquiry pre-filled with reference and fields
     *  - POST /api/v1/inquiries/{id}/generate-one-time-link  generates a hosted URL
     */
    public function createInquiry(string $referenceId, array $fields = []): array
    {
        $payload = [
            'data' => [
                'attributes' => array_filter([
                    'inquiry_template_id' => $this->templateId,
                    'reference_id' => $referenceId,
                    'fields' => $fields ?: null,
                ], static fn ($v) => $v !== null),
            ],
        ];

        $response = $this->request()
            ->post($this->baseUrl.'/inquiries', $payload)
            ->throw();

        return $response->json();
    }

    public function generateOneTimeLink(string $inquiryId): string
    {
        $response = $this->request()
            ->post($this->baseUrl.'/inquiries/'.$inquiryId.'/generate-one-time-link', [])
            ->throw();

        return data_get($response->json(), 'meta.one-time-link')
            ?? data_get($response->json(), 'data.attributes.one-time-link')
            ?? '';
    }

    public function fetchInquiry(string $inquiryId): array
    {
        $response = $this->request()
            ->get($this->baseUrl.'/inquiries/'.$inquiryId)
            ->throw();

        return $response->json();
    }

    /**
     * Verify Persona's `Persona-Signature` header. Persona sends signatures in the
     * form `t=<timestamp>,v1=<hmac>` where v1 is HMAC-SHA256(secret, timestamp + "." + body).
     */
    public function verifyWebhookSignature(string $signatureHeader, string $rawBody): bool
    {
        if (empty($this->webhookSecret) || $signatureHeader === '') {
            return false;
        }

        $parts = [];
        foreach (explode(',', $signatureHeader) as $segment) {
            [$key, $value] = array_pad(explode('=', trim($segment), 2), 2, null);
            if ($key !== null && $value !== null) {
                $parts[$key] = $value;
            }
        }

        $timestamp = $parts['t'] ?? null;
        $provided = $parts['v1'] ?? null;
        if (! $timestamp || ! $provided) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$rawBody, $this->webhookSecret);

        return hash_equals($expected, $provided);
    }

    private function request(): PendingRequest
    {
        return Http::acceptJson()
            ->asJson()
            ->withToken($this->apiKey)
            ->withHeaders([
                'Persona-Version' => $this->apiVersion,
                'Key-Inflection' => 'snake',
            ]);
    }
}
