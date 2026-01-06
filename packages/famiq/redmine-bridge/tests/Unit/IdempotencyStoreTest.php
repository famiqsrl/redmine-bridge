<?php

declare(strict_types=1);

namespace Famiq\RedmineBridge\Tests\Unit;

use Famiq\RedmineBridge\DTO\IdempotencyRecord;
use Famiq\RedmineBridge\Idempotency\InMemoryIdempotencyStore;
use PHPUnit\Framework\TestCase;

final class IdempotencyStoreTest extends TestCase
{
    public function testStoresAndFindsRecord(): void
    {
        $store = new InMemoryIdempotencyStore();
        $record = new IdempotencyRecord('crear_ticket', 'key-1', 'hash', '{"issue_id":1}', '2024-01-01T00:00:00Z');

        $this->assertNull($store->find('crear_ticket', 'key-1'));
        $store->save($record);

        $found = $store->find('crear_ticket', 'key-1');
        $this->assertNotNull($found);
        $this->assertSame('hash', $found->requestHash);
    }
}
