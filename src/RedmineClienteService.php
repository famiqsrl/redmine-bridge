<?php

declare(strict_types=1);

namespace Famiq\RedmineBridge;

use Famiq\RedmineBridge\Contacts\ContactResolverInterface;
use Famiq\RedmineBridge\DTO\BuscarClienteResult;
use Famiq\RedmineBridge\DTO\ClienteDTO;
use Famiq\RedmineBridge\DTO\UpsertClienteResult;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class RedmineClienteService
{
    public function __construct(
        private ContactResolverInterface $resolver,
        private LoggerInterface $logger = new NullLogger(),
    ) {
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

        return $this->resolver->buscar($criteria, $context);
    }

    public function upsertCliente(ClienteDTO $cliente, RequestContext $context): UpsertClienteResult
    {
        $this->logger->info('redmine.contact.upsert', [
            'external_id' => $cliente->externalId,
            'correlation_id' => $context->correlationId,
        ]);

        return $this->resolver->upsert($cliente, $context);
    }
}
