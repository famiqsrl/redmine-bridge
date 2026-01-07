<?php

declare(strict_types=1);

namespace Famiq\RedmineBridge;

use Famiq\RedmineBridge\DTO\AdjuntoDTO;
use Famiq\RedmineBridge\DTO\CrearAdjuntoResult;
use Famiq\RedmineBridge\DTO\CrearMensajeResult;
use Famiq\RedmineBridge\DTO\CrearTicketResult;
use Famiq\RedmineBridge\DTO\ListarTicketsResult;
use Famiq\RedmineBridge\DTO\MensajeDTO;
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
        $issueCustomFields = $this->customFieldResolver->getIssueCustomFieldsForTracker($trackerId, $context);
        $customFieldMap = $this->customFieldResolver->getIssueCustomFieldNameToIdMapForTracker($trackerId, $context);
        $resolvedCustomFieldsById = $this->resolveCustomFieldsById($ticket->customFields, $customFieldMap);
        $this->assertRequiredCustomFields($trackerId, $issueCustomFields, $resolvedCustomFieldsById, $customFieldMap);

        $payload = $this->mapper->issuePayload($ticket, $projectId, $trackerId, $resolvedCustomFieldsById);
        $response = $this->client->request('POST', '/issues.json', $payload, [], $context);

        $issueId = (int) ($response['issue']['id'] ?? 0);
        return new CrearTicketResult($issueId);
    }

    public function listarTickets(?string $status, ?int $page, ?int $perPage, ?string $clienteRef, RequestContext $context): ListarTicketsResult
    {
        $params = array_filter([
            'status_id' => $status,
            'page' => $page,
            'limit' => $perPage,
        ], static fn ($value) => $value !== null);

        $path = '/issues.json';
        if ($params !== []) {
            $path .= '?' . http_build_query($params);
        }

        $response = $this->client->request('GET', $path, null, [], $context);

        $items = $response['issues'] ?? [];
        $total = (int) ($response['total_count'] ?? count($items));
        $page = $page ?? 1;
        $perPage = $perPage ?? count($items);

        return new ListarTicketsResult($items, $total, $page, $perPage);
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

        $token = $response['upload']['token'] ?? null;
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
}
