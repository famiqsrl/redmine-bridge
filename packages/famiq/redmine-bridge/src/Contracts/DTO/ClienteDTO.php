<?php

declare(strict_types=1);

namespace Famiq\RedmineBridge\Contracts\DTO;

final readonly class ClienteDTO
{
    /**
     * @param string[] $emails
     * @param string[] $telefonos
     */
    public function __construct(
        public string $tipo,
        public ?string $razonSocial,
        public ?string $nombre,
        public ?string $apellido,
        public ?string $cuit,
        public array $emails,
        public array $telefonos,
        public ?string $direccion,
        public ?string $externalId,
        public string $sourceSystem,
    ) {
    }
}
