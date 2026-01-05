<?php

declare(strict_types=1);

namespace Famiq\RedmineBridge\Contracts\Results;

final readonly class ListarTicketsResult
{
    /**
     * @param array<int, array<string, mixed>> $items
     */
    public function __construct(
        public array $items,
        public int $total,
        public int $page,
        public int $perPage,
    ) {
    }
}
