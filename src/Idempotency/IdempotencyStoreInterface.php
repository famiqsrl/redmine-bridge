<?php

declare(strict_types=1);

namespace Famiq\RedmineBridge\Idempotency;

use Famiq\RedmineBridge\DTO\IdempotencyRecord;

interface IdempotencyStoreInterface
{
    public function find(string $operation, string $key): ?IdempotencyRecord;

    public function save(IdempotencyRecord $record): void;
}
