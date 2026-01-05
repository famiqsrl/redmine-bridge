<?php

declare(strict_types=1);

namespace Famiq\RedmineBridge\Contracts\Commands;

use Famiq\RedmineBridge\Contracts\Context\RequestContext;
use Famiq\RedmineBridge\Contracts\DTO\MensajeDTO;

final readonly class CrearMensajeCommand
{
    public function __construct(
        public MensajeDTO $mensaje,
        public RequestContext $context,
    ) {
    }
}
