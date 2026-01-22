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
use Famiq\RedmineBridge\Http\RedmineHttpClient;
use Psr\Http\Client\ClientInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Facade principal para operar con la API de Redmine.
 */
final class RedmineBridge
{
    private RedmineTicketService $ticketService;
    private RedmineClienteService $clienteService;

    public function __construct(
        RedmineConfig $config,
        ClientInterface $client,
        ?LoggerInterface $logger = null,
        ?string $contactSearchPath = '/contacts/search.json',
        ?string $contactUpsertPath = '/contacts.json',
    ) {
        $logger = $logger ?? new NullLogger();

        $http = new RedmineHttpClient($client, $config, $logger);
        $mapper = new RedminePayloadMapper();
        $customFieldResolver = new RedmineCustomFieldResolver($http);

        $this->ticketService = new RedmineTicketService($http, $config, $mapper, $customFieldResolver, $logger);
        $this->clienteService = new RedmineClienteService(
            $http,
            $contactSearchPath,
            $contactUpsertPath,
            $logger,
        );
    }

    public function crearTicket(
        TicketDTO $ticket,
        int $projectId,
        int $trackerId,
        ?RequestContext $context = null,
    ): CrearTicketResult {
        return $this->ticketService->crearTicket($ticket, $projectId, $trackerId, $this->resolveContext($context));
    }

    public function crearHelpdeskTicket(
        TicketDTO $ticket,
        string $contactEmail,
        int $projectId,
        int $trackerId,
        ?RequestContext $context = null,
        ?array $cliente = null,
    ): CrearTicketResult {
        $resolvedContext = $this->resolveContext($context);

        if ($contactEmail !== '') {
            $searchResult = $this->clienteService->buscarCliente($contactEmail, null, $resolvedContext);
            if ($searchResult->items === [] && is_array($cliente)) {
                $this->clienteService->upsertCliente(
                    $this->buildClienteFromArray($cliente, $contactEmail),
                    $resolvedContext,
                );
            }
        }

        return $this->ticketService->crearHelpdeskTicket(
            $ticket,
            $contactEmail,
            $projectId,
            $trackerId,
            $resolvedContext,
        );
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

    /**
     * @param array<string, mixed> $cliente
     */
    private function buildClienteFromArray(array $cliente, string $contactEmail): ClienteDTO
    {
        $emails = $this->normalizeStringArray($cliente['emails'] ?? []);
        if ($contactEmail !== '' && !in_array($contactEmail, $emails, true)) {
            $emails[] = $contactEmail;
        }

        return new ClienteDTO(
            $this->normalizeString($cliente['tipo'] ?? null) ?? 'persona',
            $this->normalizeString($cliente['razonSocial'] ?? null),
            $this->normalizeString($cliente['nombre'] ?? null),
            $this->normalizeString($cliente['apellido'] ?? null),
            $this->normalizeString($cliente['cuit'] ?? null),
            $emails,
            $this->normalizeStringArray($cliente['telefonos'] ?? []),
            $this->normalizeString($cliente['direccion'] ?? null),
            $this->normalizeString($cliente['externalId'] ?? null),
            $this->normalizeString($cliente['sourceSystem'] ?? null) ?? 'helpdesk',
        );
    }

    private function normalizeString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);
        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @return string[]
     */
    private function normalizeStringArray(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $item) {
            if (!is_string($item)) {
                continue;
            }

            $trimmed = trim($item);
            if ($trimmed !== '') {
                $items[] = $trimmed;
            }
        }

        return $items;
    }
}
