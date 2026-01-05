<?php

declare(strict_types=1);

namespace Famiq\RedmineBridge\Contracts\Queries;

use Famiq\RedmineBridge\Contracts\Context\RequestContext;

final readonly class BuscarClienteQuery
{
    public function __construct(
        public string $query,
        public ?string $externalId,
        public RequestContext $context,
    ) {
    }
}
