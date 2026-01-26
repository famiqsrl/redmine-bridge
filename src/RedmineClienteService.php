<?php

declare(strict_types=1);

namespace Famiq\RedmineBridge;

use Famiq\RedmineBridge\Contacts\ApiContactResolver;
use Famiq\RedmineBridge\DTO\BuscarClienteResult;
use Famiq\RedmineBridge\DTO\ClienteDTO;
use Famiq\RedmineBridge\DTO\UpsertClienteResult;
use Famiq\RedmineBridge\Http\RedmineHttpClient;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class RedmineClienteService
{
    private ApiContactResolver $searchResolver;
    private ApiContactResolver $upsertResolver;

    public function __construct(
        private RedmineHttpClient $client,
        ?string $contactSearchPath,
        ?string $contactUpsertPath,
        private LoggerInterface $logger = new NullLogger(),
    ) {
        $this->searchResolver = new ApiContactResolver($client, $contactSearchPath, null, $logger);
        $this->upsertResolver = new ApiContactResolver($client, null, $contactUpsertPath, $logger);
    }

    public function buscarCliente(string $query, ?string $externalId, RequestContext $context): BuscarClienteResult
    {
        $criteria = new ClienteDTO(
            'persona',
            null,
            $query,
            null,
            null,
            [],
            [],
            null,
            $externalId,
            'unknown',
        );

        $this->logger->info('redmine.contact.buscar', [
            'query' => $query,
            'correlation_id' => $context->correlationId,
        ]);

        return $this->searchResolver->buscar($criteria, $context);
    }

    public function upsertCliente(ClienteDTO $cliente, RequestContext $context): UpsertClienteResult
    {
        $this->logger->info('redmine.contact.upsert', [
            'external_id' => $cliente->externalId,
            'correlation_id' => $context->correlationId,
        ]);

        return $this->upsertResolver->upsert($cliente, $context);
    }

    /**
     * @return array<string, mixed>
     */
    public function buscarContactos(string $search, int $limit, int $offset, RequestContext $context): array
    {
        $path = '/contacts.json?' . http_build_query([
            'limit' => $limit,
            'offset' => $offset,
            'search' => $search,
        ]);

        return $this->client->request('GET', $path, null, [], $context);
    }

    /**
     * @param array<string, mixed> $contactPayload
     * @return array<string, mixed>
     */
    public function crearContacto(array $contactPayload, RequestContext $context): array
    {
        return $this->client->request('POST', '/contacts.json', $contactPayload, [], $context);
    }

    /**
     * @param array<string, mixed> $contactPayload
     * @return array<string, mixed>
     */
    public function actualizarContacto(int $contactId, array $contactPayload, RequestContext $context): array
    {
        return $this->client->request('PUT', sprintf('/contacts/%d.json', $contactId), $contactPayload, [], $context);
    }

    /**
     * @return array<string, mixed>
     */
    public function asociarIssueAContacto(int $contactId, string|int $issueId, RequestContext $context): array
    {
        $payload = [
            'issue_id' => $issueId,
        ];

        return $this->client->request(
            'POST',
            sprintf('/contacts/%d/issues.json', $contactId),
            $payload,
            [],
            $context,
        );
    }
}
