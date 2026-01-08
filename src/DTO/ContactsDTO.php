<?php

declare(strict_types=1);

namespace Famiq\RedmineBridge\DTO;

final class ContactsDTO
{
    /**
     * @param int[] $ids
     * @param string[] $emails
     */
    public function __construct(
        public array $ids = [],
        public array $emails = [],
    ) {
    }
}
