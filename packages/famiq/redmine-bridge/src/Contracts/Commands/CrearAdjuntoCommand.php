<?php

declare(strict_types=1);

namespace Famiq\RedmineBridge\Contracts\Commands;

use Famiq\RedmineBridge\Contracts\Context\RequestContext;
use Famiq\RedmineBridge\Contracts\DTO\AdjuntoDTO;

final readonly class CrearAdjuntoCommand
{
    public function __construct(
        public AdjuntoDTO $adjunto,
        public string $idempotencyKey,
        public RequestContext $context,
    ) {
    }
}
