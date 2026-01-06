<?php

declare(strict_types=1);

namespace Famiq\ActiveDirectoryUser\RedmineBridge;

use Famiq\RedmineBridge\DTO\AdjuntoDTO;
use Famiq\RedmineBridge\DTO\BuscarClienteResult;
use Famiq\RedmineBridge\DTO\ClienteDTO;
use Famiq\RedmineBridge\DTO\CrearAdjuntoResult;
use Famiq\RedmineBridge\DTO\CrearMensajeResult;
use Famiq\RedmineBridge\DTO\CrearTicketResult;
use Famiq\RedmineBridge\DTO\ListarTicketsResult;
use Famiq\RedmineBridge\DTO\MensajeDTO;
use Famiq\RedmineBridge\DTO\TicketDTO;
use Famiq\RedmineBridge\DTO\UpsertClienteResult;
use Famiq\RedmineBridge\RedmineClienteService;
use Famiq\RedmineBridge\RedmineTicketService;
use Famiq\RedmineBridge\RequestContext;

final class RedmineBridgeFacade
{
    public function __construct(
        private readonly RedmineClienteService $clienteService,
        private readonly RedmineTicketService $ticketService,
    ) {
    }

    public function buscarCliente(string $query, ?string $externalId, ?string $correlationId): BuscarClienteResult
    {
        return $this->clienteService->buscarCliente($query, $externalId, $this->context($correlationId));
    }

    public function upsertCliente(ClienteDTO $cliente, ?string $correlationId): UpsertClienteResult
    {
        return $this->clienteService->upsertCliente($cliente, $this->context($correlationId));
    }

    public function crearTicket(TicketDTO $ticket, string $idempotencyKey, ?string $correlationId): CrearTicketResult
    {
        return $this->ticketService->crearTicket($ticket, $idempotencyKey, $this->context($correlationId));
    }

    public function listarTickets(?string $status, ?int $page, ?int $perPage, ?string $clienteRef, ?string $correlationId): ListarTicketsResult
    {
        return $this->ticketService->listarTickets($status, $page, $perPage, $clienteRef, $this->context($correlationId));
    }

    public function crearMensaje(int $issueId, string $body, string $visibility, ?string $authorRef, ?string $correlationId): CrearMensajeResult
    {
        $dto = new MensajeDTO($issueId, $body, $visibility, $authorRef);

        return $this->ticketService->crearMensaje($dto, $this->context($correlationId));
    }

    public function crearAdjunto(AdjuntoDTO $adjunto, string $idempotencyKey, ?string $correlationId): CrearAdjuntoResult
    {
        return $this->ticketService->crearAdjunto($adjunto, $idempotencyKey, $this->context($correlationId));
    }

    private function context(?string $correlationId): RequestContext
    {
        return $correlationId !== null ? new RequestContext($correlationId) : RequestContext::generate();
    }
}
