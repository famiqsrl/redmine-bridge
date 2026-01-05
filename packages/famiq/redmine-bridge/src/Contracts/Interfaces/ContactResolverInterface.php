<?php

declare(strict_types=1);

namespace Famiq\RedmineBridge\Contracts\Interfaces;

use Famiq\RedmineBridge\Contracts\Context\RequestContext;
use Famiq\RedmineBridge\Contracts\DTO\ClienteDTO;
use Famiq\RedmineBridge\Contracts\Results\BuscarClienteResult;
use Famiq\RedmineBridge\Contracts\Results\UpsertClienteResult;

interface ContactResolverInterface
{
    public function buscar(ClienteDTO $criteria, RequestContext $context): BuscarClienteResult;

    public function upsert(ClienteDTO $cliente, RequestContext $context): UpsertClienteResult;
}
