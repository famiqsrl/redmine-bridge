<?php

declare(strict_types=1);

namespace Famiq\RedmineBridge\DTO;

use Famiq\RedmineBridge\DTO\ClienteDTO;

final class BuscarClienteResult
{
    /**
     * @param ClienteDTO[] $items
     */
    public function __construct(
        public string $matchType,
        public array $items,
    ) {
    }
}
