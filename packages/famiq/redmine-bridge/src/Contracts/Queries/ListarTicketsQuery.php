<?php

declare(strict_types=1);

namespace Famiq\RedmineBridge\Contracts\Queries;

use Famiq\RedmineBridge\Contracts\Context\RequestContext;

final readonly class ListarTicketsQuery
{
    public function __construct(
        public ?string $status,
        public ?int $page,
        public ?int $perPage,
        public ?string $clienteRef,
        public RequestContext $context,
    ) {
    }
}
