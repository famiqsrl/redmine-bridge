<?php

declare(strict_types=1);

namespace Famiq\RedmineBridge;

final class RequestContext
{
    public function __construct(
        public string $correlationId,
        public ?string $userLogin = null,
    ) {
    }

    public static function generate(?string $userLogin = null): self
    {
        return new self(bin2hex(random_bytes(16)), $userLogin);
    }
}
