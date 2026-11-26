<?php

namespace HiEvents\Services\Application\Handlers\Organizer\Integrations;

use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;
use HiEvents\Services\Application\Handlers\Organizer\Integrations\DTO\IntegrationsEmbedDTO;
use HiEvents\Services\Domain\Integrations\ProductConnectionService;
use HiEvents\Services\Infrastructure\Fastn\Exception\FastnNotConfiguredException;
use HiEvents\Services\Infrastructure\Fastn\Exception\FastnRequestException;
use HiEvents\Services\Infrastructure\Fastn\FastnClient;
use HiEvents\Services\Infrastructure\Fastn\FastnEmbedToken;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Each organizer is one fastn customer. It is registered the first time someone opens its integrations, and every
 * visit after that only mints a token for the signed-in user.
 */
class CreateIntegrationsEmbedTokenHandler
{
    private const REGISTERED_CACHE_KEY = 'fastn:customer-registered:';

    public function __construct(
        private readonly FastnClient $fastnClient,
        private readonly OrganizerRepositoryInterface $organizerRepository,
        private readonly Cache $cache,
        private readonly ProductConnectionService $productConnectionService,
    ) {}

    /**
     * @throws FastnNotConfiguredException
     * @throws FastnRequestException
     * @throws ResourceNotFoundException
     */
    public function handle(int $organizerId, int $accountId, int $userId, bool $canCreateApiKey): IntegrationsEmbedDTO
    {
        if (! $this->fastnClient->isConfigured()) {
            throw new FastnNotConfiguredException;
        }

        $organizer = $this->organizerRepository->findFirstWhere([
            'id' => $organizerId,
            'account_id' => $accountId,
        ]);

        if ($organizer === null) {
            throw new ResourceNotFoundException(__('Organizer not found'));
        }

        $customerId = self::customerId($organizerId);
        $fastnUserId = (string) $userId;
        $cacheKey = self::REGISTERED_CACHE_KEY.$organizerId;

        if (! $this->cache->has($cacheKey)) {
            $this->register($customerId, $organizer->getName(), $organizerId, $fastnUserId, $cacheKey);
        }

        $embedToken = $this->mintToken($customerId, $organizer->getName(), $organizerId, $fastnUserId, $cacheKey);

        return new IntegrationsEmbedDTO(
            embedToken: $embedToken,
            productConnected: $this->productConnectionService->isEnabled()
                ? $this->productConnectionService->ensureConnected($organizerId, $accountId, $userId, $customerId, $canCreateApiKey)
                : null,
        );
    }

    /**
     * @throws FastnRequestException
     */
    private function mintToken(string $customerId, string $organizerName, int $organizerId, string $fastnUserId, string $cacheKey): FastnEmbedToken
    {
        try {
            return $this->fastnClient->createEmbedToken($customerId, $fastnUserId);
        } catch (FastnRequestException $exception) {
            // The remembered registration can outlive the customer, for example after pointing at another fastn
            // workspace. fastn says so with a 404, so register again and try once more.
            if (! $exception->isCustomerNotFound()) {
                throw $exception;
            }

            $this->cache->forget($cacheKey);
            $this->register($customerId, $organizerName, $organizerId, $fastnUserId, $cacheKey);

            return $this->fastnClient->createEmbedToken($customerId, $fastnUserId);
        }
    }

    public static function customerId(int $organizerId): string
    {
        return 'hievents-organizer-'.$organizerId;
    }

    private function register(string $customerId, string $organizerName, int $organizerId, string $userId, string $cacheKey): void
    {
        $this->fastnClient->registerCustomer($customerId, self::displayName($organizerName, $organizerId), $userId);
        $this->cache->forever($cacheKey, true);
    }

    /**
     * fastn refuses angle brackets and control characters in a customer name.
     */
    private static function displayName(string $organizerName, int $organizerId): string
    {
        $name = (string) preg_replace('/[<>\p{C}\s]+/u', ' ', $organizerName);
        $name = trim(mb_substr($name, 0, 200));

        return $name !== '' ? $name : 'Organizer '.$organizerId;
    }
}
