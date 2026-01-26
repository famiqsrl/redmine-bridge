<?php

declare(strict_types=1);

namespace Famiq\RedmineBridge\DTO;

final class ContactDTO
{
    /**
     * @param string[] $emails
     * @param string[] $phones
     * @param array<int, array{id: int, value: mixed}> $customFields
     */
    public function __construct(
        public bool $isCompany,
        public ?string $firstName,
        public ?string $lastName,
        public ?string $company,
        public array $emails,
        public array $phones,
        public ?string $address = null,
        public array $customFields = [],
    ) {
    }
}
