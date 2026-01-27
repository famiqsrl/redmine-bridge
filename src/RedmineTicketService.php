<?php

declare(strict_types=1);

namespace Famiq\RedmineBridge;

use Famiq\RedmineBridge\DTO\AdjuntoDTO;
use Famiq\RedmineBridge\DTO\CrearAdjuntoResult;
use Famiq\RedmineBridge\DTO\CrearMensajeResult;
use Famiq\RedmineBridge\DTO\CrearTicketResult;
use Famiq\RedmineBridge\DTO\ListarTicketsResult;
use Famiq\RedmineBridge\DTO\MensajeDTO;
use Famiq\RedmineBridge\DTO\ObtenerTicketResult;
use Famiq\RedmineBridge\DTO\TicketDTO;
use Famiq\RedmineBridge\Exceptions\MissingRequiredCustomFieldsException;
use Famiq\RedmineBridge\Exceptions\RedmineTransportException;
use Famiq\RedmineBridge\Http\RedmineHttpClient;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class RedmineTicketService
{
    public function __construct(
        private RedmineHttpClient $client,
        private RedmineConfig $config,
        private RedminePayloadMapper $mapper,
        private RedmineCustomFieldResolver $customFieldResolver,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function crearTicket(TicketDTO $ticket, int $projectId, int $trackerId, RequestContext $context): CrearTicketResult
    {
        $payload = $this->buildIssuePayload($ticket, $projectId, $trackerId, $context);
        $headers = [];

        if (!empty($context->idUsuario)) {
            $headers['X-Redmine-Switch-User'] = (string) $context->idUsuario;
        }

        $response = $this->client->request('POST', '/issues.json', $payload, $headers, $context);

        $issue = $response['issue'] ?? null;
        $issueId = is_array($issue) ? (int) ($issue['id'] ?? 0) : 0;
        return new CrearTicketResult($issueId);
    }

    public function crearHelpdeskTicket(
        TicketDTO $ticket,
        string $contactEmail,
        int $projectId,
        int $trackerId,
        RequestContext $context,
        ?array $cliente = null,
    ): CrearTicketResult {
        $issuePayload = $this->buildIssuePayload($ticket, $projectId, $trackerId, $context);
        $headers = [];

        if (!empty($context->idUsuario)) {
            $headers['X-Redmine-Switch-User'] = (string) $context->idUsuario;
        }

        $payload = [
            'helpdesk_ticket' => [
                'issue' => $issuePayload['issue'] ?? [],
                'contact' => [
                    'email' => $contactEmail,
                ],
            ],
        ];

        $response = $this->client->request('POST', '/helpdesk_tickets.json', $payload, $headers, $context);
        $issue = $response['helpdesk_ticket'] ?? null;

        $issueId = is_array($issue) ? (int) ($response['helpdesk_ticket']['id'] ?? 0) : 0;

        return new CrearTicketResult($issueId);
    }

    public function listarTickets(?string $status, ?int $page, ?int $perPage, ?string $clienteRef, RequestContext $context): ListarTicketsResult
    {
        $filters = [
            'status_id' => $status,
        ];

        if ($clienteRef !== null) {
            $customFieldId = $this->config->customFieldMap['cliente_ref'] ?? null;
            if ($customFieldId !== null) {
                $filters['cf_' . $customFieldId] = $clienteRef;
            }
        }

        $params = $this->buildTicketQueryParams($filters, null, $page, $perPage);
        $path = $this->buildPathWithQuery('/issues.json', $params);

        $response = $this->client->request('GET', $path, null, [], $context);

        $items = $this->normalizeIssueItems($response['issues'] ?? null);
        $total = (int) ($response['total_count'] ?? count($items));
        $page = $page ?? 1;
        $perPage = $perPage ?? count($items);

        return new ListarTicketsResult($items, $total, $page, $perPage);
    }

    public function listarTicketsPorEmpresa(
        string $empresa,
        ?string $status,
        ?int $page,
        ?int $perPage,
        ?string $clienteRef,
        RequestContext $context,
    ): ListarTicketsResult {
        $contactIds = $this->findContactIdsByEmpresaWithFallback($empresa, $context);
        if ($contactIds === []) {
            return new ListarTicketsResult([], 0, $page ?? 1, $perPage ?? 0);
        }

        $issueIdsById = [];
        foreach ($contactIds as $contactId) {
            foreach ($this->fetchContactTicketIds($contactId, $context) as $issueId) {
                $issueIdsById[(string) $issueId] = $issueId;
            }
        }

        if ($issueIdsById === []) {
            return new ListarTicketsResult([], 0, $page ?? 1, $perPage ?? 0);
        }

        $issueCache = [];
        $items = [];
        $projectId = $this->resolveOptionalContextFilter($context, 'projectId');
        $trackerId = $this->resolveOptionalContextFilter($context, 'trackerId');

        foreach ($issueIdsById as $issueId) {
            if (!array_key_exists($issueId, $issueCache)) {
                $issueCache[$issueId] = $this->fetchTicketDetails((int) $issueId, $context);
            }
            $ticket = $issueCache[$issueId];
            if ($ticket === []) {
                continue;
            }

            if (!$this->ticketMatchesFilters($ticket, $status, $projectId, $trackerId, $clienteRef)) {
                continue;
            }

            $items[] = $ticket;
        }

        usort(
            $items,
            static fn (array $left, array $right): int => (int) ($right['id'] ?? 0) <=> (int) ($left['id'] ?? 0),
        );

        $page = $page ?? 1;
        $total = count($items);

        if ($perPage === null) {
            $perPage = $total;
        } else {
            $offset = max(0, ($page - 1) * $perPage);
            $items = array_slice($items, $offset, $perPage);
        }

        return new ListarTicketsResult($items, $total, $page, $perPage);
    }

    /**
     * @param array<string, mixed> $filters
     * @param string[]|null $select
     */
    public function consultarTickets(
        array $filters,
        ?array $select,
        ?int $page,
        ?int $perPage,
        RequestContext $context,
    ): ListarTicketsResult {
        $params = $this->buildTicketQueryParams($filters, $select, $page, $perPage);
        $path = $this->buildPathWithQuery('/issues.json', $params);

        $response = $this->client->request('GET', $path, null, [], $context);
        $items = $this->normalizeIssueItems($response['issues'] ?? null);
        $total = (int) ($response['total_count'] ?? count($items));
        $page = $page ?? 1;
        $perPage = $perPage ?? count($items);

        return new ListarTicketsResult($items, $total, $page, $perPage);
    }

    /**
     * @param string[]|null $select
     */
    public function obtenerTicket(int $issueId, ?array $select, RequestContext $context): ObtenerTicketResult
    {
        $params = $this->buildTicketQueryParams([], $select, null, null);
        $path = $this->buildPathWithQuery(sprintf('/issues/%d.json', $issueId), $params);

        $response = $this->client->request('GET', $path, null, [], $context);
        $issue = $response['issue'] ?? null;

        return new ObtenerTicketResult(is_array($issue) ? $issue : []);
    }

    public function crearMensaje(MensajeDTO $mensaje, RequestContext $context): CrearMensajeResult
    {
        $payload = $this->mapper->messagePayload($mensaje);
        $this->client->request('PUT', sprintf('/issues/%d.json', $mensaje->issueId), $payload, [], $context);

        return new CrearMensajeResult(null);
    }

    public function crearAdjunto(AdjuntoDTO $adjunto, RequestContext $context): CrearAdjuntoResult
    {
        $content = $this->resolveContent($adjunto->content);
        $token = $this->uploadContent($content, $adjunto->filename, $context);

        $payload = [
            'issue' => [
                'uploads' => [
                    [
                        'token' => $token,
                        'filename' => $adjunto->filename,
                        'content_type' => $adjunto->mime,
                    ],
                ],
            ],
        ];

        $this->client->request('PUT', sprintf('/issues/%d.json', $adjunto->issueId), $payload, [], $context);

        return new CrearAdjuntoResult(null);
    }

    /**
     * @return array<string, mixed>
     */
    public function obtenerIssueConDetalles(string|int $issueId, RequestContext $context): array
    {
        $path = $this->buildPathWithQuery(sprintf('/issues/%s.json', $issueId), [
            'include' => 'journals,attachments,relations,watchers',
        ]);

        return $this->client->request('GET', $path, null, [], $context);
    }

    /**
     * @return array<string, mixed>
     */
    public function obtenerIssueBasico(string|int $issueId, RequestContext $context): array
    {
        $path = $this->buildPathWithQuery(sprintf('/issues/%s.json', $issueId), [
            'include' => 'journals,attachments',
        ]);

        return $this->client->request('GET', $path, null, [], $context);
    }

    /**
     * @return array<string, mixed>
     */
    public function actualizarIssueSubject(string|int $issueId, string $subject, RequestContext $context): array
    {
        $payload = [
            'issue' => [
                'subject' => $subject,
            ],
        ];

        return $this->client->request('PUT', sprintf('/issues/%s.json', $issueId), $payload, [], $context);
    }

    /**
     * @return array<string, mixed>
     */
    public function asignarContactoAIssue(string|int $issueId, int $contactId, RequestContext $context): array
    {
        $payload = [
            'issue' => [
                'contact_id' => $contactId,
            ],
        ];

        return $this->client->request('PUT', sprintf('/issues/%s.json', $issueId), $payload, [], $context);
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
        array $customFields,
        RequestContext $context,
    ): array {
        $payload = [
            'issue' => array_filter([
                'project_id' => $projectId,
                'tracker_id' => $trackerId,
                'subject' => $subject,
                'description' => $description,
                'custom_fields' => $this->buildCustomFields($customFields),
            ], static fn ($value) => $value !== null && $value !== []),
        ];

        $headers = [];
        if (!empty($context->idUsuario)) {
            $headers['X-Redmine-Switch-User'] = (string) $context->idUsuario;
        }

        return $this->client->request('POST', '/issues.json', $payload, $headers, $context);
    }

    /**
     * @return array<string, mixed>
     */
    public function obtenerAttachmentInfo(int $attachmentId, RequestContext $context): array
    {
        return $this->client->request('GET', sprintf('/attachments/%d.json', $attachmentId), null, [], $context);
    }

    public function descargarContenido(string $url, RequestContext $context): string
    {
        return $this->client->requestRaw('GET', $url, null, [], $context);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function crearHelpdeskTicketRaw(array $payload, RequestContext $context): array
    {
        $headers = [];
        if (!empty($context->idUsuario)) {
            $headers['X-Redmine-Switch-User'] = (string) $context->idUsuario;
        }

        return $this->client->request('POST', '/helpdesk_tickets.json', $payload, $headers, $context);
    }

    private function resolveContent(string $content): string
    {
        if (is_file($content)) {
            $data = file_get_contents($content);
            if ($data === false) {
                throw new RedmineTransportException('Unable to read attachment content');
            }
            return $data;
        }

        return $content;
    }

    /**
     * @param array<int, mixed> $customFields
     * @return array<int, array<string, mixed>>
     */
    private function buildCustomFields(array $customFields): array
    {
        $fields = [];

        foreach ($customFields as $id => $value) {
            $fieldId = is_numeric($id) ? (int) $id : null;
            if ($fieldId === null || $value === null || $value === []) {
                continue;
            }

            $fields[] = [
                'id' => $fieldId,
                'value' => $value,
            ];
        }

        return $fields;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildIssuePayload(TicketDTO $ticket, int $projectId, int $trackerId, RequestContext $context): array
    {
        $issueCustomFields = $this->customFieldResolver->getIssueCustomFieldsForTracker($trackerId, $context);
        $customFieldMap = $this->customFieldResolver->getIssueCustomFieldNameToIdMapForTracker($trackerId, $context);
        $resolvedCustomFieldsById = $this->resolveCustomFieldsById($ticket->customFields, $customFieldMap);
        $resolvedCustomFieldsById = $this->autofillRequiredCustomFields($issueCustomFields, $resolvedCustomFieldsById);
        $this->assertRequiredCustomFields($trackerId, $issueCustomFields, $resolvedCustomFieldsById, $customFieldMap);

        return $this->mapper->issuePayload($ticket, $projectId, $trackerId, $resolvedCustomFieldsById);
    }

    private function uploadContent(string $content, string $filename, RequestContext $context): string
    {
        $response = $this->client->request(
            'POST',
            '/uploads.json',
            $content,
            [
                'Content-Type' => 'application/octet-stream',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            ],
            $context,
        );

        $upload = $response['upload'] ?? null;
        $token = is_array($upload) ? ($upload['token'] ?? null) : null;
        if (!is_string($token)) {
            $this->logger->error('redmine.upload_missing_token', [
                'filename' => $filename,
                'correlation_id' => $context?->correlationId,
            ]);
            throw new RedmineTransportException('Redmine upload token missing');
        }

        return $token;
    }

    /**
     * @param array<string, mixed> $customFields
     * @param array<string, int> $customFieldMap
     * @return array<int, mixed>
     */
    private function resolveCustomFieldsById(array $customFields, array $customFieldMap): array
    {
        $resolved = [];

        foreach ($customFields as $key => $value) {
            if (!array_key_exists($key, $customFieldMap)) {
                continue;
            }

            $resolved[$customFieldMap[$key]] = $value;
        }

        return $resolved;
    }

    /**
     * @param array<int, array<string, mixed>> $issueCustomFields
     * @param array<int, mixed> $resolvedCustomFieldsById
     * @return array<int, mixed>
     */
    private function autofillRequiredCustomFields(array $issueCustomFields, array $resolvedCustomFieldsById): array
    {
        foreach ($issueCustomFields as $field) {
            if (($field['required'] ?? false) !== true) {
                continue;
            }

            $fieldId = (int) ($field['id'] ?? 0);
            if (!array_key_exists($fieldId, $resolvedCustomFieldsById)) {
                $possibleValues = $field['possible_values'] ?? [];
                if (is_array($possibleValues) && $possibleValues !== []) {
                    $resolvedCustomFieldsById[$fieldId] = $possibleValues[0];
                }
            }
        }

        return $resolvedCustomFieldsById;
    }

    /**
     * @param array<int, array<string, mixed>> $issueCustomFields
     * @param array<int, mixed> $resolvedCustomFieldsById
     * @param array<string, int> $customFieldMap
     */
    private function assertRequiredCustomFields(
        int $trackerId,
        array $issueCustomFields,
        array $resolvedCustomFieldsById,
        array $customFieldMap,
    ): void {
        $missingIds = [];
        $missingKeys = [];
        /** @var array<int, string> $idToName */
        $idToName = array_flip($customFieldMap);

        foreach ($issueCustomFields as $field) {
            if (($field['required'] ?? false) !== true) {
                continue;
            }

            $fieldId = (int) ($field['id'] ?? 0);
            $value = $resolvedCustomFieldsById[$fieldId] ?? null;

            if (!$this->isMissingRequiredValue($value)) {
                continue;
            }

            $missingIds[] = $fieldId;
            $nameKey = $idToName[(string) $fieldId] ?? null;
            if ($nameKey !== null) {
                $missingKeys[] = $nameKey;
            }
        }

        if ($missingIds !== []) {
            throw new MissingRequiredCustomFieldsException($trackerId, $missingKeys, $missingIds);
        }
    }

    private function isMissingRequiredValue(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        if (is_string($value) && $value === '') {
            return true;
        }

        if (is_array($value) && $value === []) {
            return true;
        }

        return false;
    }

    /**
     * @param array<string, mixed> $filters
     * @param string[]|null $select
     * @return array<string, mixed>
     */
    private function buildTicketQueryParams(array $filters, ?array $select, ?int $page, ?int $perPage): array
    {
        $params = [];

        foreach ($filters as $key => $value) {
            if ($value === null) {
                continue;
            }
            $params[$key] = $value;
        }

        if ($select !== null) {
            $normalizedSelect = array_values(array_filter(array_map('strval', $select), static fn ($value) => $value !== ''));
            if ($normalizedSelect !== []) {
                $params['select'] = implode(',', $normalizedSelect);
            }
        }

        if ($page !== null) {
            $params['page'] = $page;
        }

        if ($perPage !== null) {
            $params['limit'] = $perPage;
        }

        return $params;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function buildPathWithQuery(string $path, array $params): string
    {
        if ($params === []) {
            return $path;
        }

        return $path . '?' . http_build_query($params);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function normalizeIssueItems(mixed $items): array
    {
        if (!is_array($items)) {
            return [];
        }

        $normalized = [];
        foreach ($items as $item) {
            if (is_array($item)) {
                $normalized[] = $item;
            }
        }

        return $normalized;
    }

    /**
     * @return array<int, int>
     */
    private function fetchContactTicketIds(int $contactId, RequestContext $context): array
    {
        $path = $this->buildPathWithQuery(sprintf('/contacts/%d.json', $contactId), [
            'include' => 'tickets',
        ]);

        $response = $this->client->request('GET', $path, null, [], $context);
        $contact = $response['contact'] ?? null;
        if (!is_array($contact)) {
            return [];
        }

        return $this->extractIssueIdsFromContactTickets($contact['tickets'] ?? null);
    }

    /**
     * @return array<int, int>
     */
    private function extractIssueIdsFromContactTickets(mixed $tickets): array
    {
        if (!is_array($tickets)) {
            return [];
        }

        $ids = [];
        foreach ($tickets as $ticket) {
            if (!is_array($ticket)) {
                continue;
            }

            $issueId = $ticket['issue_id'] ?? $ticket['id'] ?? null;
            if ($issueId === null || !is_numeric($issueId)) {
                continue;
            }

            $ids[(int) $issueId] = (int) $issueId;
        }

        return array_values($ids);
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchTicketDetails(int $issueId, RequestContext $context): array
    {
        $ticket = $this->fetchHelpdeskTicketDetails($issueId, $context);
        if ($ticket !== [] && $this->isUsableTicketArray($ticket)) {
            return $ticket;
        }

        return $this->fetchIssueDetails($issueId, $context);
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchHelpdeskTicketDetails(int $issueId, RequestContext $context): array
    {
        $response = $this->client->request('GET', sprintf('/helpdesk_tickets/%d.json', $issueId), null, [], $context);
        $ticket = $response['helpdesk_ticket'] ?? null;
        if (!is_array($ticket)) {
            return [];
        }

        $issue = $ticket['issue'] ?? null;
        if (is_array($issue)) {
            return $issue;
        }

        return $ticket;
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchIssueDetails(int $issueId, RequestContext $context): array
    {
        $response = $this->client->request('GET', sprintf('/issues/%d.json', $issueId), null, [], $context);
        $issue = $response['issue'] ?? null;

        return is_array($issue) ? $issue : [];
    }

    /**
     * @param array<string, mixed> $ticket
     */
    private function isUsableTicketArray(array $ticket): bool
    {
        return array_key_exists('id', $ticket);
    }

    /**
     * @param array<string, mixed> $ticket
     */
    private function ticketMatchesFilters(
        array $ticket,
        ?string $status,
        ?int $projectId,
        ?int $trackerId,
        ?string $clienteRef,
    ): bool {
        if ($status !== null && (string) $this->extractIdFromNestedArray($ticket, 'status') !== (string) $status) {
            return false;
        }

        if ($projectId !== null && $this->extractIdFromNestedArray($ticket, 'project') !== $projectId) {
            return false;
        }

        if ($trackerId !== null && $this->extractIdFromNestedArray($ticket, 'tracker') !== $trackerId) {
            return false;
        }

        if ($clienteRef !== null) {
            $customFieldId = $this->config->customFieldMap['cliente_ref'] ?? null;
            if ($customFieldId !== null) {
                $value = $this->findCustomFieldValue($ticket, $customFieldId);
                if ($value === null) {
                    return false;
                }

                if (is_array($value)) {
                    if (!in_array((string) $clienteRef, array_map('strval', $value), true)) {
                        return false;
                    }
                } elseif ((string) $value !== (string) $clienteRef) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $ticket
     */
    private function extractIdFromNestedArray(array $ticket, string $key): ?int
    {
        $nested = $ticket[$key] ?? null;
        if (is_array($nested) && array_key_exists('id', $nested) && is_numeric($nested['id'])) {
            return (int) $nested['id'];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $ticket
     */
    private function findCustomFieldValue(array $ticket, int $customFieldId): string|array|null
    {
        $fields = $ticket['custom_fields'] ?? null;
        if (!is_array($fields)) {
            return null;
        }

        foreach ($fields as $field) {
            if (!is_array($field)) {
                continue;
            }

            if ((int) ($field['id'] ?? 0) !== $customFieldId) {
                continue;
            }

            return $field['value'] ?? null;
        }

        return null;
    }

    private function resolveOptionalContextFilter(RequestContext $context, string $property): ?int
    {
        if (!property_exists($context, $property)) {
            return null;
        }

        $value = $context->{$property};
        if (is_int($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        return null;
    }

    /**
     * @return array<int, int>
     */
    private function findContactIdsByCompany(string $empresa, RequestContext $context): array
    {
        $contacts = $this->fetchContactsBySearch($empresa, $context);

        return $this->filterContactIdsByField($contacts, 'company', $empresa);
    }

    /**
     * @return array<int, int>
     */
    private function findContactIdsByFirstName(string $empresa, RequestContext $context): array
    {
        $contacts = $this->fetchContactsBySearch($empresa, $context);

        return $this->filterContactIdsByField($contacts, 'first_name', $empresa);
    }

    /**
     * @return array<int, int>
     */
    private function findContactIdsByEmpresaWithFallback(string $empresa, RequestContext $context): array
    {
        $ids = $this->findContactIdsByCompany($empresa, $context);
        if ($ids !== []) {
            return $ids;
        }

        return $this->findContactIdsByFirstName($empresa, $context);
    }

    /**
     * @return array<int, mixed>
     */
    private function fetchContactsBySearch(string $empresa, RequestContext $context): array
    {
        $path = $this->buildPathWithQuery('/contacts.json', [
            'search' => $empresa,
            'limit' => 200, // evita falsos "no hay" por paginado/limit default
        ]);

        $response = $this->client->request('GET', $path, null, [], $context);
        $contacts = $response['contacts'] ?? null;

        return is_array($contacts) ? $contacts : [];
    }

    /**
     * @param array<int, mixed> $contacts
     * @return array<int, int>
     */
    private function filterContactIdsByField(array $contacts, string $field, string $empresa): array
    {
        $ids = [];

        foreach ($contacts as $contact) {
            if (!is_array($contact)) {
                continue;
            }

            $value = isset($contact[$field]) ? (string) $contact[$field] : null;
            if ($value === null || $value !== $empresa) {
                continue;
            }

            $id = isset($contact['id']) ? (int) $contact['id'] : null;
            if ($id !== null) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }
}
