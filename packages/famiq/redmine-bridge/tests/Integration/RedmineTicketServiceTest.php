<?php

declare(strict_types=1);

namespace Famiq\RedmineBridge\Tests\Integration;

use Famiq\RedmineBridge\DTO\TicketDTO;
use Famiq\RedmineBridge\Http\RedmineHttpClient;
use Famiq\RedmineBridge\Idempotency\InMemoryIdempotencyStore;
use Famiq\RedmineBridge\RedmineConfig;
use Famiq\RedmineBridge\RedminePayloadMapper;
use Famiq\RedmineBridge\RedmineTicketService;
use Famiq\RedmineBridge\RequestContext;
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

        $ticket = new TicketDTO('Subject', 'Body', 'media', null, null, null, null, []);
        $context = RequestContext::generate();

        $result = $service->crearTicket($ticket, 'idempotency-1', $context);
        $this->assertSame(55, $result->issueId);
        $this->assertFalse($result->idempotencyHit);

        $repeat = $service->crearTicket($ticket, 'idempotency-1', $context);
        $this->assertTrue($repeat->idempotencyHit);
    }
}
