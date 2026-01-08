<?php

declare(strict_types=1);

namespace Famiq\RedmineBridge;

use Famiq\RedmineBridge\DTO\MensajeDTO;
use Famiq\RedmineBridge\DTO\TicketDTO;

final class RedminePayloadMapper
{
    /**
     * @param array<int, mixed> $resolvedCustomFieldsById
     * @return array<string, mixed>
     */
    public function issuePayload(TicketDTO $ticket, int $projectId, int $trackerId, array $resolvedCustomFieldsById): array
    {
        $customFields = $this->buildCustomFields($resolvedCustomFieldsById);
        $contactIds = $this->normalizeContactIds($ticket);
        $contactEmails = $this->normalizeContactEmails($ticket);

        return [
            'issue' => array_filter([
                'project_id' => $projectId,
                'tracker_id' => $trackerId,
                'subject' => $ticket->subject,
                'description' => $ticket->description,
                'priority_id' => $ticket->prioridad,
                'category_id' => $ticket->categoria,
                'contact_ids' => $contactIds,
                'contact_emails' => $contactEmails,
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
     * @param array<int, mixed> $resolvedCustomFieldsById
     * @return array<int, array<string, mixed>>
     */
    private function buildCustomFields(array $resolvedCustomFieldsById): array
    {
        $fields = [];
        foreach ($resolvedCustomFieldsById as $id => $value) {
            $this->pushCustomField($fields, (int) $id, $value);
        }

        return $fields;
    }

    /**
     * @param array<int, array<string, mixed>> $fields
     */
    private function pushCustomField(array &$fields, ?int $fieldId, mixed $value): void
    {
        if ($fieldId === null || $value === null || $value === []) {
            return;
        }

        $fields[] = [
            'id' => $fieldId,
            'value' => $value,
        ];
    }

    /**
     * @return int[]
     */
    private function normalizeContactIds(TicketDTO $ticket): array
    {
        $contactIds = $ticket->contacts?->ids ?? [];

        return array_values(array_filter(
            array_map(
                static fn (int|string $id): int => (int) $id,
                array_filter($contactIds, static fn (mixed $id): bool => $id !== null && $id !== '')
            ),
            static fn (int $id): bool => $id > 0
        ));
    }

    /**
     * @return string[]
     */
    private function normalizeContactEmails(TicketDTO $ticket): array
    {
        $emails = $ticket->contacts?->emails ?? [];

        return array_values(array_filter(
            array_map('strval', $emails),
            static fn (string $email): bool => $email !== ''
        ));
    }
}
