<?php

declare(strict_types=1);

namespace Famiq\RedmineBridge\Contracts\Results;

final readonly class UpsertClienteResult
{
    public function __construct(
        public string $status,
        public ?string $contactId,
        public ?string $externalId,
    ) {
    }
}
