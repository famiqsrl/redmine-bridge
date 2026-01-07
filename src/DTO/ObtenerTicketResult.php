<?php

declare(strict_types=1);

namespace Famiq\RedmineBridge\DTO;

final class ObtenerTicketResult
{
    /**
     * @param array<string, mixed> $item
     */
    public function __construct(public array $item)
    {
    }
}
