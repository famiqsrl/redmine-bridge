<?php

declare(strict_types=1);

namespace Famiq\RedmineBridge\Idempotency;

use Famiq\RedmineBridge\DTO\IdempotencyRecord;

final class InMemoryIdempotencyStore implements IdempotencyStoreInterface
{
    /**
     * @var array<string, IdempotencyRecord>
     */
    private array $records = [];

    public function find(string $operation, string $key): ?IdempotencyRecord
    {
        return $this->records[$this->buildKey($operation, $key)] ?? null;
    }

    public function save(IdempotencyRecord $record): void
    {
        $this->records[$this->buildKey($record->operation, $record->key)] = $record;
    }

    private function buildKey(string $operation, string $key): string
    {
        return $operation . ':' . $key;
    }
}
