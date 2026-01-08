<?php

declare(strict_types=1);

namespace Famiq\RedmineBridge\DTO;

final class TicketDTO
{
    /**
     * @param array<string, mixed> $customFields
     */
    public function __construct(
        public string $subject,
        public string $description,
        public string $prioridad,
        public ?string $categoria,
        public ?string $canal,
        public ?string $externalTicketId,
        public ?string $clienteRef,
        public array $customFields,
        public ?ContactsDTO $contacts = null,
    ) {
    }
}
