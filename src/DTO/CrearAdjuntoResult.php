<?php

declare(strict_types=1);

namespace Famiq\RedmineBridge\DTO;

final class CrearAdjuntoResult
{
    public function __construct(
        public ?string $attachmentId,
        public bool $idempotencyHit,
    ) {
    }
}
