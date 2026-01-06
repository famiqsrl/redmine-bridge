<?php

declare(strict_types=1);

namespace Famiq\RedmineBridge;

final class RedmineConfig
{
    /**
     * @param array<string, int|string> $customFieldMap
     * @param array<int, array<int|string, mixed>> $requiredCustomFieldOptionsByTracker
     */
    public function __construct(
        public string $baseUrl,
        public string $username,
        public string $password,
        public array $customFieldMap,
        public array $requiredCustomFieldOptionsByTracker = [],
        public bool $useSsl = true,
    ) {
    }
}
