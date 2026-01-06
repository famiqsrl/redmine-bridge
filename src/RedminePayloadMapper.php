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
    public function issuePayload(TicketDTO $ticket, int $projectId, int $trackerId, array $customFieldMap): array
    {
        $customFields = $this->buildCustomFields($ticket, $customFieldMap);

        return [
            'issue' => array_filter([
                'project_id' => $projectId,
                'tracker_id' => $trackerId,
                'subject' => $ticket->subject,
                'description' => $ticket->description,
                'priority_id' => $this->mapPriority($ticket->prioridad),
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
    private function buildCustomFields(TicketDTO $ticket, array $customFieldMap): array
    {
        $fields = [];
        $map = $customFieldMap;

        $this->pushCustomField($fields, $map['origen'] ?? null, $ticket->customFields['origen'] ?? null);
        $this->pushCustomField($fields, $map['external_ticket_id'] ?? null, $ticket->externalTicketId);
        $this->pushCustomField($fields, $map['canal'] ?? null, $ticket->canal);
        $this->pushCustomField($fields, $map['contact_ref'] ?? null, $ticket->clienteRef);

        foreach ($ticket->customFields as $key => $value) {
            if (isset($map[$key])) {
                $this->pushCustomField($fields, $map[$key], $value);
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

    private function mapPriority(string $prioridad): int
    {
        return match ($prioridad) {
            'alta' => 5,
            'media' => 4,
            'baja' => 3,
            default => 4,
        };
    }
}
