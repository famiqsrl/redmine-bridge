<?php

declare(strict_types=1);

namespace Famiq\RedmineBridge\Infrastructure\Redmine\Contacts;

use Famiq\RedmineBridge\Contracts\Context\RequestContext;
use Famiq\RedmineBridge\Contracts\DTO\ClienteDTO;
use Famiq\RedmineBridge\Contracts\Interfaces\ContactResolverInterface;
use Famiq\RedmineBridge\Contracts\Results\BuscarClienteResult;
use Famiq\RedmineBridge\Contracts\Results\UpsertClienteResult;
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
