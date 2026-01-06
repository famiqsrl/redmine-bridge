<?php

declare(strict_types=1);

namespace Famiq\RedmineBridge;

use Famiq\RedmineBridge\DTO\AdjuntoDTO;
use Famiq\RedmineBridge\DTO\CrearAdjuntoResult;
use Famiq\RedmineBridge\DTO\CrearMensajeResult;
use Famiq\RedmineBridge\DTO\CrearTicketResult;
use Famiq\RedmineBridge\DTO\IdempotencyRecord;
use Famiq\RedmineBridge\DTO\ListarTicketsResult;
use Famiq\RedmineBridge\DTO\MensajeDTO;
use Famiq\RedmineBridge\DTO\TicketDTO;
use Famiq\RedmineBridge\Exceptions\RedmineTransportException;
use Famiq\RedmineBridge\Http\RedmineHttpClient;
use Famiq\RedmineBridge\Idempotency\IdempotencyStoreInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class RedmineTicketService
{
    public function __construct(
        private readonly RedmineHttpClient $client,
        private readonly RedmineConfig $config,
        private readonly RedminePayloadMapper $mapper,
        private readonly IdempotencyStoreInterface $idempotencyStore,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function crearTicket(TicketDTO $ticket, string $idempotencyKey, RequestContext $context): CrearTicketResult
    {
        $existing = $this->idempotencyStore->find('crear_ticket', $idempotencyKey);
        if ($existing !== null) {
            $payload = json_decode($existing->responsePayload, true);
            return new CrearTicketResult((int) ($payload['issue_id'] ?? 0), true);
        }

        $payload = $this->mapper->issuePayload($ticket, $this->config);
        $response = $this->client->request('POST', '/issues.json', $payload, [], $context);

        $issueId = (int) ($response['issue']['id'] ?? 0);
        $record = new IdempotencyRecord(
            'crear_ticket',
            $idempotencyKey,
            hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
            json_encode(['issue_id' => $issueId], JSON_THROW_ON_ERROR),
            date('c'),
        );
        $this->idempotencyStore->save($record);

        return new CrearTicketResult($issueId, false);
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

    public function crearAdjunto(AdjuntoDTO $adjunto, string $idempotencyKey, RequestContext $context): CrearAdjuntoResult
    {
        $existing = $this->idempotencyStore->find('crear_adjunto', $idempotencyKey);
        if ($existing !== null) {
            $payload = json_decode($existing->responsePayload, true);
            return new CrearAdjuntoResult($payload['attachment_id'] ?? null, true);
        }

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

        $record = new IdempotencyRecord(
            'crear_adjunto',
            $idempotencyKey,
            $adjunto->sha256 ?? hash('sha256', $content),
            json_encode(['attachment_id' => null], JSON_THROW_ON_ERROR),
            date('c'),
        );
        $this->idempotencyStore->save($record);

        return new CrearAdjuntoResult(null, false);
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
}
