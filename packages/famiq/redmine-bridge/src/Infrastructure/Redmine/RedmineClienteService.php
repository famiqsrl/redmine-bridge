<?php

declare(strict_types=1);

namespace Famiq\RedmineBridge\Infrastructure\Redmine;

use Famiq\RedmineBridge\Contracts\DTO\ClienteDTO;
use Famiq\RedmineBridge\Contracts\Interfaces\ClienteServiceInterface;
use Famiq\RedmineBridge\Contracts\Interfaces\ContactResolverInterface;
use Famiq\RedmineBridge\Contracts\Queries\BuscarClienteQuery;
use Famiq\RedmineBridge\Contracts\Results\BuscarClienteResult;
use Famiq\RedmineBridge\Contracts\Results\UpsertClienteResult;
use Famiq\RedmineBridge\Contracts\Commands\UpsertClienteCommand;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class RedmineClienteService implements ClienteServiceInterface
{
    public function __construct(
        private readonly ContactResolverInterface $resolver,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function buscarCliente(BuscarClienteQuery $query): BuscarClienteResult
    {
        $criteria = new ClienteDTO(
            'persona',
            null,
            $query->query,
            null,
            null,
            [],
            [],
            null,
            $query->externalId,
            'unknown',
        );

        $this->logger->info('redmine.contact.buscar', [
            'query' => $query->query,
            'correlation_id' => $query->context->correlationId,
        ]);

        return $this->resolver->buscar($criteria, $query->context);
    }

    public function upsertCliente(UpsertClienteCommand $command): UpsertClienteResult
    {
        $this->logger->info('redmine.contact.upsert', [
            'external_id' => $command->cliente->externalId,
            'correlation_id' => $command->context->correlationId,
        ]);

        return $this->resolver->upsert($command->cliente, $command->context);
    }
}
