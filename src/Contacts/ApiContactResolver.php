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

final class ApiContactResolver
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

        $contacts = $response['contacts'] ?? null;
        if (!is_array($contacts)) {
            $contacts = [];
        }

        $items = [];
        foreach ($contacts as $contact) {
            if (!is_array($contact)) {
                continue;
            }
            $items[] = new ClienteDTO(
                'empresa',
                isset($contact['company']) ? (string) $contact['company'] : null,
                isset($contact['first_name']) ? (string) $contact['first_name'] : null,
                isset($contact['last_name']) ? (string) $contact['last_name'] : null,
                isset($contact['tax_id']) ? (string) $contact['tax_id'] : null,
                is_array($contact['emails'] ?? null) ? array_values(array_map('strval', $contact['emails'])) : [],
                is_array($contact['phones'] ?? null) ? array_values(array_map('strval', $contact['phones'])) : [],
                isset($contact['address']) ? (string) $contact['address'] : null,
                isset($contact['external_id']) ? (string) $contact['external_id'] : null,
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
        $contact = $response['contact'] ?? null;
        $contactId = is_array($contact) ? (string) ($contact['id'] ?? '') : '';

        $this->logger->info('redmine.contact.upserted', [
            'contact_id' => $contactId,
            'correlation_id' => $context->correlationId,
        ]);

        return new UpsertClienteResult('updated', $contactId !== '' ? $contactId : null, $cliente->externalId);
    }
}
