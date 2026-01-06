<?php

declare(strict_types=1);

namespace Famiq\RedmineBridge\Contacts;

use Famiq\RedmineBridge\DTO\BuscarClienteResult;
use Famiq\RedmineBridge\DTO\ClienteDTO;
use Famiq\RedmineBridge\DTO\UpsertClienteResult;
use Famiq\RedmineBridge\RequestContext;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class FallbackContactResolver implements ContactResolverInterface
{
    public function __construct(private readonly LoggerInterface $logger = new NullLogger())
    {
    }

    public function buscar(ClienteDTO $criteria, RequestContext $context): BuscarClienteResult
    {
        $this->logger->warning('redmine.contact.fallback.buscar', [
            'correlation_id' => $context->correlationId,
        ]);

        return new BuscarClienteResult('none', []);
    }

    public function upsert(ClienteDTO $cliente, RequestContext $context): UpsertClienteResult
    {
        $this->logger->warning('redmine.contact.fallback.upsert', [
            'external_id' => $cliente->externalId,
            'correlation_id' => $context->correlationId,
        ]);

        return new UpsertClienteResult('unchanged', null, $cliente->externalId);
    }
}
