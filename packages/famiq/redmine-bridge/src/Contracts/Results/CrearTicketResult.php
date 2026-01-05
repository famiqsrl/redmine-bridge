<?php

declare(strict_types=1);

namespace Famiq\RedmineBridge\Contracts\Results;

final readonly class CrearTicketResult
{
    public function __construct(
        public int $issueId,
        public bool $idempotencyHit,
    ) {
    }
}
