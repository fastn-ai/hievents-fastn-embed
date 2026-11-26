<?php

namespace HiEvents\Services\Infrastructure\Fastn;

use HiEvents\Services\Infrastructure\Fastn\Exception\FastnNotConfiguredException;
use HiEvents\Services\Infrastructure\Fastn\Exception\FastnRequestException;
use Illuminate\Config\Repository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpClient;
use Illuminate\Http\Client\Response;

/**
 * Server-side calls to fastn. Each call names the customer and the user it is for and signs the pair with the
 * identity secret, so the API key alone cannot act for a customer.
 */
class FastnClient
{
    private const TIMEOUT_SECONDS = 10;

    public function __construct(
        private readonly HttpClient $httpClient,
        private readonly Repository $config,
    ) {}

    public function isConfigured(): bool
    {
        return $this->apiKey() !== '' && $this->identitySecret() !== '';
    }

    /**
     * Registers a customer under our own id for it. Answers false when fastn already has that customer.
     */
    public function registerCustomer(string $customerId, string $name, string $userId): bool
    {
        // There is no customer to name yet, so the signature covers an empty customer id.
        $response = $this->send('POST', '/v1/orgs', $this->identityHeaders(null, $userId), [
            'name' => $name,
            'orgId' => $customerId,
            // Without a slug fastn derives one from the name, and two customers with the same name would collide.
            'slug' => $customerId,
        ]);

        if ($response->successful()) {
            return true;
        }

        if ($response->status() === 400 && str_contains((string) $response->json('error'), 'already')) {
            return false;
        }

        throw $this->failure('register the customer', $response);
    }

    public function createEmbedToken(string $customerId, string $userId): FastnEmbedToken
    {
        $response = $this->send('POST', '/v1/embed/token', $this->identityHeaders($customerId, $userId));

        $token = $response->json('data.token');
        if (! $response->successful() || ! is_string($token) || $token === '') {
            throw $this->failure('mint an embed token', $response);
        }

        return new FastnEmbedToken(
            token: $token,
            iframeUrl: $this->baseUrl().'/v1/embed/iframe?token='.rawurlencode($token),
            expiresAt: $response->json('data.expiresAt'),
        );
    }

    /**
     * Stores credentials for one of the customer's connections. fastn refuses a second connection of the same name,
     * so when one is already there its credentials are replaced instead.
     *
     * @param  array<string, string>  $credentials
     */
    public function storeConnection(
        string $customerId,
        string $userId,
        string $connectorId,
        string $authMethodId,
        string $name,
        array $credentials,
    ): void {
        $headers = $this->identityHeaders($customerId, $userId);

        $response = $this->send('POST', '/v1/connections', $headers, [
            'connectorId' => $connectorId,
            'authMethodId' => $authMethodId,
            'name' => $name,
            'credentials' => $credentials,
        ]);

        if ($response->status() === 409) {
            $connectionId = $this->findConnectionId($headers, $connectorId, $name);

            if ($connectionId !== null) {
                $response = $this->send('PUT', '/v1/connections/'.rawurlencode($connectionId), $headers, [
                    'credentials' => $credentials,
                ]);
            }
        }

        if (! $response->successful()) {
            throw $this->failure('store the connection', $response);
        }
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function findConnectionId(array $headers, string $connectorId, string $name): ?string
    {
        $response = $this->send('GET', '/v1/connections', $headers);

        if (! $response->successful()) {
            throw $this->failure('list the connections', $response);
        }

        foreach ((array) $response->json('data') as $connection) {
            if (
                is_array($connection)
                && ($connection['connectorId'] ?? null) === $connectorId
                && ($connection['name'] ?? null) === $name
                && is_string($connection['id'] ?? null)
            ) {
                return $connection['id'];
            }
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    private function identityHeaders(?string $customerId, string $userId): array
    {
        $headers = [
            'x-user-id' => $userId,
            'x-identity-hmac' => hash_hmac('sha256', ($customerId ?? '')."\n".$userId, $this->identitySecret()),
        ];

        if ($customerId !== null) {
            $headers['x-org-id'] = $customerId;
        }

        return $headers;
    }

    /**
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>|null  $body
     */
    private function send(string $method, string $path, array $headers, ?array $body = null): Response
    {
        if (! $this->isConfigured()) {
            throw new FastnNotConfiguredException;
        }

        $request = $this->httpClient
            ->withToken($this->apiKey())
            ->withHeaders($headers)
            ->acceptJson()
            ->timeout(self::TIMEOUT_SECONDS);

        try {
            return $request->send($method, $this->baseUrl().$path, $body === null ? [] : ['json' => $body]);
        } catch (ConnectionException $exception) {
            throw new FastnRequestException('fastn could not be reached: '.$exception->getMessage());
        }
    }

    private function failure(string $what, Response $response): FastnRequestException
    {
        $error = $response->json('error');

        return new FastnRequestException(
            message: sprintf('fastn refused to %s (%d): %s', $what, $response->status(), is_string($error) ? $error : 'no error message'),
            status: $response->status(),
            errorCode: is_string($response->json('code')) ? $response->json('code') : null,
        );
    }

    private function baseUrl(): string
    {
        return rtrim((string) $this->config->get('services.fastn.api_url'), '/');
    }

    private function apiKey(): string
    {
        return trim((string) $this->config->get('services.fastn.api_key'));
    }

    private function identitySecret(): string
    {
        return trim((string) $this->config->get('services.fastn.idv_secret'));
    }
}
