<?php

declare(strict_types=1);

namespace Famiq\RedmineBridge;

final readonly class RedmineConfig
{
    /**
     * @param array<string, int|string> $customFieldMap
     */
    public function __construct(
        public string $baseUrl,
        public string $apiKey,
        public int $projectId,
        public int $trackerId,
        public array $customFieldMap,
        public ?string $contactsApiBase,
        public ?string $contactsSearchPath,
        public ?string $contactsUpsertPath,
        public string $contactStrategy,
    ) {
    }
}
