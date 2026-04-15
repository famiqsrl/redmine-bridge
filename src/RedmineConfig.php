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
        public string $fallbackUserLogin = 'redminecrm',
        public string $internalEmailDomain = 'famiq.com.ar',
        /** @var string[] Dominios internos adicionales que permiten crear usuarios en Redmine */
        public array $internalEmailDomains = ['famiq.com.uy'],
        public int $portalGroupId = 100,
        public ?string $apiKey = null,

    ) {
    }

    /**
     * Indica si el email pertenece a un dominio interno de Famiq
     * (internalEmailDomain + internalEmailDomains).
     */
    public function isInternalEmail(?string $email): bool
    {
        if ($email === null) {
            return false;
        }

        $trimmed = trim($email);
        if ($trimmed === '' || !str_contains($trimmed, '@')) {
            return false;
        }

        $parts = explode('@', $trimmed, 2);
        $domain = strtolower(trim($parts[1] ?? ''));
        if ($domain === '') {
            return false;
        }

        if ($domain === strtolower($this->internalEmailDomain)) {
            return true;
        }

        foreach ($this->internalEmailDomains as $extra) {
            if ($domain === strtolower((string) $extra)) {
                return true;
            }
        }

        return false;
    }
}
