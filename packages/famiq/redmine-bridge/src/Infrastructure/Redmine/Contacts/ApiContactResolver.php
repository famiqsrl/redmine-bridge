<?php

declare(strict_types=1);

namespace Famiq\RedmineBridge\Infrastructure\Redmine\Contacts;

use Famiq\RedmineBridge\Contracts\Context\RequestContext;
use Famiq\RedmineBridge\Contracts\DTO\ClienteDTO;
use Famiq\RedmineBridge\Contracts\Interfaces\ContactResolverInterface;
use Famiq\RedmineBridge\Contracts\Results\BuscarClienteResult;
use Famiq\RedmineBridge\Contracts\Results\UpsertClienteResult;
use Famiq\RedmineBridge\Domain\Exceptions\RedmineTransportException;
use Famiq\RedmineBridge\Infrastructure\Redmine\RedmineConfig;
use Famiq\RedmineBridge\Infrastructure\Redmine\RedmineHttpClient;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class ApiContactResolver implements ContactResolverInterface
{
    public function __construct(
        private readonly RedmineHttpClient $client,
        private readonly RedmineConfig $config,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function buscar(ClienteDTO $criteria, RequestContext $context): BuscarClienteResult
    {
        if ($this->config->contactsSearchPath === null) {
            throw new RedmineTransportException('Contacts API search path not configured');
        }

        $path = $this->config->contactsSearchPath . '?' . http_build_query([
            'q' => $criteria->externalId ?? $criteria->razonSocial ?? $criteria->nombre ?? '',
        ]);

        $response = $this->client->request('GET', $path, null, [], $context);

        $items = [];
        foreach ($response['contacts'] ?? [] as $contact) {
            $items[] = new ClienteDTO(
                'empresa',
                $contact['company'] ?? null,
                $contact['first_name'] ?? null,
                $contact['last_name'] ?? null,
                $contact['tax_id'] ?? null,
                $contact['emails'] ?? [],
                $contact['phones'] ?? [],
                $contact['address'] ?? null,
                $contact['external_id'] ?? null,
                'redmineup',
            );
        }

        $matchType = $items === [] ? 'none' : 'probable';

        return new BuscarClienteResult($matchType, $items);
    }

    public function upsert(ClienteDTO $cliente, RequestContext $context): UpsertClienteResult
    {
        if ($this->config->contactsUpsertPath === null) {
            throw new RedmineTransportException('Contacts API upsert path not configured');
        }

        $payload = [
            'contact' => [
                'company' => $cliente->razonSocial,
                'first_name' => $cliente->nombre,
                'last_name' => $cliente->apellido,
                'tax_id' => $cliente->cuit,
                'emails' => $cliente->emails,
                'phones' => $cliente->telefonos,
                'address' => $cliente->direccion,
                'external_id' => $cliente->externalId,
                'source' => $cliente->sourceSystem,
            ],
        ];

        $response = $this->client->request('POST', $this->config->contactsUpsertPath, $payload, [], $context);
        $contactId = (string) ($response['contact']['id'] ?? '');

        $this->logger->info('redmine.contact.upserted', [
            'contact_id' => $contactId,
            'correlation_id' => $context->correlationId,
        ]);

        return new UpsertClienteResult('updated', $contactId !== '' ? $contactId : null, $cliente->externalId);
    }
}
