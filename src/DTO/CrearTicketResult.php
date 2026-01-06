<?php

declare(strict_types=1);

namespace Famiq\RedmineBridge\DTO;

final class CrearTicketResult
{
    public function __construct(
        public int $issueId,
    ) {
    }
}
