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

        $searchValue = $cliente->externalId ?? $cliente->razonSocial ?? $cliente->nombre ?? '';
        $searchPath = $this->searchPath . '?' . http_build_query(['search' => $searchValue]);

        $searchResponse = $this->client->request('GET', $searchPath, null, [], $context);

        $contacts = $searchResponse['contacts'] ?? [];
        if (!is_array($contacts)) {
            $contacts = [];
        }

        $matchedId = null;
        foreach ($contacts as $c) {
            if (!is_array($c)) {
                continue;
            }

            $ext = isset($c['external_id']) ? (string) $c['external_id'] : null;
            if ($cliente->externalId !== null && $ext !== null && $ext === $cliente->externalId) {
                $matchedId = isset($c['id']) ? (string) $c['id'] : null;
                break;
            }
        }

        $primaryEmail = $cliente->emails[0] ?? null;
        $primaryPhone = $cliente->telefonos[0] ?? null;

        $payload = [
            'contact' => array_filter([
                'is_company' => $cliente->razonSocial !== null && $cliente->razonSocial !== '',
                'company'    => $cliente->razonSocial,
                'first_name' => $cliente->nombre,
                'last_name'  => $cliente->apellido,

                'email' => $primaryEmail,
                'phone' => $primaryPhone,

                'address_attributes' => $cliente->direccion ? ['full_address' => $cliente->direccion] : null,

                'external_id' => $cliente->externalId,
                'source'      => $cliente->sourceSystem,
            ], static fn($v) => $v !== null && $v !== ''),
        ];

        if ($matchedId !== null && $matchedId !== '') {
            $updatePath = preg_replace('#/contacts\.json$#', '/contacts/' . $matchedId . '.json', $this->upsertPath) ?? ('/contacts/' . $matchedId . '.json');

            $response = $this->client->request('PUT', $updatePath, $payload, [], $context);

            $contact = $response['contact'] ?? null;
            $contactId = is_array($contact) ? (string) ($contact['id'] ?? $matchedId) : $matchedId;

            $this->logger->info('redmine.contact.updated', [
                'contact_id' => $contactId,
                'correlation_id' => $context->correlationId,
            ]);

            return new UpsertClienteResult('updated', $contactId !== '' ? $contactId : null, $cliente->externalId);
        }

        $response = $this->client->request('POST', $this->upsertPath, $payload, [], $context);
        $contact = $response['contact'] ?? null;
        $contactId = is_array($contact) ? (string) ($contact['id'] ?? '') : '';

        $this->logger->info('redmine.contact.created', [
            'contact_id' => $contactId,
            'correlation_id' => $context->correlationId,
        ]);

        return new UpsertClienteResult('created', $contactId !== '' ? $contactId : null, $cliente->externalId);
    }
}
