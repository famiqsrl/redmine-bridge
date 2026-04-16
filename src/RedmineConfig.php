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
     * Determina si un email pertenece a un dominio interno de Famiq.
     */
    public function isInternalEmail(?string $email): bool
    {
        if ($email === null) {
            return false;
        }

        $email = trim($email);
        if ($email === '' || !str_contains($email, '@')) {
            return false;
        }

        $domain = strtolower(substr(strrchr($email, '@') ?: '', 1));
        if ($domain === '') {
            return false;
        }

        $allDomains = array_map('strtolower', array_merge(
            [$this->internalEmailDomain],
            $this->internalEmailDomains,
        ));

        return in_array($domain, $allDomains, true);
    }
}
