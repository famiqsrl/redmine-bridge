<?php

declare(strict_types=1);

namespace Famiq\RedmineBridge;

use Famiq\RedmineBridge\DTO\MensajeDTO;
use Famiq\RedmineBridge\DTO\TicketDTO;

final class RedminePayloadMapper
{
    /**
     * @return array<string, mixed>
     */
    public function issuePayload(
        TicketDTO $ticket,
        int $projectId,
        array $customFieldMap,
        array $requiredCustomFieldOptionsByTracker,
    ): array
    {
        $customFields = $this->buildCustomFields($ticket, $customFieldMap, $requiredCustomFieldOptionsByTracker);

        return [
            'issue' => array_filter([
                'project_id' => $projectId,
                'tracker_id' => $ticket->trackerId,
                'subject' => $ticket->subject,
                'description' => $ticket->description,
                'priority_id' => $ticket->prioridad,
                'category_id' => $ticket->categoria,
                'custom_fields' => $customFields,
            ], static fn ($value) => $value !== null && $value !== []),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function messagePayload(MensajeDTO $mensaje): array
    {
        return [
            'issue' => [
                'notes' => $mensaje->body,
                'private_notes' => $mensaje->visibility === 'internal',
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    /**
     * @param array<string, int|string> $customFieldMap
     * @return array<int, array<string, mixed>>
     */
    private function buildCustomFields(
        TicketDTO $ticket,
        array $customFieldMap,
        array $requiredCustomFieldOptionsByTracker,
    ): array
    {
        $fields = [];
        $map = $customFieldMap;
        $ticketCustomFields = $this->applyRequiredCustomFields($ticket, $map, $requiredCustomFieldOptionsByTracker);

        $this->pushCustomField($fields, $map['origen'] ?? null, $ticketCustomFields['origen'] ?? null);
        $this->pushCustomField($fields, $map['external_ticket_id'] ?? null, $ticket->externalTicketId);
        $this->pushCustomField($fields, $map['canal'] ?? null, $ticket->canal);
        $this->pushCustomField($fields, $map['contact_ref'] ?? null, $ticket->clienteRef);

        $reservedKeys = ['origen', 'external_ticket_id', 'canal', 'contact_ref'];
        foreach ($ticketCustomFields as $key => $value) {
            if (is_string($key) && in_array($key, $reservedKeys, true)) {
                continue;
            }

            if (array_key_exists($key, $map)) {
                $this->pushCustomField($fields, $map[$key], $value);
                continue;
            }

            if (is_int($key)) {
                $this->pushCustomField($fields, $key, $value);
            }
        }

        return $fields;
    }

    /**
     * @param array<int, array<string, mixed>> $fields
     */
    private function pushCustomField(array &$fields, int|string|null $fieldId, mixed $value): void
    {
        if ($fieldId === null || $value === null) {
            return;
        }

        $fields[] = [
            'id' => $fieldId,
            'value' => $value,
        ];
    }

    /**
     * @param array<string, mixed> $ticketCustomFields
     * @param array<string, int|string> $customFieldMap
     */
    private function hasCustomFieldValue(array $ticketCustomFields, array $customFieldMap, int|string $fieldId): bool
    {
        if (array_key_exists($fieldId, $ticketCustomFields) && $ticketCustomFields[$fieldId] !== null) {
            return true;
        }

        foreach ($customFieldMap as $key => $mappedId) {
            if ($mappedId === $fieldId && array_key_exists($key, $ticketCustomFields) && $ticketCustomFields[$key] !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, int|string> $customFieldMap
     * @param array<int, array<int|string, mixed>> $requiredCustomFieldOptionsByTracker
     * @return array<string, mixed>
     */
    private function applyRequiredCustomFields(
        TicketDTO $ticket,
        array $customFieldMap,
        array $requiredCustomFieldOptionsByTracker,
    ): array {
        $ticketCustomFields = $ticket->customFields;
        $requiredFields = $requiredCustomFieldOptionsByTracker[$ticket->trackerId] ?? [];

        foreach ($requiredFields as $fieldId => $options) {
            if ($this->hasCustomFieldValue($ticketCustomFields, $customFieldMap, $fieldId)) {
                continue;
            }

            $value = $this->resolveDefaultValue($options);
            if ($value === null) {
                continue;
            }

            $ticketCustomFields[$fieldId] = $value;
        }

        return $ticketCustomFields;
    }

    private function resolveDefaultValue(mixed $options): mixed
    {
        if (is_array($options)) {
            return $options === [] ? null : reset($options);
        }

        return $options;
    }
}
