<?php

declare(strict_types=1);

namespace Famiq\RedmineBridge;

use Famiq\RedmineBridge\DTO\AdjuntoDTO;
use Famiq\RedmineBridge\DTO\BuscarClienteResult;
use Famiq\RedmineBridge\DTO\ClienteDTO;
use Famiq\RedmineBridge\DTO\ContactDTO;
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

final class RedmineBridge
{
    private RedmineTicketService $ticketService;
    private RedmineClienteService $clienteService;
    private RedmineConfig $config;
    private LoggerInterface $logger;

    public function __construct(
        RedmineConfig $config,
        ClientInterface $client,
        ?LoggerInterface $logger = null,
        ?string $contactSearchPath = '/contacts/search.json',
        ?string $contactUpsertPath = '/contacts.json',
    ) {
        $logger = $logger ?? new NullLogger();

        $this->config = $config;
        $this->logger = $logger;

        $http = new RedmineHttpClient($client, $config, $logger);
        $mapper = new RedminePayloadMapper();
        $customFieldResolver = new RedmineCustomFieldResolver($http);

        $userResolver = new RedmineUserResolver($http, $config, $logger);
        $this->ticketService = new RedmineTicketService($http, $config, $mapper, $customFieldResolver, $logger, $userResolver);
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
        string|ContactDTO $contact,
        int $projectId,
        int $trackerId,
        ?RequestContext $context = null,
        ?array $cliente = null,
    ): CrearTicketResult {
        $resolvedContext = $this->resolveContext($context);
        $contactEmail = $contact instanceof ContactDTO ? $contact->email : $contact;

        // Si el contacto es un empleado Famiq (dominio interno), reemplazamos
        // por el contacto de tipo "company" de la empresa del incidente ($cliente).
        if ($this->config->isInternalEmail($contactEmail) && is_array($cliente)) {
            $replacement = $this->resolveCompanyContactFromCliente($cliente, $contactEmail, $resolvedContext);
            if ($replacement !== null) {
                $contact = $replacement;
                $contactEmail = $replacement->email;
            }
        }

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
            $contact,
            $projectId,
            $trackerId,
            $resolvedContext,
        );
    }

    public function crearHelpdeskTicketConFallback(
        TicketDTO $ticket,
        string|ContactDTO $contact,
        int $projectId,
        int $trackerId,
        ?RequestContext $context = null,
        ?int $contactId = null,
        ?array $cliente = null,
    ): CrearTicketResult {
        $resolvedContext = $this->resolveContext($context);
        $contactEmail = $contact instanceof ContactDTO ? $contact->email : $contact;

        // Si el contacto es un empleado Famiq (dominio interno), reemplazamos
        // por el contacto de tipo "company" de la empresa del incidente ($cliente).
        if ($this->config->isInternalEmail($contactEmail) && is_array($cliente)) {
            try {
                $replacement = $this->resolveCompanyContactFromCliente($cliente, $contactEmail, $resolvedContext);
                if ($replacement !== null) {
                    $contact = $replacement;
                    $contactEmail = $replacement->email;
                    if ($replacement->id !== null && $replacement->id > 0) {
                        $contactId = $replacement->id;
                    }
                }
            } catch (\Throwable $e) {
                // Best-effort: si falla la resolución del contacto company,
                // seguimos con el contacto original.
                $this->logger->warning('redmine.helpdesk.company_contact.resolve_failed', [
                    'correlation_id' => $resolvedContext->correlationId,
                    'error' => get_class($e),
                    'message' => $e->getMessage(),
                ]);
            }
        }

        if ($contactEmail !== '') {
            try {
                $searchResult = $this->clienteService->buscarCliente($contactEmail, null, $resolvedContext);
                if ($searchResult->items === [] && is_array($cliente)) {
                    $this->clienteService->upsertCliente(
                        $this->buildClienteFromArray($cliente, $contactEmail),
                        $resolvedContext,
                    );
                }
            } catch (\Throwable) {
                // Best-effort: don't block ticket creation if client search/upsert fails
            }
        }

        return $this->ticketService->crearHelpdeskTicketConFallback(
            $ticket,
            $contact,
            $projectId,
            $trackerId,
            $resolvedContext,
            $contactId,
        );
    }

    public function listarTickets(
        ?string $status,
        ?int $page = null,
        ?int $perPage = null,
        ?string $clienteRef = null,
        ?string $empresa = null,
        ?RequestContext $context = null,
    ): ListarTicketsResult {
        return $this->ticketService->listarTickets($status, $page, $perPage, $clienteRef, $empresa, $this->resolveContext($context));
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

    /**
     * @return array<string, mixed>
     */
    public function obtenerIssueConDetalles(string|int $issueId, ?RequestContext $context = null): array
    {
        return $this->ticketService->obtenerIssueConDetalles($issueId, $this->resolveContext($context));
    }

    /**
     * @return array<string, mixed>
     */
    public function obtenerIssueBasico(string|int $issueId, ?RequestContext $context = null): array
    {
        return $this->ticketService->obtenerIssueBasico($issueId, $this->resolveContext($context));
    }

    /**
     * @return array<string, mixed>
     */
    public function actualizarIssueSubject(string|int $issueId, string $subject, ?RequestContext $context = null): array
    {
        return $this->ticketService->actualizarIssueSubject($issueId, $subject, $this->resolveContext($context));
    }

    /**
     * @return array<string, mixed>
     */
    public function asignarContactoAIssue(string|int $issueId, int $contactId, ?RequestContext $context = null): array
    {
        return $this->ticketService->asignarContactoAIssue($issueId, $contactId, $this->resolveContext($context));
    }

    /**
     * @return array<string, mixed>
     */
    public function asociarIssueAContacto(int $contactId, string|int $issueId, ?RequestContext $context = null): array
    {
        return $this->clienteService->asociarIssueAContacto($contactId, $issueId, $this->resolveContext($context));
    }

    /**
     * @param array<int, mixed> $customFields
     * @return array<string, mixed>
     */
    public function crearIssueCore(
        int $projectId,
        int $trackerId,
        string $subject,
        string $description,
        array $customFields = [],
        ?RequestContext $context = null,
        ?float $estimatedHours = null,
    ): array {
        return $this->ticketService->crearIssueCore(
            $projectId,
            $trackerId,
            $subject,
            $description,
            $customFields,
            $this->resolveContext($context),
            $estimatedHours,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function buscarContactos(
        string $search,
        int $limit = 100,
        int $offset = 0,
        ?RequestContext $context = null,
    ): array {
        return $this->clienteService->buscarContactos($search, $limit, $offset, $this->resolveContext($context));
    }

    /**
     * @param array<string, mixed> $contactPayload
     * @return array<string, mixed>
     */
    public function crearContacto(array $contactPayload, ?RequestContext $context = null): array
    {
        return $this->clienteService->crearContacto($contactPayload, $this->resolveContext($context));
    }

    /**
     * @param array<string, mixed> $contactPayload
     * @return array<string, mixed>
     */
    public function actualizarContacto(int $contactId, array $contactPayload, ?RequestContext $context = null): array
    {
        return $this->clienteService->actualizarContacto($contactId, $contactPayload, $this->resolveContext($context));
    }

    /**
     * @return array<string, mixed>
     */
    public function obtenerAttachmentInfo(int $attachmentId, ?RequestContext $context = null): array
    {
        return $this->ticketService->obtenerAttachmentInfo($attachmentId, $this->resolveContext($context));
    }

    /**
     * @return array<string, mixed>
     */
    public function obtenerHelpdeskTicket(int $ticketId, ?RequestContext $context = null): array
    {
        return $this->ticketService->obtenerHelpdeskTicket($ticketId, $this->resolveContext($context));
    }

    public function descargarContenido(string $url, ?RequestContext $context = null): string
    {
        return $this->ticketService->descargarContenido($url, $this->resolveContext($context));
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function crearHelpdeskTicketRaw(array $payload, ?RequestContext $context = null): array
    {
        return $this->ticketService->crearHelpdeskTicketRaw($payload, $this->resolveContext($context));
    }

    private function resolveContext(?RequestContext $context): RequestContext
    {
        return $context ?? RequestContext::generate();
    }

    /**
     * Resuelve el contacto de tipo "company" para la empresa del cliente/incidente.
     * Busca (o crea, via upsert) el contacto con is_company=true usando los
     * datos del array $cliente (razonSocial, cuit, externalId, etc.) y retorna
     * un ContactDTO que referencia a ese contacto por id.
     *
     * @param array<string, mixed> $cliente
     */
    private function resolveCompanyContactFromCliente(
        array $cliente,
        string $originalEmail,
        RequestContext $context,
    ): ?ContactDTO {
        $base = $this->buildClienteFromArray($cliente, '');

        // Sin razón social no podemos identificar/crear un contacto company.
        if ($base->razonSocial === null || $base->razonSocial === '') {
            $this->logger->info('redmine.helpdesk.company_contact.skip_no_razon_social', [
                'correlation_id' => $context->correlationId,
                'original_email' => $originalEmail,
            ]);
            return null;
        }

        $empresaDTO = new ClienteDTO(
            'empresa',
            $base->razonSocial,
            null,
            null,
            $base->cuit,
            $base->emails,
            $base->telefonos,
            $base->direccion,
            $base->externalId,
            $base->sourceSystem,
        );

        $result = $this->clienteService->upsertCliente($empresaDTO, $context);

        $id = $result->contactId !== null && $result->contactId !== ''
            ? (int) $result->contactId
            : 0;

        if ($id <= 0) {
            $this->logger->warning('redmine.helpdesk.company_contact.no_id_returned', [
                'correlation_id' => $context->correlationId,
                'razon_social' => $empresaDTO->razonSocial,
                'external_id' => $empresaDTO->externalId,
            ]);
            return null;
        }

        $this->logger->info('redmine.helpdesk.company_contact.resolved', [
            'correlation_id' => $context->correlationId,
            'original_email' => $originalEmail,
            'razon_social' => $empresaDTO->razonSocial,
            'contact_id' => $id,
        ]);

        // Preferimos el email de la empresa (si lo tiene); si no, dejamos el
        // email vacío para que el contacto se identifique sólo por id.
        $primaryEmail = $empresaDTO->emails[0] ?? '';

        return new ContactDTO(
            email: $primaryEmail,
            firstName: $empresaDTO->razonSocial,
            lastName: null,
            id: $id,
        );
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
