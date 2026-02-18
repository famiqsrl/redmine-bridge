<?php

declare(strict_types=1);

namespace Famiq\RedmineBridge\DTO;

final class AdjuntoInlineDTO
{
    public function __construct(
        public string $filename,
        public string $mime,
        public string $content,
    ) {
    }
}
