<?php

declare(strict_types=1);

namespace Famiq\RedmineBridge\Contacts;

use Famiq\RedmineBridge\DTO\BuscarClienteResult;
use Famiq\RedmineBridge\DTO\ClienteDTO;
use Famiq\RedmineBridge\DTO\UpsertClienteResult;
use Famiq\RedmineBridge\RequestContext;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class CustomFieldContactResolver implements ContactResolverInterface
{
    public function __construct(private readonly LoggerInterface $logger = new NullLogger())
    {
    }

    public function buscar(ClienteDTO $criteria, RequestContext $context): BuscarClienteResult
    {
        $this->logger->warning('redmine.contact.custom_field.buscar_not_supported', [
            'correlation_id' => $context->correlationId,
        ]);

        return new BuscarClienteResult('none', []);
    }

    public function upsert(ClienteDTO $cliente, RequestContext $context): UpsertClienteResult
    {
        $this->logger->info('redmine.contact.custom_field.upsert_fallback', [
            'external_id' => $cliente->externalId,
            'correlation_id' => $context->correlationId,
        ]);

        return new UpsertClienteResult('unchanged', null, $cliente->externalId);
    }
}
