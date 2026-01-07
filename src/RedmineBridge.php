<?php

declare(strict_types=1);

namespace Famiq\RedmineBridge;

use Famiq\RedmineBridge\DTO\AdjuntoDTO;
use Famiq\RedmineBridge\DTO\BuscarClienteResult;
use Famiq\RedmineBridge\DTO\ClienteDTO;
use Famiq\RedmineBridge\DTO\CrearAdjuntoResult;
use Famiq\RedmineBridge\DTO\CrearMensajeResult;
use Famiq\RedmineBridge\DTO\CrearTicketResult;
use Famiq\RedmineBridge\DTO\ListarTicketsResult;
use Famiq\RedmineBridge\DTO\MensajeDTO;
use Famiq\RedmineBridge\DTO\ObtenerTicketResult;
use Famiq\RedmineBridge\DTO\TicketDTO;
use Famiq\RedmineBridge\DTO\UpsertClienteResult;

final class RedmineBridge
{
    public function __construct(
        private RedmineTicketService $ticketService,
        private RedmineClienteService $clienteService,
    ) {
    }

    public function crearTicket(
        TicketDTO $ticket,
        int $projectId,
        int $trackerId,
        ?RequestContext $context = null,
    ): CrearTicketResult {
        return $this->ticketService->crearTicket($ticket, $projectId, $trackerId, $this->resolveContext($context));
    }

    public function listarTickets(
        ?string $status,
        ?int $page = null,
        ?int $perPage = null,
        ?string $clienteRef = null,
        ?RequestContext $context = null,
    ): ListarTicketsResult {
        return $this->ticketService->listarTickets($status, $page, $perPage, $clienteRef, $this->resolveContext($context));
    }

    /**
     * @param array<string, mixed> $filters
     * @param string[]|null $select
     */
    public function consultarTickets(
        array $filters,
        ?array $select = null,
        ?int $page = null,
        ?int $perPage = null,
        ?RequestContext $context = null,
    ): ListarTicketsResult {
        return $this->ticketService->consultarTickets($filters, $select, $page, $perPage, $this->resolveContext($context));
    }

    /**
     * @param string[]|null $select
     */
    public function obtenerTicket(int $issueId, ?array $select = null, ?RequestContext $context = null): ObtenerTicketResult
    {
        return $this->ticketService->obtenerTicket($issueId, $select, $this->resolveContext($context));
    }

    public function crearMensaje(MensajeDTO $mensaje, ?RequestContext $context = null): CrearMensajeResult
    {
        return $this->ticketService->crearMensaje($mensaje, $this->resolveContext($context));
    }

    public function crearAdjunto(AdjuntoDTO $adjunto, ?RequestContext $context = null): CrearAdjuntoResult
    {
        return $this->ticketService->crearAdjunto($adjunto, $this->resolveContext($context));
    }

    public function buscarCliente(string $query, ?string $externalId = null, ?RequestContext $context = null): BuscarClienteResult
    {
        return $this->clienteService->buscarCliente($query, $externalId, $this->resolveContext($context));
    }

    public function upsertCliente(ClienteDTO $cliente, ?RequestContext $context = null): UpsertClienteResult
    {
        return $this->clienteService->upsertCliente($cliente, $this->resolveContext($context));
    }

    private function resolveContext(?RequestContext $context): RequestContext
    {
        return $context ?? RequestContext::generate();
    }
}
