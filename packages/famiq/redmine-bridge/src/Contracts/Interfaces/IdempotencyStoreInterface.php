<?php

declare(strict_types=1);

namespace Famiq\RedmineBridge\Contracts\Interfaces;

use Famiq\RedmineBridge\Contracts\DTO\IdempotencyRecord;

interface IdempotencyStoreInterface
{
    public function find(string $operation, string $key): ?IdempotencyRecord;

    public function save(IdempotencyRecord $record): void;
}
