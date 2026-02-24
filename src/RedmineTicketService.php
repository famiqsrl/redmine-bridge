<?php

declare(strict_types=1);

namespace Famiq\RedmineBridge;

use Famiq\RedmineBridge\DTO\AdjuntoDTO;
use Famiq\RedmineBridge\DTO\AdjuntoInlineDTO;
use Famiq\RedmineBridge\DTO\ContactDTO;
use Famiq\RedmineBridge\DTO\CrearAdjuntoResult;
use Famiq\RedmineBridge\DTO\CrearMensajeResult;
use Famiq\RedmineBridge\DTO\CrearTicketResult;
use Famiq\RedmineBridge\DTO\ListarTicketsResult;
use Famiq\RedmineBridge\DTO\MensajeDTO;
use Famiq\RedmineBridge\DTO\ObtenerTicketResult;
use Famiq\RedmineBridge\DTO\TicketDTO;
use Famiq\RedmineBridge\Exceptions\MissingRequiredCustomFieldsException;
use Famiq\RedmineBridge\Exceptions\RedmineAuthException;
use Famiq\RedmineBridge\Exceptions\RedmineTransportException;
use Famiq\RedmineBridge\Exceptions\RedmineValidationException;
use Famiq\RedmineBridge\Http\RedmineHttpClient;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class RedmineTicketService
{
    private const CRM_PROJECT_IDENTIFIER = 'r-crm';
    private const CRM_PROJECT_ROLE_ID = 6;

    public function __construct(
        private RedmineHttpClient $client,
        private RedmineConfig $config,
        private RedminePayloadMapper $mapper,
        private RedmineCustomFieldResolver $customFieldResolver,
        private LoggerInterface $logger = new NullLogger(),
        private ?RedmineUserResolver $userResolver = null,
    ) {}

    public function crearTicket(TicketDTO $ticket, int $projectId, int $trackerId, RequestContext $context): CrearTicketResult
    {
        $resolved = $this->resolveUser($context);
        $login = $resolved['login'];
        $extraDescription = $resolved['extraDescription'];
        $userId = $resolved['userId'] ?? null;

        // Si tenemos userId, asegurar membership en el proyecto
        if ($userId !== null) {
            $this->ensureUserHasProjectMembershipById($projectId, $userId, $context);
        }

        if ($extraDescription !== null) {
            $ticket = clone $ticket;
            $ticket->description = $ticket->description . "\n\n" . $extraDescription;
        }

        $payload = $this->buildIssuePayload($ticket, $projectId, $trackerId, $context);

        // Asignar el ticket al usuario resuelto
        if ($userId !== null) {
            $payload['issue']['assigned_to_id'] = $userId;
        }

        if ($ticket->adjuntos !== []) {
            $uploads = $this->uploadAdjuntosInline($ticket->adjuntos, $context);
            $payload['issue']['uploads'] = $uploads;
        }

        $headers = [];

        if ($login !== null) {
            $headers['X-Redmine-Switch-User'] = $login;
        }

        $response = $this->client->request('POST', '/issues.json', $payload, $headers, $context);

        $issue = $response['issue'] ?? null;
        $issueId = is_array($issue) ? (int) ($issue['id'] ?? 0) : 0;
        return new CrearTicketResult($issueId);
    }

    public function crearHelpdeskTicket(
        TicketDTO $ticket,
        string|ContactDTO $contact,
        int $projectId,
        int $trackerId,
        RequestContext $context,
        ?array $cliente = null,
    ): CrearTicketResult {
        $contactDTO = $contact instanceof ContactDTO
            ? $contact
            : new ContactDTO(email: $contact);

        $resolved = $this->resolveUser($context);
        $login = $resolved['login'];
        $extraDescription = $resolved['extraDescription'];
        $userId = $resolved['userId'] ?? null;

        if ($userId !== null) {
            $this->ensureUserHasProjectMembershipById($projectId, $userId, $context);
        }

        if ($extraDescription !== null) {
            $ticket = clone $ticket;
            $ticket->description = $ticket->description . "\n\n" . $extraDescription;
        }

        try {
            $issuePayload = $this->buildIssuePayload($ticket, $projectId, $trackerId, $context);
        } catch (\Throwable $e) {
            $this->logger->warning('redmine.helpdesk.buildIssuePayload.fallback', [
                'correlation_id' => $context->correlationId,
                'error' => get_class($e),
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
            ]);

            $issuePayload = ['issue' => []];
        }

        $issueBlock = $issuePayload['issue'] ?? [];
        $payloadCfs = $issueBlock['custom_fields'] ?? [];
        if (!is_array($payloadCfs)) {
            $payloadCfs = [];
        }

        $dtoCfs = [];
        foreach ($ticket->customFields as $cf) {
            $id = is_array($cf) ? (int) ($cf['id'] ?? 0) : 0;
            $value = is_array($cf) ? ($cf['value'] ?? null) : null;
            if ($id > 0 && $value !== null) {
                $dtoCfs[$id] = $value;
            }
        }

        $mergedById = [];

        foreach ($payloadCfs as $cf) {
            if (!is_array($cf)) {
                continue;
            }
            $id = (int) ($cf['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $mergedById[$id] = $cf['value'] ?? null;
        }

        foreach ($dtoCfs as $id => $value) {
            $mergedById[(int) $id] = $value;
        }

        $finalCfs = [];
        foreach ($mergedById as $id => $value) {
            if ((int) $id <= 0 || $value === null || $value === []) {
                continue;
            }
            $finalCfs[] = ['id' => (int) $id, 'value' => $value];
        }

        if ($finalCfs !== []) {
            $issueBlock['custom_fields'] = array_values($finalCfs);
        }

        $issueBlock['project_id'] = $issueBlock['project_id'] ?? $projectId;
        $issueBlock['tracker_id'] = $issueBlock['tracker_id'] ?? $trackerId;
        $issueBlock['subject'] = $issueBlock['subject'] ?? $ticket->subject;
        $issueBlock['description'] = $issueBlock['description'] ?? $ticket->description;

        if ($ticket->prioridad !== null && !isset($issueBlock['priority_id'])) {
            $issueBlock['priority_id'] = $ticket->prioridad;
        }

        // Redmine start_date admite solo YYYY-MM-DD (sin hora)
        $issueBlock['start_date'] = (new \DateTimeImmutable('now'))->format('Y-m-d');

        if ($ticket->adjuntos !== []) {
            $uploads = $this->uploadAdjuntosInline($ticket->adjuntos, $context);
            $issueBlock['uploads'] = $uploads;
        }

        $headers = [];

        if ($login !== null) {
            $headers['X-Redmine-Switch-User'] = $login;
        }

        $payload = [
            'helpdesk_ticket' => [
                'issue' => array_filter($issueBlock, static fn($v) => $v !== null && $v !== []),
                'contact' => $contactDTO->toPayloadArray(),
            ],
        ];

        if ($contactDTO->id !== null && $contactDTO->id > 0) {
            $payload['helpdesk_ticket']['contact_id'] = $contactDTO->id;
        }

        $this->logger->error('redmine.helpdesk.create.payload', [
            'correlation_id' => $context->correlationId,
            'payload' => $payload,
            'switch_user' => $login,
        ]);

        try {
            $response = $this->client->request('POST', '/helpdesk_tickets.json', $payload, $headers, $context);

            return $this->parseHelpdeskTicketResponse($response);
        } catch (RedmineAuthException $e) {
            $this->logger->error('redmine.helpdesk.create.auth_error', [
                'correlation_id' => $context->correlationId,
                'switch_user' => $login,
                'code' => $e->getCode(),
                'message' => $e->getMessage(),
            ]);

            if ($e->getCode() === 412) {
                $this->logger->error('redmine.helpdesk.create.retry_without_switch_user', [
                    'correlation_id' => $context->correlationId,
                    'original_switch_user' => $login,
                ]);

                $response = $this->client->request('POST', '/helpdesk_tickets.json', $payload, [], $context);

                return $this->parseHelpdeskTicketResponse($response);
            }

            if ($login !== null && $this->shouldAutoGrantCrmMembership($login, $context)) {
                $this->ensureUserHasProjectMembership(
                    projectIdentifier: self::CRM_PROJECT_IDENTIFIER,
                    login: $login,
                    roleId: self::CRM_PROJECT_ROLE_ID,
                    context: $context
                );

                $this->logger->info('redmine.helpdesk.create.retry_after_membership', [
                    'correlation_id' => $context->correlationId,
                    'switch_user' => $login,
                ]);

                $response = $this->client->request('POST', '/helpdesk_tickets.json', $payload, $headers, $context);

                return $this->parseHelpdeskTicketResponse($response);
            }

            throw $e;
        }
    }

    public function listarTickets(
        ?string $status,
        ?int $page,
        ?int $perPage,
        ?string $clienteRef,
        ?string $empresa,
        RequestContext $context,
    ): ListarTicketsResult {
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

        if ($empresa !== null) {
            $params['set_filter'] = 1;
            $params['f[]'] = 'customer_company';
            $params['op[customer_company]'] = '=';
            $params['v[customer_company][]'] = $empresa;
        }

        $path = $this->buildPathWithQuery('/issues.json', $params);

        $response = $this->client->request('GET', $path, null, [], $context);

        $items = $this->normalizeIssueItems($response['issues'] ?? null);
        $total = (int) ($response['total_count'] ?? count($items));
        $page = $page ?? 1;
        $perPage = $perPage ?? count($items);

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

        if ($mensaje->adjuntos !== []) {
            $uploads = $this->uploadAdjuntosInline($mensaje->adjuntos, $context);
            $payload['issue']['uploads'] = $uploads;
        }

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
        $resolved = $this->resolveUser($context);
        $login = $resolved['login'];
        $extraDescription = $resolved['extraDescription'];
        $userId = $resolved['userId'] ?? null;

        if ($userId !== null) {
            $this->ensureUserHasProjectMembershipById($projectId, $userId, $context);
        }

        if ($extraDescription !== null) {
            $description = $description . "\n\n" . $extraDescription;
        }

        $issueData = array_filter([
            'project_id' => $projectId,
            'tracker_id' => $trackerId,
            'subject' => $subject,
            'description' => $description,
            'custom_fields' => $this->buildCustomFields($customFields),
            'start_date' => (new \DateTimeImmutable('now'))->format('Y-m-d'),
        ], static fn($value) => $value !== null && $value !== []);

        if ($userId !== null) {
            $issueData['assigned_to_id'] = $userId;
        }

        $payload = ['issue' => $issueData];

        $headers = [];
        if ($login !== null) {
            $headers['X-Redmine-Switch-User'] = $login;
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
        $resolved = $this->resolveUser($context);
        $login = $resolved['login'];
        $extraDescription = $resolved['extraDescription'];

        if ($extraDescription !== null) {
            /** @var array<string, mixed> $helpdeskTicket */
            $helpdeskTicket = $payload['helpdesk_ticket'] ?? [];
            /** @var array<string, mixed> $issue */
            $issue = $helpdeskTicket['issue'] ?? [];
            $rawDescription = $issue['description'] ?? '';
            $existing = is_string($rawDescription) ? $rawDescription : '';
            $issue['description'] = $existing . "\n\n" . $extraDescription;
            $helpdeskTicket['issue'] = $issue;
            $payload['helpdesk_ticket'] = $helpdeskTicket;
        }

        $headers = [];
        if ($login !== null) {
            $headers['X-Redmine-Switch-User'] = $login;
        }

        // Best-effort start_date si viene issue en el payload raw
        if (isset($payload['helpdesk_ticket']['issue']) && is_array($payload['helpdesk_ticket']['issue'])) {
            if (!isset($payload['helpdesk_ticket']['issue']['start_date'])) {
                $payload['helpdesk_ticket']['issue']['start_date'] = (new \DateTimeImmutable('now'))->format('Y-m-d');
            }
        }

        try {
            return $this->client->request('POST', '/helpdesk_tickets.json', $payload, $headers, $context);
        } catch (RedmineAuthException $e) {
            $this->logger->warning('redmine.helpdesk.raw.auth_error', [
                'correlation_id' => $context->correlationId,
                'switch_user' => $login,
                'code' => $e->getCode(),
                'message' => $e->getMessage(),
            ]);

            if ($login !== null && $this->shouldAutoGrantCrmMembership($login, $context)) {
                $this->ensureUserHasProjectMembership(
                    projectIdentifier: self::CRM_PROJECT_IDENTIFIER,
                    login: $login,
                    roleId: self::CRM_PROJECT_ROLE_ID,
                    context: $context
                );

                $this->logger->info('redmine.helpdesk.raw.retry_after_membership', [
                    'correlation_id' => $context->correlationId,
                    'switch_user' => $login,
                ]);

                return $this->client->request('POST', '/helpdesk_tickets.json', $payload, $headers, $context);
            }

            throw $e;
        }
    }

    /**
     * Intenta crear via /helpdesk_tickets.json; si falla con 404 (plugin
     * no disponible) o 403 (permisos), cae a /issues.json via crearIssueCore().
     */
    public function crearHelpdeskTicketConFallback(
        TicketDTO $ticket,
        string|ContactDTO $contact,
        int $projectId,
        int $trackerId,
        RequestContext $context,
        ?int $contactId = null,
    ): CrearTicketResult {
        try {
            return $this->crearHelpdeskTicket($ticket, $contact, $projectId, $trackerId, $context);
        } catch (RedmineValidationException $e) {
            if ((int) $e->getCode() !== 404) {
                throw $e;
            }

            $this->logger->warning('redmine.helpdesk.fallback_to_issues', [
                'correlation_id' => $context->correlationId,
                'code' => $e->getCode(),
                'message' => $e->getMessage(),
            ]);
        } catch (RedmineAuthException $e) {
            if (!in_array((int) $e->getCode(), [403, 404], true)) {
                throw $e;
            }

            $this->logger->warning('redmine.helpdesk.fallback_to_issues.auth', [
                'correlation_id' => $context->correlationId,
                'code' => $e->getCode(),
                'message' => $e->getMessage(),
            ]);
        }

        // Fallback: crear via /issues.json
        $this->logger->info('redmine.issues.fallback.start', [
            'correlation_id' => $context->correlationId,
            'projectId' => $projectId,
            'trackerId' => $trackerId,
        ]);

        $contactEmail = $contact instanceof ContactDTO ? $contact->email : $contact;
        $contactInfo = '';
        if ($contactEmail !== '') {
            $contactInfo = "\n\n---\nContacto: " . $contactEmail;
        }

        $customFieldsById = [];
        foreach ($ticket->customFields as $key => $value) {
            if (is_array($value) && isset($value['id'], $value['value'])) {
                $customFieldsById[(int) $value['id']] = $value['value'];
            } elseif (is_int($key) || (is_string($key) && ctype_digit($key))) {
                $customFieldsById[(int) $key] = $value;
            }
        }

        $response = $this->crearIssueCore(
            $projectId,
            $trackerId,
            $ticket->subject,
            $ticket->description . $contactInfo,
            $customFieldsById,
            $context,
        );

        $issue = $response['issue'] ?? null;
        $issueId = is_array($issue) ? (int) ($issue['id'] ?? 0) : 0;

        if ($issueId === 0) {
            $issueId = (int) ($response['id'] ?? 0);
        }

        // Best-effort: asociar contacto al issue si tenemos contactId
        if ($contactId !== null && $contactId > 0 && $issueId > 0) {
            try {
                $this->client->request(
                    'PUT',
                    sprintf('/issues/%d.json', $issueId),
                    ['issue' => ['contact_id' => $contactId]],
                    [],
                    $context,
                );
            } catch (\Throwable $e) {
                $this->logger->warning('redmine.issues.fallback.contact_assign_failed', [
                    'correlation_id' => $context->correlationId,
                    'issueId' => $issueId,
                    'contactId' => $contactId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return new CrearTicketResult($issueId);
    }

    /**
     * @param AdjuntoInlineDTO[] $adjuntos
     * @return array<int, array<string, string>>
     */
    private function uploadAdjuntosInline(array $adjuntos, RequestContext $context): array
    {
        $uploads = [];

        foreach ($adjuntos as $adjunto) {
            $content = $this->resolveContent($adjunto->content);
            $token = $this->uploadContent($content, $adjunto->filename, $context);

            $uploads[] = [
                'token' => $token,
                'filename' => $adjunto->filename,
                'content_type' => $adjunto->mime,
            ];
        }

        return $uploads;
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

        $payload = $this->mapper->issuePayload($ticket, $projectId, $trackerId, $resolvedCustomFieldsById);

        if (!isset($payload['issue']) || !is_array($payload['issue'])) {
            $payload['issue'] = [];
        }

        $payload['issue']['start_date'] = (new \DateTimeImmutable('now'))->format('Y-m-d');

        return $payload;
    }

    private function uploadContent(string $content, string $filename, RequestContext $context): string
    {
        $decodedContent = $this->decodeIfBase64($content);

        $response = $this->client->request(
            'POST',
            '/uploads.json?filename=' . urlencode($filename),
            $decodedContent,
            [
                'Content-Type' => 'application/octet-stream',
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

    private function decodeIfBase64(string $content): string
    {
        if (preg_match('/^[a-zA-Z0-9\/\r\n+]*={0,2}$/', $content) && base64_decode($content, true) !== false) {
            $decoded = base64_decode($content, true);
            if ($decoded !== false) {
                return $decoded;
            }
        }

        return $content;
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
            if ($value === null || $value === []) {
                continue;
            }

            if (is_int($key) || (is_string($key) && ctype_digit($key))) {
                $resolved[(int) $key] = $value;
                continue;
            }

            if (!is_string($key) || !array_key_exists($key, $customFieldMap)) {
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

    /**
     * @return array{login: ?string, extraDescription: ?string, userId: ?int}
     */
    private function resolveUser(RequestContext $context): array
    {
        if ($this->userResolver === null || empty($context->idUsuario)) {
            $login = !empty($context->idUsuario) ? (string) $context->idUsuario : null;
            return ['login' => $login, 'extraDescription' => null, 'userId' => null];
        }

        return $this->userResolver->resolveUserForTicket($context);
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
            $normalizedSelect = array_values(array_filter(array_map('strval', $select), static fn($value) => $value !== ''));
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

    private function shouldAutoGrantCrmMembership(string $login, RequestContext $context): bool
    {
        return trim($login) !== '';
    }

    private function ensureUserHasProjectMembership(
        string $projectIdentifier,
        string $login,
        int $roleId,
        RequestContext $context
    ): void {
        // Contexto sin Switch-User: membership es operacion admin
        $adminContext = new RequestContext($context->correlationId);

        $this->logger->info('redmine.crm_membership.ensure.start', [
            'correlation_id' => $context->correlationId,
            'login' => $login,
            'project' => $projectIdentifier,
            'role_id' => $roleId,
        ]);

        $userId = $this->resolveUserIdByLogin($login, $adminContext);
        if ($userId === null) {
            $this->logger->error('redmine.crm_membership.ensure.user_not_found', [
                'correlation_id' => $context->correlationId,
                'login' => $login,
            ]);
            return;
        }

        if ($this->isUserMemberOfProject($projectIdentifier, $userId, $adminContext)) {
            $this->logger->info('redmine.crm_membership.ensure.already_member', [
                'correlation_id' => $context->correlationId,
                'login' => $login,
                'user_id' => $userId,
                'project' => $projectIdentifier,
            ]);
            return;
        }

        $path = sprintf('/projects/%s/memberships.json', $projectIdentifier);
        $payload = [
            'membership' => [
                'user_id' => $userId,
                'role_ids' => [$roleId],
            ],
        ];

        $this->logger->info('redmine.crm_membership.ensure.create.start', [
            'correlation_id' => $context->correlationId,
            'path' => $path,
            'user_id' => $userId,
            'role_id' => $roleId,
        ]);

        $this->client->request('POST', $path, $payload, [], $adminContext);

        $this->logger->info('redmine.crm_membership.ensure.create.ok', [
            'correlation_id' => $context->correlationId,
            'user_id' => $userId,
            'project' => $projectIdentifier,
            'role_id' => $roleId,
        ]);
    }

    /**
     * Asegura que el usuario (por ID numerico) sea miembro del proyecto (por ID numerico).
     * Usa el role CRM_PROJECT_ROLE_ID por defecto. Best-effort: no lanza excepciones.
     */
    private function ensureUserHasProjectMembershipById(int $projectId, int $userId, RequestContext $context): void
    {
        // Contexto sin Switch-User: membership es operacion admin
        $adminContext = new RequestContext($context->correlationId);

        try {
            $path = sprintf('/projects/%d/memberships.json', $projectId);

            // Verificar si ya es miembro
            $response = $this->client->request('GET', $path, null, [], $adminContext);
            $memberships = $response['memberships'] ?? [];
            if (is_array($memberships)) {
                foreach ($memberships as $m) {
                    if (!is_array($m)) {
                        continue;
                    }
                    $user = $m['user'] ?? null;
                    if (is_array($user) && isset($user['id']) && (int) $user['id'] === $userId) {
                        return; // ya es miembro
                    }
                }
            }

            // Crear membership
            $payload = [
                'membership' => [
                    'user_id' => $userId,
                    'role_ids' => [self::CRM_PROJECT_ROLE_ID],
                ],
            ];

            $this->client->request('POST', $path, $payload, [], $adminContext);

            $this->logger->info('redmine.project_membership.created', [
                'correlation_id' => $context->correlationId,
                'user_id' => $userId,
                'project_id' => $projectId,
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('redmine.project_membership.error', [
                'correlation_id' => $context->correlationId,
                'user_id' => $userId,
                'project_id' => $projectId,
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
            ]);
        }
    }

    private function resolveUserIdByLogin(string $login, RequestContext $context): ?int
    {
        $login = trim($login);
        if ($login === '') {
            return null;
        }
        $path = $this->buildPathWithQuery('/users.json', ['name' => $login]);

        try {
            $response = $this->client->request('GET', $path, null, [], $context);
        } catch (\Throwable $e) {
            $this->logger->error('redmine.user.lookup.error', [
                'correlation_id' => $context->correlationId,
                'login' => $login,
                'error' => get_class($e),
                'message' => $e->getMessage(),
            ]);
            return null;
        }

        $users = $response['users'] ?? [];
        if (!is_array($users)) {
            return null;
        }

        foreach ($users as $u) {
            if (!is_array($u)) {
                continue;
            }
            $uLogin = isset($u['login']) ? (string) $u['login'] : '';
            if ($uLogin === $login) {
                $id = isset($u['id']) ? (int) $u['id'] : 0;
                return $id > 0 ? $id : null;
            }
        }

        if (count($users) === 1 && is_array($users[0]) && isset($users[0]['id'])) {
            $id = (int) $users[0]['id'];
            return $id > 0 ? $id : null;
        }

        return null;
    }

    private function isUserMemberOfProject(string $projectIdentifier, int $userId, RequestContext $context): bool
    {
        $path = sprintf('/projects/%s/memberships.json', $projectIdentifier);

        try {
            $response = $this->client->request('GET', $path, null, [], $context);
        } catch (\Throwable $e) {
            $this->logger->error('redmine.memberships.list.error', [
                'correlation_id' => $context->correlationId,
                'project' => $projectIdentifier,
                'error' => get_class($e),
                'message' => $e->getMessage(),
            ]);
            return false;
        }

        $memberships = $response['memberships'] ?? [];
        if (!is_array($memberships)) {
            return false;
        }

        foreach ($memberships as $m) {
            if (!is_array($m)) {
                continue;
            }
            $user = $m['user'] ?? null;
            if (!is_array($user)) {
                continue;
            }
            $id = isset($user['id']) ? (int) $user['id'] : 0;
            if ($id === $userId) {
                return true;
            }
        }

        return false;
    }

    private function parseHelpdeskTicketResponse(array $response): CrearTicketResult
    {
        $issueId = 0;

        $ht = $response['helpdesk_ticket'] ?? null;
        if (is_array($ht)) {
            $issueId = (int) ($ht['id'] ?? 0);
            if ($issueId === 0) {
                $issue = $ht['issue'] ?? null;
                $issueId = is_array($issue) ? (int) ($issue['id'] ?? 0) : 0;
            }
        }

        if ($issueId === 0) {
            $issue = $response['issue'] ?? null;
            $issueId = is_array($issue) ? (int) ($issue['id'] ?? 0) : 0;
        }

        if ($issueId === 0) {
            $issueId = (int) ($response['issue_id'] ?? ($response['id'] ?? 0));
        }

        return new CrearTicketResult($issueId);
    }
}
