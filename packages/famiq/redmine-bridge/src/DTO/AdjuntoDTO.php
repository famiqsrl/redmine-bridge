<?php

declare(strict_types=1);

namespace Famiq\RedmineBridge\DTO;

final readonly class AdjuntoDTO
{
    public function __construct(
        public int $issueId,
        public string $filename,
        public string $mime,
        public string $content,
        public ?string $sha256,
        public ?string $externalAttachmentId,
    ) {
    }
}
