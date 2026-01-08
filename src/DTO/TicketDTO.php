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
        public string $prioridad = '1',
        public ?string $categoria = null,
        public ?string $canal = null,
        public ?string $externalTicketId = null,
        public ?string $clienteRef = null,
        public array $customFields = [],
    ) {
    }
}
