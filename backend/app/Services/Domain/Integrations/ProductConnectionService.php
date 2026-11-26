<?php

namespace HiEvents\Services\Domain\Integrations;

use HiEvents\DomainObjects\Enums\ApiKeyAccess;
use HiEvents\DomainObjects\PersonalAccessTokenDomainObject;
use HiEvents\Repository\Interfaces\PersonalAccessTokenRepositoryInterface;
use HiEvents\Repository\Interfaces\UserRepositoryInterface;
use HiEvents\Services\Domain\Auth\ApiKey\ApiKeyService;
use HiEvents\Services\Infrastructure\Fastn\Exception\FastnRequestException;
use HiEvents\Services\Infrastructure\Fastn\FastnClient;
use Illuminate\Config\Repository as Config;
use Illuminate\Contracts\Cache\Repository as Cache;
use Psr\Log\LoggerInterface;

/**
 * Integrations read this organizer's orders and attendees through our own API, so fastn needs a connection to it.
 * We create that connection ourselves, with an API key made for the purpose, so the organizer only has to connect
 * the other app.
 */
class ProductConnectionService
{
    public const CONNECTION_NAME = 'hievents';

    private const CONNECTED_CACHE_KEY = 'fastn:product-connection:';

    private const IN_PROGRESS_CACHE_KEY = 'fastn:product-connection-in-progress:';

    private const IN_PROGRESS_SECONDS = 30;

    public function __construct(
        private readonly FastnClient $fastnClient,
        private readonly ApiKeyService $apiKeyService,
        private readonly PersonalAccessTokenRepositoryInterface $apiKeyRepository,
        private readonly UserRepositoryInterface $userRepository,
        private readonly Cache $cache,
        private readonly Config $config,
        private readonly LoggerInterface $logger,
    ) {}

    public function isEnabled(): bool
    {
        return $this->connectorId() !== '' && $this->authMethodId() !== '' && $this->productApiUrl() !== '';
    }

    /**
     * Answers whether fastn holds a working connection for the organizer once this returns.
     *
     * @param  bool  $canCreateKey  whether the signed-in user may create a read and write API key right now
     */
    public function ensureConnected(
        int $organizerId,
        int $accountId,
        int $userId,
        string $customerId,
        bool $canCreateKey,
    ): bool {
        $cacheKey = self::CONNECTED_CACHE_KEY.$organizerId;

        if ($this->keyIsStillUsable($this->cache->get($cacheKey), $accountId)) {
            return true;
        }

        $user = $this->userRepository->findById($userId);

        if (! $canCreateKey || $user->getEmailVerifiedAt() === null) {
            return false;
        }

        if ($this->apiKeyService->countActiveKeys($userId, $accountId) >= ApiKeyService::MAX_ACTIVE_KEYS_PER_MEMBER) {
            return false;
        }

        // Two requests for the same organizer would each make a key. The second one leaves it to the first.
        if (! $this->cache->add(self::IN_PROGRESS_CACHE_KEY.$organizerId, true, self::IN_PROGRESS_SECONDS)) {
            return true;
        }

        $keyName = self::keyName($organizerId);

        $this->apiKeyRepository->deleteWhere([
            PersonalAccessTokenDomainObject::ACCOUNT_ID => $accountId,
            PersonalAccessTokenDomainObject::NAME => $keyName,
            [PersonalAccessTokenDomainObject::OAUTH_CONNECTION_ID, 'null', null],
        ]);

        $created = $this->apiKeyService->create(
            user: $user,
            accountId: $accountId,
            name: $keyName,
            access: ApiKeyAccess::READ_WRITE,
            expiresInDays: null,
        );

        try {
            $this->fastnClient->storeConnection(
                customerId: $customerId,
                userId: (string) $userId,
                connectorId: $this->connectorId(),
                authMethodId: $this->authMethodId(),
                name: self::CONNECTION_NAME,
                credentials: ['token' => $created->plainTextKey, 'baseUrl' => $this->productApiUrl()],
            );
        } catch (FastnRequestException $exception) {
            $this->apiKeyRepository->deleteById($created->apiKey->getId());
            $this->cache->forget(self::IN_PROGRESS_CACHE_KEY.$organizerId);

            $this->logger->error('Could not connect the organizer to fastn', [
                'organizer_id' => $organizerId,
                'status' => $exception->status,
                'code' => $exception->errorCode,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }

        $this->cache->forever($cacheKey, $created->apiKey->getId());
        $this->cache->forget(self::IN_PROGRESS_CACHE_KEY.$organizerId);

        return true;
    }

    public static function keyName(int $organizerId): string
    {
        return sprintf('Integrations (organizer %d)', $organizerId);
    }

    /**
     * The key can be revoked from the API keys page, or go with the member who opened integrations first. Either
     * way the connection is dead and the next visit makes a new one.
     */
    private function keyIsStillUsable(mixed $apiKeyId, int $accountId): bool
    {
        if (! is_int($apiKeyId)) {
            return false;
        }

        return $this->apiKeyRepository->findFirstWhere([
            PersonalAccessTokenDomainObject::ID => $apiKeyId,
            PersonalAccessTokenDomainObject::ACCOUNT_ID => $accountId,
        ]) !== null;
    }

    private function connectorId(): string
    {
        return trim((string) $this->config->get('services.fastn.connector_id'));
    }

    private function authMethodId(): string
    {
        return trim((string) $this->config->get('services.fastn.connector_auth_method_id'));
    }

    private function productApiUrl(): string
    {
        return rtrim(trim((string) $this->config->get('services.fastn.product_api_url')), '/');
    }
}
