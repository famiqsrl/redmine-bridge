<?php

declare(strict_types=1);

namespace Famiq\RedmineBridge;

final class RequestContext
{
    public function __construct(
        public string $correlationId,
        public ?string $idUsuario = null,
        public ?string $emailUsuario = null,
        public ?string $nombreUsuario = null,
        public ?string $apellidoUsuario = null,
    ) {
    }

    public static function generate(): self
    {
        return new self(bin2hex(random_bytes(16)));
    }
}
