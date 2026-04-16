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
        $this->config = $config;
        $this->logger = $logger;
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

        $contact = $this->resolveContactForFamiqEmail($contact, $cliente, $ticket, $resolvedContext);
        $contactEmail = $contact instanceof ContactDTO ? $contact->email : $contact;

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

        $contact = $this->resolveContactForFamiqEmail($contact, $cliente, $ticket, $resolvedContext);
        if ($contact instanceof ContactDTO && $contact->id !== null && $contact->id > 0) {
            $contactId = $contact->id;
        }
        $contactEmail = $contact instanceof ContactDTO ? $contact->email : $contact;

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
     * Cuando el email del contacto pertenece a un dominio interno de Famiq
     * (@famiq.com.ar / @famiq.com.uy), no queremos asociar el ticket al
     * usuario interno sino al contacto correspondiente a la empresa cliente
     * en Redmine. Si encontramos un contacto en Redmine que matchea con la
     * empresa (por externalId / cuit / razonSocial / clienteRef), devolvemos
     * un ContactDTO con esos datos. Caso contrario devolvemos el contacto
     * original sin modificar.
     *
     * @param array<string, mixed>|null $cliente
     */
    private function resolveContactForFamiqEmail(
        string|ContactDTO $contact,
        ?array $cliente,
        TicketDTO $ticket,
        RequestContext $context,
    ): string|ContactDTO {
        $contactEmail = $contact instanceof ContactDTO ? $contact->email : $contact;

        if ($contactEmail === '' || !$this->config->isInternalEmail($contactEmail)) {
            return $contact;
        }

        $companyContact = $this->buscarContactoEmpresa($cliente, $ticket, $context);
        if ($companyContact === null) {
            $this->logger->info('redmine.bridge.famiq_email.no_company_contact', [
                'correlation_id' => $context->correlationId,
                'famiq_email' => $contactEmail,
                'cliente_ref' => $ticket->clienteRef,
                'cliente_external_id' => is_array($cliente) ? ($cliente['externalId'] ?? null) : null,
            ]);
            return $contact;
        }

        $this->logger->info('redmine.bridge.famiq_email.contact_replaced', [
            'correlation_id' => $context->correlationId,
            'famiq_email' => $contactEmail,
            'company_contact_id' => $companyContact->id,
            'company_contact_email' => $companyContact->email,
        ]);

        return $companyContact;
    }

    /**
     * Busca en Redmine el contacto que corresponde a la empresa cliente,
     * usando los identificadores disponibles (externalId, cuit, razonSocial,
     * clienteRef del ticket). Retorna null si no se encuentra ningun contacto
     * con email asociado.
     *
     * @param array<string, mixed>|null $cliente
     */
    private function buscarContactoEmpresa(
        ?array $cliente,
        TicketDTO $ticket,
        RequestContext $context,
    ): ?ContactDTO {
        $externalIds = [];
        $textQueries = [];

        if (is_array($cliente)) {
            $extId = $this->normalizeString($cliente['externalId'] ?? null);
            if ($extId !== null) {
                $externalIds[] = $extId;
            }

            foreach (['cuit', 'razonSocial', 'nombre'] as $key) {
                $value = $this->normalizeString($cliente[$key] ?? null);
                if ($value !== null) {
                    $textQueries[] = $value;
                }
            }
        }

        $clienteRef = $this->normalizeString($ticket->clienteRef);
        if ($clienteRef !== null) {
            $externalIds[] = $clienteRef;
            $textQueries[] = $clienteRef;
        }

        $externalIds = array_values(array_unique($externalIds));
        $textQueries = array_values(array_unique($textQueries));

        foreach ($externalIds as $extId) {
            $contact = $this->buscarContactoEmpresaPorQuery($extId, $extId, $context);
            if ($contact !== null) {
                return $contact;
            }
        }

        foreach ($textQueries as $query) {
            $contact = $this->buscarContactoEmpresaPorQuery($query, null, $context);
            if ($contact !== null) {
                return $contact;
            }
        }

        return null;
    }

    /**
     * Ejecuta una busqueda contra /contacts.json y devuelve el primer contacto
     * que tenga email. Si se pasa $matchExternalId solo se considera el
     * contacto cuyo external_id coincida exactamente.
     */
    private function buscarContactoEmpresaPorQuery(
        string $query,
        ?string $matchExternalId,
        RequestContext $context,
    ): ?ContactDTO {
        try {
            $response = $this->clienteService->buscarContactos($query, 25, 0, $context);
        } catch (\Throwable $e) {
            $this->logger->warning('redmine.bridge.famiq_email.contact_search_failed', [
                'correlation_id' => $context->correlationId,
                'query' => $query,
                'error' => $e->getMessage(),
            ]);
            return null;
        }

        $contacts = $response['contacts'] ?? null;
        if (!is_array($contacts) || $contacts === []) {
            return null;
        }

        foreach ($contacts as $raw) {
            if (!is_array($raw)) {
                continue;
            }

            if ($matchExternalId !== null) {
                $extId = isset($raw['external_id']) ? (string) $raw['external_id'] : '';
                if ($extId !== $matchExternalId) {
                    continue;
                }
            }

            $email = $this->extractContactEmail($raw);
            if ($email === null) {
                continue;
            }

            $id = isset($raw['id']) ? (int) $raw['id'] : 0;
            $firstName = isset($raw['first_name']) ? (string) $raw['first_name'] : null;
            $lastName = isset($raw['last_name']) ? (string) $raw['last_name'] : null;

            // Para contactos tipo empresa, RedmineUP suele dejar first/last vacios
            // y usa el campo "company".
            if (($firstName === null || trim($firstName) === '') && isset($raw['company'])) {
                $firstName = (string) $raw['company'];
            }

            return new ContactDTO(
                email: $email,
                firstName: $firstName,
                lastName: $lastName,
                id: $id > 0 ? $id : null,
            );
        }

        return null;
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function extractContactEmail(array $raw): ?string
    {
        $emails = $raw['emails'] ?? null;
        if (is_array($emails)) {
            foreach ($emails as $email) {
                if (!is_string($email)) {
                    continue;
                }
                $trimmed = trim($email);
                if ($trimmed === '' || $this->config->isInternalEmail($trimmed)) {
                    continue;
                }
                return $trimmed;
            }
        }

        if (isset($raw['email']) && is_string($raw['email'])) {
            $trimmed = trim($raw['email']);
            if ($trimmed !== '' && !$this->config->isInternalEmail($trimmed)) {
                return $trimmed;
            }
        }

        return null;
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
