<?php

namespace Tests\Unit\Services\Application\Handlers\Organizer\Integrations;

use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;
use HiEvents\Services\Application\Handlers\Organizer\Integrations\CreateIntegrationsEmbedTokenHandler;
use HiEvents\Services\Domain\Integrations\ProductConnectionService;
use HiEvents\Services\Infrastructure\Fastn\Exception\FastnNotConfiguredException;
use HiEvents\Services\Infrastructure\Fastn\Exception\FastnRequestException;
use HiEvents\Services\Infrastructure\Fastn\FastnClient;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

class CreateIntegrationsEmbedTokenHandlerTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private const ORGS_URL = 'https://fastn.test/v1/orgs';

    private const TOKEN_URL = 'https://fastn.test/v1/embed/token';

    private CacheRepository $cache;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.fastn.api_url' => 'https://fastn.test/',
            'services.fastn.api_key' => 'key_live',
            'services.fastn.idv_secret' => 'idv_secret',
        ]);

        $this->cache = new CacheRepository(new ArrayStore);
    }

    public function test_registers_the_organizer_once_and_mints_a_signed_token(): void
    {
        Http::fake([
            self::ORGS_URL => Http::response(['data' => ['id' => 'org_1']], 201),
            self::TOKEN_URL => Http::response(['data' => ['token' => 'emb_1', 'expiresAt' => '2030-01-01T00:00:00.000Z']]),
        ]);

        $handler = $this->handler('Acme <b>Events</b>');
        $first = $handler->handle(organizerId: 7, accountId: 3, userId: 42, canCreateApiKey: true);
        $handler->handle(organizerId: 7, accountId: 3, userId: 42, canCreateApiKey: true);

        $this->assertSame('emb_1', $first->embedToken->token);
        $this->assertSame('https://fastn.test/v1/embed/iframe?token=emb_1', $first->embedToken->iframeUrl);
        $this->assertSame('2030-01-01T00:00:00.000Z', $first->embedToken->expiresAt);
        $this->assertNull($first->productConnected);

        Http::assertSentCount(3);
        Http::assertSent(fn (Request $request) => $request->url() === self::ORGS_URL
            && $request->hasHeader('Authorization', 'Bearer key_live')
            && $request['orgId'] === 'hievents-organizer-7'
            && $request['slug'] === 'hievents-organizer-7'
            && $request['name'] === 'Acme b Events /b'
            && ! $request->hasHeader('x-org-id')
            && $request->hasHeader('x-user-id', '42')
            && $request->hasHeader('x-identity-hmac', hash_hmac('sha256', "\n42", 'idv_secret')));
        Http::assertSent(fn (Request $request) => $request->url() === self::TOKEN_URL
            && $request->hasHeader('Authorization', 'Bearer key_live')
            && $request->hasHeader('x-org-id', 'hievents-organizer-7')
            && $request->hasHeader('x-user-id', '42')
            && $request->hasHeader('x-identity-hmac', hash_hmac('sha256', "hievents-organizer-7\n42", 'idv_secret')));
    }

    public function test_treats_an_already_registered_organizer_as_registered(): void
    {
        Http::fake([
            self::ORGS_URL => Http::response(['error' => 'External ID "hievents-organizer-7" is already used by customer "Acme"'], 400),
            self::TOKEN_URL => Http::response(['data' => ['token' => 'emb_1']]),
        ]);

        $this->assertSame('emb_1', $this->handler()->handle(7, 3, 42, true)->embedToken->token);
        $this->assertTrue($this->cache->has('fastn:customer-registered:7'));
    }

    public function test_registers_again_when_fastn_no_longer_knows_the_organizer(): void
    {
        $this->cache->forever('fastn:customer-registered:7', true);

        Http::fake([
            self::ORGS_URL => Http::response(['data' => ['id' => 'org_1']], 201),
            self::TOKEN_URL => Http::sequence()
                ->push(['error' => 'Customer not found', 'code' => 'IDENTITY_ORG_NOT_FOUND'], 404)
                ->push(['data' => ['token' => 'emb_2']]),
        ]);

        $this->assertSame('emb_2', $this->handler()->handle(7, 3, 42, true)->embedToken->token);
        Http::assertSentCount(3);
    }

    public function test_fails_when_fastn_refuses_the_registration(): void
    {
        Http::fake([self::ORGS_URL => Http::response(['error' => 'Forbidden'], 403)]);

        try {
            $this->handler()->handle(7, 3, 42, true);
            $this->fail('Expected the registration failure to be raised');
        } catch (FastnRequestException $exception) {
            $this->assertSame(403, $exception->status);
        }

        $this->assertFalse($this->cache->has('fastn:customer-registered:7'));
        Http::assertSentCount(1);
    }

    public function test_calls_nothing_when_the_key_or_secret_is_missing(): void
    {
        config(['services.fastn.idv_secret' => null]);
        Http::fake();

        $this->expectException(FastnNotConfiguredException::class);

        try {
            $this->handler()->handle(7, 3, 42, true);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_refuses_an_organizer_outside_the_account(): void
    {
        Http::fake();

        $repository = Mockery::mock(OrganizerRepositoryInterface::class);
        $repository->shouldReceive('findFirstWhere')->with(['id' => 7, 'account_id' => 3])->andReturn(null);

        $this->expectException(ResourceNotFoundException::class);

        try {
            $this->handlerWith($repository)->handle(7, 3, 42, true);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_connects_the_organizer_once_the_token_is_minted(): void
    {
        Http::fake([
            self::ORGS_URL => Http::response(['data' => ['id' => 'org_1']], 201),
            self::TOKEN_URL => Http::response(['data' => ['token' => 'emb_1']]),
        ]);

        $productConnection = Mockery::mock(ProductConnectionService::class);
        $productConnection->shouldReceive('isEnabled')->andReturn(true);
        $productConnection->shouldReceive('ensureConnected')
            ->once()
            ->with(7, 3, 42, 'hievents-organizer-7', false)
            ->andReturn(false);

        $result = $this->handler(productConnection: $productConnection)->handle(7, 3, 42, false);

        $this->assertSame('emb_1', $result->embedToken->token);
        $this->assertFalse($result->productConnected);
    }

    private function handler(string $organizerName = 'Acme', ?ProductConnectionService $productConnection = null): CreateIntegrationsEmbedTokenHandler
    {
        $organizer = Mockery::mock(OrganizerDomainObject::class);
        $organizer->shouldReceive('getName')->andReturn($organizerName);

        $repository = Mockery::mock(OrganizerRepositoryInterface::class);
        $repository->shouldReceive('findFirstWhere')->with(['id' => 7, 'account_id' => 3])->andReturn($organizer);

        return $this->handlerWith($repository, $productConnection);
    }

    private function handlerWith(
        OrganizerRepositoryInterface $repository,
        ?ProductConnectionService $productConnection = null,
    ): CreateIntegrationsEmbedTokenHandler {
        if ($productConnection === null) {
            $productConnection = Mockery::mock(ProductConnectionService::class);
            $productConnection->shouldReceive('isEnabled')->andReturn(false);
        }

        return new CreateIntegrationsEmbedTokenHandler(
            new FastnClient(app(Factory::class), app('config')),
            $repository,
            $this->cache,
            $productConnection,
        );
    }
}
