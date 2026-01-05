<?php

declare(strict_types=1);

namespace Famiq\RedmineBridge\Contracts\Interfaces;

use Famiq\RedmineBridge\Contracts\Commands\UpsertClienteCommand;
use Famiq\RedmineBridge\Contracts\Queries\BuscarClienteQuery;
use Famiq\RedmineBridge\Contracts\Results\BuscarClienteResult;
use Famiq\RedmineBridge\Contracts\Results\UpsertClienteResult;

interface ClienteServiceInterface
{
    public function buscarCliente(BuscarClienteQuery $query): BuscarClienteResult;

    public function upsertCliente(UpsertClienteCommand $command): UpsertClienteResult;
}
