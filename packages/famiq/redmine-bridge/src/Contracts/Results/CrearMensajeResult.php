<?php

declare(strict_types=1);

namespace Famiq\RedmineBridge\Contracts\Results;

final readonly class CrearMensajeResult
{
    public function __construct(
        public ?int $journalId,
    ) {
    }
}
