<?php

declare(strict_types=1);

namespace Famiq\RedmineBridge\Infrastructure\Redmine\Contacts;

use Famiq\RedmineBridge\Contracts\Context\RequestContext;
use Famiq\RedmineBridge\Contracts\DTO\ClienteDTO;
use Famiq\RedmineBridge\Contracts\Interfaces\ContactResolverInterface;
use Famiq\RedmineBridge\Contracts\Results\BuscarClienteResult;
use Famiq\RedmineBridge\Contracts\Results\UpsertClienteResult;
use Famiq\RedmineBridge\Infrastructure\Redmine\RedmineConfig;

final class ContactResolverSelector implements ContactResolverInterface
{
    public function __construct(
        private readonly RedmineConfig $config,
        private readonly ContactResolverInterface $apiResolver,
        private readonly ContactResolverInterface $customFieldResolver,
        private readonly ContactResolverInterface $fallbackResolver,
    ) {
    }

    public function buscar(ClienteDTO $criteria, RequestContext $context): BuscarClienteResult
    {
        return $this->resolver()->buscar($criteria, $context);
    }

    public function upsert(ClienteDTO $cliente, RequestContext $context): UpsertClienteResult
    {
        return $this->resolver()->upsert($cliente, $context);
    }

    private function resolver(): ContactResolverInterface
    {
        return match ($this->config->contactStrategy) {
            'api' => $this->apiResolver,
            'custom_field' => $this->customFieldResolver,
            default => $this->fallbackResolver,
        };
    }
}
