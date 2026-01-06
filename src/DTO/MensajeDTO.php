<?php

declare(strict_types=1);

namespace Famiq\RedmineBridge\DTO;

final class MensajeDTO
{
    public function __construct(
        public int $issueId,
        public string $body,
        public string $visibility,
        public ?string $authorRef,
    ) {
    }
}
