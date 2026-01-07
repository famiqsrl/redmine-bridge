<?php

declare(strict_types=1);

namespace Famiq\RedmineBridge;

final class RedmineConfig
{
    /**
     * @param array<string, int> $customFieldMap
     */
    public function __construct(
        public string $baseUrl,
        public string $username,
        public string $password,
        public array $customFieldMap,
        public bool $useSsl = true,
    ) {
    }
}
