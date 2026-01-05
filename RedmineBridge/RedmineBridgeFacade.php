<?php

declare(strict_types=1);

namespace Famiq\ActiveDirectoryUser\RedmineBridge;

use Famiq\RedmineBridge\Contracts\Commands\CrearAdjuntoCommand;
use Famiq\RedmineBridge\Contracts\Commands\CrearMensajeCommand;
use Famiq\RedmineBridge\Contracts\Commands\CrearTicketCommand;
use Famiq\RedmineBridge\Contracts\Commands\UpsertClienteCommand;
use Famiq\RedmineBridge\Contracts\Context\RequestContext;
use Famiq\RedmineBridge\Contracts\DTO\AdjuntoDTO;
use Famiq\RedmineBridge\Contracts\DTO\ClienteDTO;
use Famiq\RedmineBridge\Contracts\DTO\MensajeDTO;
use Famiq\RedmineBridge\Contracts\DTO\TicketDTO;
use Famiq\RedmineBridge\Contracts\Interfaces\ClienteServiceInterface;
use Famiq\RedmineBridge\Contracts\Interfaces\TicketServiceInterface;
use Famiq\RedmineBridge\Contracts\Queries\BuscarClienteQuery;
use Famiq\RedmineBridge\Contracts\Queries\ListarTicketsQuery;
use Famiq\RedmineBridge\Contracts\Results\BuscarClienteResult;
use Famiq\RedmineBridge\Contracts\Results\CrearAdjuntoResult;
use Famiq\RedmineBridge\Contracts\Results\CrearMensajeResult;
use Famiq\RedmineBridge\Contracts\Results\CrearTicketResult;
use Famiq\RedmineBridge\Contracts\Results\ListarTicketsResult;
use Famiq\RedmineBridge\Contracts\Results\UpsertClienteResult;

final class RedmineBridgeFacade
{
    public function __construct(
        private readonly ClienteServiceInterface $clienteService,
        private readonly TicketServiceInterface $ticketService,
    ) {
    }

    public function buscarCliente(string $query, ?string $externalId, ?string $correlationId): BuscarClienteResult
    {
        return $this->clienteService->buscarCliente(new BuscarClienteQuery(
            $query,
            $externalId,
            $this->context($correlationId),
        ));
    }

    public function upsertCliente(ClienteDTO $cliente, ?string $correlationId): UpsertClienteResult
    {
        return $this->clienteService->upsertCliente(new UpsertClienteCommand(
            $cliente,
            $this->context($correlationId),
        ));
    }

    public function crearTicket(TicketDTO $ticket, string $idempotencyKey, ?string $correlationId): CrearTicketResult
    {
        return $this->ticketService->crearTicket(new CrearTicketCommand(
            $ticket,
            $idempotencyKey,
            $this->context($correlationId),
        ));
    }

    public function listarTickets(?string $status, ?int $page, ?int $perPage, ?string $clienteRef, ?string $correlationId): ListarTicketsResult
    {
        return $this->ticketService->listarTickets(new ListarTicketsQuery(
            $status,
            $page,
            $perPage,
            $clienteRef,
            $this->context($correlationId),
        ));
    }

    public function crearMensaje(int $issueId, string $body, string $visibility, ?string $authorRef, ?string $correlationId): CrearMensajeResult
    {
        $dto = new MensajeDTO($issueId, $body, $visibility, $authorRef);

        return $this->ticketService->crearMensaje(new CrearMensajeCommand($dto, $this->context($correlationId)));
    }

    public function crearAdjunto(AdjuntoDTO $adjunto, string $idempotencyKey, ?string $correlationId): CrearAdjuntoResult
    {
        return $this->ticketService->crearAdjunto(new CrearAdjuntoCommand(
            $adjunto,
            $idempotencyKey,
            $this->context($correlationId),
        ));
    }

    private function context(?string $correlationId): RequestContext
    {
        return $correlationId !== null ? new RequestContext($correlationId) : RequestContext::generate();
    }
}
