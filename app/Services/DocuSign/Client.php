<?php

namespace App\Services\DocuSign;

use DocuSign\eSign\Api\EnvelopesApi;
use DocuSign\eSign\Api\TemplatesApi;
use DocuSign\eSign\Client\ApiClient;
use DocuSign\eSign\Configuration;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class Client
{
    private const TOKEN_CACHE_KEY = 'docusign:jwt:access_token';

    private const JWT_GRANT_TYPE = 'urn:ietf:params:oauth:grant-type:jwt-bearer';

    private ?ApiClient $apiClient = null;

    public function envelopes(): EnvelopesApi
    {
        return new EnvelopesApi($this->apiClient());
    }

    public function templates(): TemplatesApi
    {
        return new TemplatesApi($this->apiClient());
    }

    public function accountId(): string
    {
        return (string) config('docusign.account_id');
    }

    public function forgetCachedToken(): void
    {
        Cache::forget(self::TOKEN_CACHE_KEY);
        $this->apiClient = null;
    }

    private function apiClient(): ApiClient
    {
        if ($this->apiClient !== null) {
            return $this->apiClient;
        }

        $config = new Configuration();
        $config->setHost(rtrim((string) config('docusign.base_uri'), '/'));
        $config->addDefaultHeader('Authorization', 'Bearer '.$this->accessToken());

        return $this->apiClient = new ApiClient($config);
    }

    private function accessToken(): string
    {
        $cached = Cache::get(self::TOKEN_CACHE_KEY);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $token = $this->requestNewAccessToken();

        Cache::put(self::TOKEN_CACHE_KEY, $token, (int) config('docusign.token_ttl_seconds', 3300));

        return $token;
    }

    private function requestNewAccessToken(): string
    {
        $assertion = $this->buildSignedJwt();

        $response = Http::asForm()->post(
            'https://'.config('docusign.auth_base_uri').'/oauth/token',
            [
                'grant_type' => self::JWT_GRANT_TYPE,
                'assertion' => $assertion,
            ]
        );

        if (! $response->successful()) {
            throw new RuntimeException(
                'DocuSign token request failed ('.$response->status().'): '.$response->body()
            );
        }

        $accessToken = $response->json('access_token');

        if (! is_string($accessToken) || $accessToken === '') {
            throw new RuntimeException('DocuSign token response missing access_token.');
        }

        return $accessToken;
    }

    private function buildSignedJwt(): string
    {
        $now = time();

        $payload = [
            'iss' => (string) config('docusign.integration_key'),
            'sub' => (string) config('docusign.user_id'),
            'aud' => (string) config('docusign.auth_base_uri'),
            'iat' => $now,
            'exp' => $now + 3600,
            'scope' => implode(' ', (array) config('docusign.scopes')),
        ];

        $privateKey = $this->readPrivateKey();

        return JWT::encode($payload, $privateKey, 'RS256');
    }

    private function readPrivateKey(): string
    {
        $configured = (string) config('docusign.private_key_path');

        $absolute = str_starts_with($configured, '/')
            ? $configured
            : base_path($configured);

        if (! is_readable($absolute)) {
            throw new RuntimeException("DocuSign private key not readable at: {$absolute}");
        }

        $contents = file_get_contents($absolute);

        if ($contents === false || trim($contents) === '') {
            throw new RuntimeException("DocuSign private key empty or unreadable at: {$absolute}");
        }

        return $contents;
    }
}
