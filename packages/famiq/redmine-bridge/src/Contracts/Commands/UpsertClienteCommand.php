<?php

declare(strict_types=1);

namespace Famiq\RedmineBridge\Contracts\Commands;

use Famiq\RedmineBridge\Contracts\Context\RequestContext;
use Famiq\RedmineBridge\Contracts\DTO\ClienteDTO;

final readonly class UpsertClienteCommand
{
    public function __construct(
        public ClienteDTO $cliente,
        public RequestContext $context,
    ) {
    }
}
