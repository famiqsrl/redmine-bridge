<?php

declare(strict_types=1);

namespace Famiq\RedmineBridge;

use Famiq\RedmineBridge\Contacts\ApiContactResolver;
use Famiq\RedmineBridge\Contacts\ContactResolverInterface;
use Famiq\RedmineBridge\DTO\BuscarClienteResult;
use Famiq\RedmineBridge\DTO\ClienteDTO;
use Famiq\RedmineBridge\DTO\UpsertClienteResult;
use Famiq\RedmineBridge\Http\RedmineHttpClient;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class RedmineClienteService
{
    private ContactResolverInterface $searchResolver;
    private ContactResolverInterface $upsertResolver;

    public function __construct(
        RedmineHttpClient $client,
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
}
