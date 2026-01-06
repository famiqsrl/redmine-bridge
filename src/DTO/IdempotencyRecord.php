<?php

declare(strict_types=1);

namespace Famiq\RedmineBridge\DTO;

final class IdempotencyRecord
{
    public function __construct(
        public string $operation,
        public string $key,
        public string $requestHash,
        public string $responsePayload,
        public string $createdAt,
    ) {
    }
}
