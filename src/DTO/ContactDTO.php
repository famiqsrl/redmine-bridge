<?php

declare(strict_types=1);

namespace Famiq\RedmineBridge\DTO;

final class ContactDTO
{
    /**
     * @param array<int, array{id: int, value: mixed}> $customFields
     */
    public function __construct(
        public string $email,
        public ?string $firstName = null,
        public ?string $lastName = null,
        public ?int $id = null,
        public array $customFields = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayloadArray(): array
    {
        $trimmedEmail = trim($this->email);
        $block = [];

        if ($trimmedEmail !== '') {
            $firstName = $this->firstName;
            if ($firstName === null || trim($firstName) === '') {
                $firstName = str_contains($trimmedEmail, '@')
                    ? explode('@', $trimmedEmail)[0]
                    : 'Contacto';
            }
            $block['email'] = mb_strtolower($trimmedEmail);
            $block['first_name'] = $firstName;
        } elseif ($this->firstName !== null && trim($this->firstName) !== '') {
            $block['first_name'] = trim($this->firstName);
        }

        if ($this->lastName !== null && trim($this->lastName) !== '') {
            $block['last_name'] = trim($this->lastName);
        }

        if ($this->id !== null && $this->id > 0) {
            $block['id'] = $this->id;
        }

        if ($this->customFields !== []) {
            $block['custom_fields'] = $this->customFields;
        }

        return $block;
    }
}
