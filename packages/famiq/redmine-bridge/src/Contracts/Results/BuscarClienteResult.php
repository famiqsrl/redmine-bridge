<?php

declare(strict_types=1);

namespace Famiq\RedmineBridge\Contracts\Results;

use Famiq\RedmineBridge\Contracts\DTO\ClienteDTO;

final readonly class BuscarClienteResult
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
