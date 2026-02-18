<?php

declare(strict_types=1);

namespace Famiq\RedmineBridge;

use Famiq\RedmineBridge\DTO\AdjuntoDTO;
use Famiq\RedmineBridge\DTO\AdjuntoInlineDTO;
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
    ) {
    }

    public function crearTicket(TicketDTO $ticket, int $projectId, int $trackerId, RequestContext $context): CrearTicketResult
    {
        $resolved = $this->resolveUser($context);
        $login = $resolved['login'];
        $extraDescription = $resolved['extraDescription'];

        if ($extraDescription !== null) {
            $ticket = clone $ticket;
            $ticket->description = $ticket->description . "\n\n" . $extraDescription;
        }

        $payload = $this->buildIssuePayload($ticket, $projectId, $trackerId, $context);

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
        string $contactEmail,
        int $projectId,
        int $trackerId,
        RequestContext $context,
        ?array $cliente = null,
    ): CrearTicketResult {
        $resolved = $this->resolveUser($context);
        $login = $resolved['login'];
        $extraDescription = $resolved['extraDescription'];

        if ($extraDescription !== null) {
            $ticket = clone $ticket;
            $ticket->description = $ticket->description . "\n\n" . $extraDescription;
        }

        $issuePayload = $this->buildIssuePayload($ticket, $projectId, $trackerId, $context);

        if ($ticket->adjuntos !== []) {
            $uploads = $this->uploadAdjuntosInline($ticket->adjuntos, $context);
            $issuePayload['issue']['uploads'] = $uploads;
        }

        $headers = [];

        if ($login !== null) {
            $headers['X-Redmine-Switch-User'] = $login;
        }

        $payload = [
            'helpdesk_ticket' => [
                'issue' => $issuePayload['issue'] ?? [],
                'contact' => [
                    'email' => $contactEmail,
                ],
            ],
        ];

        try {
            $response = $this->client->request('POST', '/helpdesk_tickets.json', $payload, $headers, $context);

            $issue = $response['helpdesk_ticket'] ?? null;
            $issueId = is_array($issue) ? (int) ($response['helpdesk_ticket']['id'] ?? 0) : 0;

            return new CrearTicketResult($issueId);
        } catch (RedmineAuthException $e) {
            $this->logger->warning('redmine.helpdesk.create.auth_error', [
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

                $this->logger->info('redmine.helpdesk.create.retry_after_membership', [
                    'correlation_id' => $context->correlationId,
                    'switch_user' => $login,
                ]);

                $response = $this->client->request('POST', '/helpdesk_tickets.json', $payload, $headers, $context);

                $issue = $response['helpdesk_ticket'] ?? null;
                $issueId = is_array($issue) ? (int) ($response['helpdesk_ticket']['id'] ?? 0) : 0;

                return new CrearTicketResult($issueId);
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

        if ($extraDescription !== null) {
            $description = $description . "\n\n" . $extraDescription;
        }

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

        return $this->mapper->issuePayload($ticket, $projectId, $trackerId, $resolvedCustomFieldsById);
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

    /**
     * @return array{login: ?string, extraDescription: ?string}
     */
    private function resolveUser(RequestContext $context): array
    {
        if ($this->userResolver === null || empty($context->idUsuario)) {
            $login = !empty($context->idUsuario) ? (string) $context->idUsuario : null;
            return ['login' => $login, 'extraDescription' => null];
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
        $this->logger->info('redmine.crm_membership.ensure.start', [
            'correlation_id' => $context->correlationId,
            'login' => $login,
            'project' => $projectIdentifier,
            'role_id' => $roleId,
        ]);

        $userId = $this->resolveUserIdByLogin($login, $context);
        if ($userId === null) {
            $this->logger->error('redmine.crm_membership.ensure.user_not_found', [
                'correlation_id' => $context->correlationId,
                'login' => $login,
            ]);
            return;
        }

        if ($this->isUserMemberOfProject($projectIdentifier, $userId, $context)) {
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

        $this->client->request('POST', $path, $payload, [], $context);

        $this->logger->info('redmine.crm_membership.ensure.create.ok', [
            'correlation_id' => $context->correlationId,
            'user_id' => $userId,
            'project' => $projectIdentifier,
            'role_id' => $roleId,
        ]);
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
}
