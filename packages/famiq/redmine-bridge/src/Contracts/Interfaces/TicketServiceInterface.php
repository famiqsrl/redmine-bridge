<?php

declare(strict_types=1);

namespace Famiq\RedmineBridge\Contracts\Interfaces;

use Famiq\RedmineBridge\Contracts\Commands\CrearAdjuntoCommand;
use Famiq\RedmineBridge\Contracts\Commands\CrearMensajeCommand;
use Famiq\RedmineBridge\Contracts\Commands\CrearTicketCommand;
use Famiq\RedmineBridge\Contracts\Queries\ListarTicketsQuery;
use Famiq\RedmineBridge\Contracts\Results\CrearAdjuntoResult;
use Famiq\RedmineBridge\Contracts\Results\CrearMensajeResult;
use Famiq\RedmineBridge\Contracts\Results\CrearTicketResult;
use Famiq\RedmineBridge\Contracts\Results\ListarTicketsResult;

interface TicketServiceInterface
{
    public function crearTicket(CrearTicketCommand $command): CrearTicketResult;

    public function listarTickets(ListarTicketsQuery $query): ListarTicketsResult;

    public function crearMensaje(CrearMensajeCommand $command): CrearMensajeResult;

    public function crearAdjunto(CrearAdjuntoCommand $command): CrearAdjuntoResult;
}
