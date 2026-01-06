<?php

declare(strict_types=1);

namespace Famiq\RedmineBridge\Contacts;

use Famiq\RedmineBridge\DTO\BuscarClienteResult;
use Famiq\RedmineBridge\DTO\ClienteDTO;
use Famiq\RedmineBridge\DTO\UpsertClienteResult;
use Famiq\RedmineBridge\Exceptions\RedmineTransportException;
use Famiq\RedmineBridge\Http\RedmineHttpClient;
use Famiq\RedmineBridge\RequestContext;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class ApiContactResolver implements ContactResolverInterface
{
    public function __construct(
        private RedmineHttpClient $client,
        private ?string $searchPath,
        private ?string $upsertPath,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function buscar(ClienteDTO $criteria, RequestContext $context): BuscarClienteResult
    {
        if ($this->searchPath === null) {
            throw new RedmineTransportException('Contacts API search path not configured');
        }

        $path = $this->searchPath . '?' . http_build_query([
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
        if ($this->upsertPath === null) {
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

        $response = $this->client->request('POST', $this->upsertPath, $payload, [], $context);
        $contactId = (string) ($response['contact']['id'] ?? '');

        $this->logger->info('redmine.contact.upserted', [
            'contact_id' => $contactId,
            'correlation_id' => $context->correlationId,
        ]);

        return new UpsertClienteResult('updated', $contactId !== '' ? $contactId : null, $cliente->externalId);
    }
}
