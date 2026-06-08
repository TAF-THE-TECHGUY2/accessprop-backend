<?php

namespace App\Services\Integrations;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * InvestReady Connect (OAuth 2.0) client.
 *
 * Flow:
 *   1. authorizationUrl($state) → redirect investor's browser there
 *   2. InvestReady redirects back to our redirect_uri with ?code & ?state
 *   3. exchangeCode($code) → returns access_token, refresh_token, expires_in
 *   4. fetchVerification($accessToken) → returns accreditation status
 *
 * TODO: Verify endpoint URLs and response shapes against the official
 * docs at https://developer.investready.com/connect once available.
 */
class InvestReadyConnectClient
{
    public function __construct(
        private readonly ?string $clientId,
        private readonly ?string $clientSecret,
        private readonly string $redirectUri,
        private readonly string $authorizeUrl,
        private readonly string $tokenUrl,
        private readonly string $apiBaseUrl,
        private readonly string $verificationEndpoint,
        private readonly string $scope,
        private readonly ?string $webhookSecret,
        private readonly bool $sandbox,
    ) {
    }

    public static function fromConfig(): self
    {
        return new self(
            clientId: config('services.investready.client_id'),
            clientSecret: config('services.investready.client_secret'),
            redirectUri: (string) config('services.investready.redirect_uri'),
            authorizeUrl: rtrim((string) config('services.investready.authorize_url'), '/'),
            tokenUrl: rtrim((string) config('services.investready.token_url'), '/'),
            apiBaseUrl: rtrim((string) config('services.investready.api_base_url'), '/'),
            verificationEndpoint: (string) config('services.investready.verification_endpoint', '/v1/users/me'),
            scope: (string) config('services.investready.scope', ''),
            webhookSecret: config('services.investready.webhook_secret'),
            sandbox: (bool) config('services.investready.sandbox', true),
        );
    }

    public function verificationEndpointUrl(): string
    {
        $path = '/'.ltrim($this->verificationEndpoint, '/');

        return $this->apiBaseUrl.$path;
    }

    public function isConfigured(): bool
    {
        return ! empty($this->clientId) && ! empty($this->clientSecret);
    }

    public function isSandbox(): bool
    {
        return $this->sandbox;
    }

    public function redirectUri(): string
    {
        return $this->redirectUri;
    }

    /**
     * Build the authorize URL the investor's browser should be redirected to.
     */
    public function authorizationUrl(string $state, ?string $redirectUri = null): string
    {
        $params = [
            'client_id' => $this->clientId,
            'redirect_uri' => $redirectUri ?? $this->redirectUri,
            'response_type' => 'code',
            'state' => $state,
        ];

        // Only include scope if explicitly configured; some providers reject
        // unknown scope strings and grant a default set when scope is omitted.
        if ($this->scope !== '') {
            $params['scope'] = $this->scope;
        }

        return $this->authorizeUrl.'?'.http_build_query($params);
    }

    /**
     * Exchange the authorization code for an access token.
     *
     * TODO: Confirm body shape (form-encoded vs JSON) and field names against docs.
     */
    public function exchangeCode(string $code, ?string $redirectUri = null): array
    {
        $response = Http::asForm()
            ->acceptJson()
            ->post($this->tokenUrl, [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'redirect_uri' => $redirectUri ?? $this->redirectUri,
            ])
            ->throw();

        return $response->json();
    }

    /**
     * Refresh an expired access token using the stored refresh_token.
     *
     * Per InvestReady docs: refresh_tokens last 2 years; access_tokens last 90 days.
     */
    public function refreshAccessToken(string $refreshToken, ?string $redirectUri = null): array
    {
        $response = Http::asForm()
            ->acceptJson()
            ->post($this->tokenUrl, [
                'grant_type' => 'refresh_token',
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'redirect_uri' => $redirectUri ?? $this->redirectUri,
                'refresh_token' => $refreshToken,
            ])
            ->throw();

        return $response->json();
    }

    /**
     * Fetch the authenticated investor's accreditation profile from InvestReady.
     *
     * Per docs (POST /api/wl/user/get.json):
     *   - Verb is POST, not GET
     *   - access_token is sent in the form body, NOT as a Bearer header
     *   - Returns the user's InvestReady profile data
     */
    public function fetchVerification(string $accessToken): array
    {
        $response = Http::asForm()
            ->acceptJson()
            ->post($this->verificationEndpointUrl(), [
                'access_token' => $accessToken,
            ])
            ->throw();

        return $response->json();
    }

    /**
     * Verify the signature on incoming InvestReady webhook payloads.
     *
     * TODO: Confirm signature header name and algorithm. Common patterns:
     *   X-InvestReady-Signature: sha256=<hex hmac of raw body using webhook_secret>
     *   X-Signature: <jwt>
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

    private function request(string $accessToken): PendingRequest
    {
        return Http::acceptJson()
            ->asJson()
            ->withToken($accessToken);
    }
}
