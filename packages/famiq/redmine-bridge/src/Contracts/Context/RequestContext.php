<?php

declare(strict_types=1);

namespace Famiq\RedmineBridge\Contracts\Context;

final readonly class RequestContext
{
    public function __construct(
        public string $correlationId,
    ) {
    }

    public static function generate(): self
    {
        return new self(bin2hex(random_bytes(16)));
    }
}
