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
    public function issuePayload(TicketDTO $ticket, int $projectId, int $trackerId, array $resolvedCustomFieldsById): array
    {
        $customFields = $this->buildCustomFields($resolvedCustomFieldsById);

        return [
            'issue' => array_filter([
                'project_id' => $projectId,
                'tracker_id' => $trackerId,
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

}
