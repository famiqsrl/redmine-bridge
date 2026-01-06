<?php

declare(strict_types=1);

namespace Famiq\RedmineBridge\Tests\Unit;

use Famiq\RedmineBridge\DTO\TicketDTO;
use Famiq\RedmineBridge\RedmineConfig;
use Famiq\RedmineBridge\RedminePayloadMapper;
use PHPUnit\Framework\TestCase;

final class RedminePayloadMapperTest extends TestCase
{
    public function testMapsTicketToPayload(): void
    {
        $mapper = new RedminePayloadMapper();
        $config = new RedmineConfig(
            'https://redmine.test',
            'key',
            12,
            5,
            [
                'origen' => 101,
                'external_ticket_id' => 102,
                'canal' => 103,
                'contact_ref' => 104,
            ],
            null,
            null,
            null,
            'fallback',
        );

        $ticket = new TicketDTO(
            'Asunto',
            'Detalle',
            'alta',
            '2',
            'email',
            'EXT-1',
            'C-99',
            ['origen' => 'crm', 'custom' => 'valor'],
        );

        $payload = $mapper->issuePayload($ticket, $config);

        $this->assertSame('Asunto', $payload['issue']['subject']);
        $this->assertSame(5, $payload['issue']['priority_id']);
        $this->assertCount(4, $payload['issue']['custom_fields']);
    }
}
