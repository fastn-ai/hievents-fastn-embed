<?php

namespace Tests\Unit\Services\Domain\Integrations;

use HiEvents\DomainObjects\Enums\ApiKeyAccess;
use HiEvents\DomainObjects\PersonalAccessTokenDomainObject;
use HiEvents\DomainObjects\UserDomainObject;
use HiEvents\Repository\Interfaces\PersonalAccessTokenRepositoryInterface;
use HiEvents\Repository\Interfaces\UserRepositoryInterface;
use HiEvents\Services\Domain\Auth\ApiKey\ApiKeyService;
use HiEvents\Services\Domain\Auth\ApiKey\DTO\CreatedApiKeyDTO;
use HiEvents\Services\Domain\Integrations\ProductConnectionService;
use HiEvents\Services\Infrastructure\Fastn\FastnClient;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use Psr\Log\NullLogger;
use Tests\TestCase;

class ProductConnectionServiceTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private const CONNECTIONS_URL = 'https://fastn.test/v1/connections';

    private const CONNECTED_KEY = 'fastn:product-connection:7';

    private CacheRepository $cache;

    private ApiKeyService&MockInterface $apiKeyService;

    private PersonalAccessTokenRepositoryInterface&MockInterface $apiKeyRepository;

    private UserRepositoryInterface&MockInterface $userRepository;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.fastn.api_url' => 'https://fastn.test',
            'services.fastn.api_key' => 'key_live',
            'services.fastn.idv_secret' => 'idv_secret',
            'services.fastn.connector_id' => 'connector_1',
            'services.fastn.connector_auth_method_id' => 'auth_1',
            'services.fastn.product_api_url' => 'https://events.test/api/',
        ]);

        $this->cache = new CacheRepository(new ArrayStore);
        $this->apiKeyService = Mockery::mock(ApiKeyService::class);
        $this->apiKeyRepository = Mockery::mock(PersonalAccessTokenRepositoryInterface::class);
        $this->userRepository = Mockery::mock(UserRepositoryInterface::class);
    }

    public function test_is_off_until_the_connector_and_the_public_url_are_set(): void
    {
        $this->assertTrue($this->service()->isEnabled());

        config(['services.fastn.product_api_url' => ' ']);

        $this->assertFalse($this->service()->isEnabled());
    }

    public function test_creates_a_key_and_stores_it_as_the_organizers_connection(): void
    {
        Http::fake([self::CONNECTIONS_URL => Http::response(['data' => ['id' => 'conn_1']], 201)]);

        $user = $this->verifiedUser();
        $this->apiKeyService->shouldReceive('countActiveKeys')->with(42, 3)->andReturn(0);
        $this->apiKeyRepository->shouldReceive('deleteWhere')->once()->with([
            'account_id' => 3,
            'name' => 'Integrations (organizer 7)',
            ['oauth_connection_id', 'null', null],
        ]);
        $this->apiKeyService->shouldReceive('create')
            ->once()
            ->with($user, 3, 'Integrations (organizer 7)', ApiKeyAccess::READ_WRITE, null)
            ->andReturn($this->createdKey(91, 'hie_plain'));

        $this->assertTrue($this->service()->ensureConnected(7, 3, 42, 'hievents-organizer-7', true));
        $this->assertSame(91, $this->cache->get(self::CONNECTED_KEY));

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->url() === self::CONNECTIONS_URL
            && $request->hasHeader('Authorization', 'Bearer key_live')
            && $request->hasHeader('x-org-id', 'hievents-organizer-7')
            && $request->hasHeader('x-user-id', '42')
            && $request->hasHeader('x-identity-hmac', hash_hmac('sha256', "hievents-organizer-7\n42", 'idv_secret'))
            && $request['connectorId'] === 'connector_1'
            && $request['authMethodId'] === 'auth_1'
            && $request['name'] === 'hievents'
            && $request['credentials'] === ['token' => 'hie_plain', 'baseUrl' => 'https://events.test/api']);
    }

    public function test_replaces_the_credentials_when_fastn_already_has_the_connection(): void
    {
        $connectionId = 'ucl:acme:hievents-organizer-7:connector_1:default:hievents';
        $connectionUrl = self::CONNECTIONS_URL.'/'.rawurlencode($connectionId);

        Http::fake(fn (Request $request) => match (true) {
            $request->method() === 'POST' => Http::response(['error' => 'A connection named "hievents" already exists for this connector.'], 409),
            $request->method() === 'GET' => Http::response(['data' => [
                ['id' => 'ucl:acme:hievents-organizer-7:other:default', 'connectorId' => 'other', 'name' => 'default'],
                ['id' => $connectionId, 'connectorId' => 'connector_1', 'name' => 'hievents'],
            ]]),
            default => Http::response(['data' => ['id' => $connectionId]]),
        });

        $user = $this->verifiedUser();
        $this->apiKeyService->shouldReceive('countActiveKeys')->with(42, 3)->andReturn(0);
        $this->apiKeyRepository->shouldReceive('deleteWhere')->once();
        $this->apiKeyRepository->shouldNotReceive('deleteById');
        $this->apiKeyService->shouldReceive('create')->once()->andReturn($this->createdKey(92, 'hie_new'));

        $this->assertTrue($this->service()->ensureConnected(7, 3, 42, 'hievents-organizer-7', true));
        $this->assertSame(92, $this->cache->get(self::CONNECTED_KEY));

        Http::assertSentCount(3);
        Http::assertSent(fn (Request $request) => $request->method() === 'PUT'
            && $request->url() === $connectionUrl
            && $request->hasHeader('x-org-id', 'hievents-organizer-7')
            && $request['credentials'] === ['token' => 'hie_new', 'baseUrl' => 'https://events.test/api']);
    }

    public function test_does_nothing_while_the_key_it_made_still_exists(): void
    {
        Http::fake();
        $this->cache->forever(self::CONNECTED_KEY, 91);
        $this->apiKeyRepository->shouldReceive('findFirstWhere')
            ->with(['id' => 91, 'account_id' => 3])
            ->andReturn(Mockery::mock(PersonalAccessTokenDomainObject::class));

        $this->assertTrue($this->service()->ensureConnected(7, 3, 42, 'hievents-organizer-7', false));

        Http::assertNothingSent();
    }

    public function test_connects_again_when_the_key_was_revoked(): void
    {
        Http::fake([self::CONNECTIONS_URL => Http::response(['data' => ['id' => 'conn_1']], 201)]);
        $this->cache->forever(self::CONNECTED_KEY, 91);
        $this->apiKeyRepository->shouldReceive('findFirstWhere')->andReturn(null);
        $this->apiKeyRepository->shouldReceive('deleteWhere')->once();
        $this->verifiedUser();
        $this->apiKeyService->shouldReceive('countActiveKeys')->andReturn(0);
        $this->apiKeyService->shouldReceive('create')->once()->andReturn($this->createdKey(92, 'hie_new'));

        $this->assertTrue($this->service()->ensureConnected(7, 3, 42, 'hievents-organizer-7', true));
        $this->assertSame(92, $this->cache->get(self::CONNECTED_KEY));
    }

    public function test_makes_no_key_for_someone_who_could_not_create_one_by_hand(): void
    {
        Http::fake();
        $this->verifiedUser();
        $this->apiKeyService->shouldNotReceive('create');

        $this->assertFalse($this->service()->ensureConnected(7, 3, 42, 'hievents-organizer-7', false));

        Http::assertNothingSent();
    }

    public function test_makes_no_key_before_the_email_address_is_confirmed(): void
    {
        Http::fake();
        $user = Mockery::mock(UserDomainObject::class);
        $user->shouldReceive('getEmailVerifiedAt')->andReturn(null);
        $this->userRepository->shouldReceive('findById')->with(42)->andReturn($user);
        $this->apiKeyService->shouldNotReceive('create');

        $this->assertFalse($this->service()->ensureConnected(7, 3, 42, 'hievents-organizer-7', true));

        Http::assertNothingSent();
    }

    public function test_removes_the_key_when_fastn_refuses_the_connection(): void
    {
        Http::fake([self::CONNECTIONS_URL => Http::response(['error' => 'Connector not found'], 404)]);
        $this->verifiedUser();
        $this->apiKeyService->shouldReceive('countActiveKeys')->andReturn(0);
        $this->apiKeyRepository->shouldReceive('deleteWhere')->once();
        $this->apiKeyService->shouldReceive('create')->once()->andReturn($this->createdKey(91, 'hie_plain'));
        $this->apiKeyRepository->shouldReceive('deleteById')->once()->with(91);

        $this->assertFalse($this->service()->ensureConnected(7, 3, 42, 'hievents-organizer-7', true));
        $this->assertFalse($this->cache->has(self::CONNECTED_KEY));
        $this->assertFalse($this->cache->has('fastn:product-connection-in-progress:7'));
    }

    private function verifiedUser(): UserDomainObject
    {
        $user = Mockery::mock(UserDomainObject::class);
        $user->shouldReceive('getEmailVerifiedAt')->andReturn('2026-01-01 00:00:00');
        $this->userRepository->shouldReceive('findById')->with(42)->andReturn($user);

        return $user;
    }

    private function createdKey(int $id, string $plainTextKey): CreatedApiKeyDTO
    {
        $apiKey = Mockery::mock(PersonalAccessTokenDomainObject::class);
        $apiKey->shouldReceive('getId')->andReturn($id);

        return new CreatedApiKeyDTO(apiKey: $apiKey, plainTextKey: $plainTextKey);
    }

    private function service(): ProductConnectionService
    {
        return new ProductConnectionService(
            new FastnClient(app(Factory::class), app('config')),
            $this->apiKeyService,
            $this->apiKeyRepository,
            $this->userRepository,
            $this->cache,
            app('config'),
            new NullLogger,
        );
    }
}
