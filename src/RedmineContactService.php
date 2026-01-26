<?php

declare(strict_types=1);

namespace Famiq\RedmineBridge;

use Famiq\RedmineBridge\DTO\ContactDTO;
use Famiq\RedmineBridge\Exceptions\RedmineTransportException;
use Famiq\RedmineBridge\Http\RedmineHttpClient;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class RedmineContactService
{
    public function __construct(
        private RedmineHttpClient $client,
        private ?string $contactsPath,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function findContactIdByEmail(string $email, RequestContext $context): ?int
    {
        $normalizedEmail = $this->normalizeEmail($email);
        if ($normalizedEmail === '') {
            return null;
        }

        $path = $this->buildContactsSearchPath($normalizedEmail);

        $response = $this->client->request('GET', $path, null, [], $context);
        $contacts = $response['contacts'] ?? [];
        if (!is_array($contacts)) {
            return null;
        }

        foreach ($contacts as $contact) {
            if (!is_array($contact)) {
                continue;
            }

            if (!$this->contactMatchesEmail($contact, $normalizedEmail)) {
                continue;
            }

            $id = (int) ($contact['id'] ?? 0);
            if ($id > 0) {
                return $id;
            }
        }

        return null;
    }

    public function upsertContact(ContactDTO $dto, RequestContext $context): int
    {
        $normalizedEmail = $this->normalizeEmail($dto->emails[0] ?? '');
        $existingId = $normalizedEmail !== '' ? $this->findContactIdByEmail($normalizedEmail, $context) : null;

        $payload = $this->buildContactPayload($dto);

        if ($existingId !== null) {
            $path = $this->buildContactUpdatePath($existingId);
            $response = $this->client->request('PUT', $path, $payload, [], $context);
            $contact = $response['contact'] ?? null;
            $id = is_array($contact) ? (int) ($contact['id'] ?? $existingId) : $existingId;

            $this->logger->info('redmine.contact.updated', [
                'contact_id' => $id,
                'correlation_id' => $context->correlationId,
            ]);

            return $id;
        }

        $path = $this->buildContactsUpsertPath();
        $response = $this->client->request('POST', $path, $payload, [], $context);
        $contact = $response['contact'] ?? null;
        $id = is_array($contact) ? (int) ($contact['id'] ?? 0) : 0;

        $this->logger->info('redmine.contact.created', [
            'contact_id' => $id,
            'correlation_id' => $context->correlationId,
        ]);

        return $id;
    }

    private function buildContactsSearchPath(string $email): string
    {
        if ($this->contactsPath === null) {
            throw new RedmineTransportException('Contacts API path not configured');
        }

        return $this->contactsPath . '?' . http_build_query(['search' => $email]);
    }

    private function buildContactsUpsertPath(): string
    {
        if ($this->contactsPath === null) {
            throw new RedmineTransportException('Contacts API path not configured');
        }

        return $this->contactsPath;
    }

    private function buildContactUpdatePath(int $contactId): string
    {
        if ($this->contactsPath === null) {
            throw new RedmineTransportException('Contacts API path not configured');
        }

        $path = preg_replace('#/contacts\\.json$#', '/contacts/' . $contactId . '.json', $this->contactsPath);

        return $path ?? ('/contacts/' . $contactId . '.json');
    }

    /**
     * @return array<string, mixed>
     */
    private function buildContactPayload(ContactDTO $dto): array
    {
        $emails = $this->normalizeEmails($dto->emails);
        $phones = $this->normalizePhones($dto->phones);
        $customFields = $this->normalizeCustomFields($dto->customFields);

        $payload = [
            'contact' => array_filter([
                'is_company' => $dto->isCompany,
                'first_name' => $dto->firstName,
                'last_name' => $dto->lastName,
                'company' => $dto->company,
                'emails' => $emails,
                'phones' => $phones,
                'address_attributes' => $dto->address !== null ? ['full_address' => $dto->address] : null,
                'custom_fields' => $customFields,
            ], static fn($value) => $value !== null && $value !== ''),
        ];

        return $payload;
    }

    /**
     * @return array<int, array{address: string}>
     */
    private function normalizeEmails(array $emails): array
    {
        $normalized = [];
        foreach ($emails as $email) {
            if (!is_string($email)) {
                continue;
            }

            $trimmed = trim($email);
            if ($trimmed === '') {
                continue;
            }

            $normalized[] = ['address' => strtolower($trimmed)];
        }

        return $normalized;
    }

    /**
     * @return array<int, array{number: string}>
     */
    private function normalizePhones(array $phones): array
    {
        $normalized = [];
        foreach ($phones as $phone) {
            if (!is_string($phone)) {
                continue;
            }

            $trimmed = trim($phone);
            if ($trimmed === '') {
                continue;
            }

            $normalized[] = ['number' => $trimmed];
        }

        return $normalized;
    }

    /**
     * @param array<int, array{id?: int, value?: mixed}> $customFields
     * @return array<int, array{id: int, value: mixed}>
     */
    private function normalizeCustomFields(array $customFields): array
    {
        $normalized = [];
        foreach ($customFields as $field) {
            if (!is_array($field)) {
                continue;
            }

            $id = $field['id'] ?? null;
            if (!is_int($id)) {
                continue;
            }

            $normalized[] = [
                'id' => $id,
                'value' => $field['value'] ?? null,
            ];
        }

        return $normalized;
    }

    private function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    /**
     * @param array<string, mixed> $contact
     */
    private function contactMatchesEmail(array $contact, string $normalizedEmail): bool
    {
        $email = $contact['email'] ?? null;
        if (is_string($email) && $this->normalizeEmail($email) === $normalizedEmail) {
            return true;
        }

        $emails = $contact['emails'] ?? null;
        if (!is_array($emails)) {
            return false;
        }

        foreach ($emails as $value) {
            if (is_string($value) && $this->normalizeEmail($value) === $normalizedEmail) {
                return true;
            }

            if (is_array($value)) {
                $address = $value['address'] ?? null;
                if (is_string($address) && $this->normalizeEmail($address) === $normalizedEmail) {
                    return true;
                }
            }
        }

        return false;
    }
}
