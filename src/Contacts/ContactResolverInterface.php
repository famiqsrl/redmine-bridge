<?php

declare(strict_types=1);

namespace Famiq\RedmineBridge\Contacts;

use Famiq\RedmineBridge\DTO\BuscarClienteResult;
use Famiq\RedmineBridge\DTO\ClienteDTO;
use Famiq\RedmineBridge\DTO\UpsertClienteResult;
use Famiq\RedmineBridge\RequestContext;

interface ContactResolverInterface
{
    public function buscar(ClienteDTO $criteria, RequestContext $context): BuscarClienteResult;

    public function upsert(ClienteDTO $cliente, RequestContext $context): UpsertClienteResult;
}
