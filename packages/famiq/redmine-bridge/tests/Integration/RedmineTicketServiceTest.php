<?php

declare(strict_types=1);

namespace Famiq\RedmineBridge\Tests\Integration;

use Famiq\RedmineBridge\Contracts\Commands\CrearTicketCommand;
use Famiq\RedmineBridge\Contracts\Context\RequestContext;
use Famiq\RedmineBridge\Contracts\DTO\TicketDTO;
use Famiq\RedmineBridge\Infrastructure\Idempotency\InMemoryIdempotencyStore;
use Famiq\RedmineBridge\Infrastructure\Redmine\RedmineConfig;
use Famiq\RedmineBridge\Infrastructure\Redmine\RedmineHttpClient;
use Famiq\RedmineBridge\Infrastructure\Redmine\RedminePayloadMapper;
use Famiq\RedmineBridge\Infrastructure\Redmine\RedmineTicketService;
use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class RedmineTicketServiceTest extends TestCase
{
    public function testCrearTicketUsesIdempotency(): void
    {
        $client = new MockClient();
        $client->addResponse(new Response(201, [], json_encode(['issue' => ['id' => 55]], JSON_THROW_ON_ERROR)));

        $config = new RedmineConfig(
            'https://redmine.test',
            'key',
            12,
            5,
            [],
            null,
            null,
            null,
            'fallback',
        );

        $service = new RedmineTicketService(
            new RedmineHttpClient($client, $config, new NullLogger()),
            $config,
            new RedminePayloadMapper(),
            new InMemoryIdempotencyStore(),
            new NullLogger(),
        );

        $command = new CrearTicketCommand(
            new TicketDTO('Subject', 'Body', 'media', null, null, null, null, []),
            'idempotency-1',
            RequestContext::generate(),
        );

        $result = $service->crearTicket($command);
        $this->assertSame(55, $result->issueId);
        $this->assertFalse($result->idempotencyHit);

        $repeat = $service->crearTicket($command);
        $this->assertTrue($repeat->idempotencyHit);
    }
}
