<?php

declare(strict_types=1);

namespace Famiq\RedmineBridge\Infrastructure\Redmine;

use Famiq\RedmineBridge\Contracts\Commands\CrearAdjuntoCommand;
use Famiq\RedmineBridge\Contracts\Commands\CrearMensajeCommand;
use Famiq\RedmineBridge\Contracts\Commands\CrearTicketCommand;
use Famiq\RedmineBridge\Contracts\DTO\IdempotencyRecord;
use Famiq\RedmineBridge\Contracts\Interfaces\IdempotencyStoreInterface;
use Famiq\RedmineBridge\Contracts\Interfaces\TicketServiceInterface;
use Famiq\RedmineBridge\Contracts\Queries\ListarTicketsQuery;
use Famiq\RedmineBridge\Contracts\Results\CrearAdjuntoResult;
use Famiq\RedmineBridge\Contracts\Results\CrearMensajeResult;
use Famiq\RedmineBridge\Contracts\Results\CrearTicketResult;
use Famiq\RedmineBridge\Contracts\Results\ListarTicketsResult;
use Famiq\RedmineBridge\Domain\Exceptions\RedmineTransportException;
use Famiq\RedmineBridge\Contracts\Context\RequestContext;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class RedmineTicketService implements TicketServiceInterface
{
    public function __construct(
        private readonly RedmineHttpClient $client,
        private readonly RedmineConfig $config,
        private readonly RedminePayloadMapper $mapper,
        private readonly IdempotencyStoreInterface $idempotencyStore,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function crearTicket(CrearTicketCommand $command): CrearTicketResult
    {
        $existing = $this->idempotencyStore->find('crear_ticket', $command->idempotencyKey);
        if ($existing !== null) {
            $payload = json_decode($existing->responsePayload, true);
            return new CrearTicketResult((int) ($payload['issue_id'] ?? 0), true);
        }

        $payload = $this->mapper->issuePayload($command->ticket, $this->config);
        $response = $this->client->request('POST', '/issues.json', $payload, [], $command->context);

        $issueId = (int) ($response['issue']['id'] ?? 0);
        $record = new IdempotencyRecord(
            'crear_ticket',
            $command->idempotencyKey,
            hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
            json_encode(['issue_id' => $issueId], JSON_THROW_ON_ERROR),
            date('c'),
        );
        $this->idempotencyStore->save($record);

        return new CrearTicketResult($issueId, false);
    }

    public function listarTickets(ListarTicketsQuery $query): ListarTicketsResult
    {
        $params = array_filter([
            'status_id' => $query->status,
            'page' => $query->page,
            'limit' => $query->perPage,
        ], static fn ($value) => $value !== null);

        $path = '/issues.json';
        if ($params !== []) {
            $path .= '?' . http_build_query($params);
        }

        $response = $this->client->request('GET', $path, null, [], $query->context);

        $items = $response['issues'] ?? [];
        $total = (int) ($response['total_count'] ?? count($items));
        $page = $query->page ?? 1;
        $perPage = $query->perPage ?? count($items);

        return new ListarTicketsResult($items, $total, $page, $perPage);
    }

    public function crearMensaje(CrearMensajeCommand $command): CrearMensajeResult
    {
        $payload = $this->mapper->messagePayload($command->mensaje);
        $this->client->request('PUT', sprintf('/issues/%d.json', $command->mensaje->issueId), $payload, [], $command->context);

        return new CrearMensajeResult(null);
    }

    public function crearAdjunto(CrearAdjuntoCommand $command): CrearAdjuntoResult
    {
        $existing = $this->idempotencyStore->find('crear_adjunto', $command->idempotencyKey);
        if ($existing !== null) {
            $payload = json_decode($existing->responsePayload, true);
            return new CrearAdjuntoResult($payload['attachment_id'] ?? null, true);
        }

        $content = $this->resolveContent($command->adjunto->content);
        $token = $this->uploadContent($content, $command->adjunto->filename, $command->context);

        $payload = [
            'issue' => [
                'uploads' => [
                    [
                        'token' => $token,
                        'filename' => $command->adjunto->filename,
                        'content_type' => $command->adjunto->mime,
                    ],
                ],
            ],
        ];

        $this->client->request('PUT', sprintf('/issues/%d.json', $command->adjunto->issueId), $payload, [], $command->context);

        $record = new IdempotencyRecord(
            'crear_adjunto',
            $command->idempotencyKey,
            $command->adjunto->sha256 ?? hash('sha256', $content),
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
