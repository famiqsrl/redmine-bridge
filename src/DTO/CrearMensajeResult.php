<?php

declare(strict_types=1);

namespace Famiq\RedmineBridge\DTO;

final class CrearMensajeResult
{
    public function __construct(
        public ?int $journalId,
    ) {
    }
}
