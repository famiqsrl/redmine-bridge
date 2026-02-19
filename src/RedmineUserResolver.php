<?php

declare(strict_types=1);

namespace Famiq\RedmineBridge;

use Famiq\RedmineBridge\Exceptions\RedmineValidationException;
use Famiq\RedmineBridge\Http\RedmineHttpClient;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class RedmineUserResolver
{
    public function __construct(
        private RedmineHttpClient $client,
        private RedmineConfig $config,
        private LoggerInterface $logger = new NullLogger(),
    ) {}

    /**
     * @return array{login: ?string, extraDescription: ?string}
     */
    public function resolveUserForTicket(\Famiq\RedmineBridge\RequestContext $context): array
    {
        $login = $context->idUsuario ? trim((string) $context->idUsuario) : '';
        $email = $context->emailUsuario ? trim((string) $context->emailUsuario) : '';
        $nombre = $context->nombreUsuario ? trim((string) $context->nombreUsuario) : '';
        $apellido = $context->apellidoUsuario ? trim((string) $context->apellidoUsuario) : '';

        if ($login === '') {
            // Sin login no hay switch-user
            return ['login' => null, 'extraDescription' => null];
        }

        $isInternal = false;
        if ($email !== '' && str_contains($email, '@')) {
            $domain = strtolower(substr(strrchr($email, '@') ?: '', 1));
            $allDomains = array_map('strtolower', array_merge(
                [$this->config->internalEmailDomain],
                $this->config->internalEmailDomains,
            ));
            $isInternal = in_array($domain, $allDomains, true);
        }

        // 1) Si el usuario existe en Redmine → usamos switch-user
        try {
            // OJO: en Redmine API estándar es /users.json (requiere permisos).
            // Si tu Redmine no permite listar usuarios, esto puede fallar.
            $res = $this->client->request('GET', '/users.json?name=' . rawurlencode($login) . '&limit=1', null, [], $context);
            $users = $res['users'] ?? [];
            if (is_array($users) && isset($users[0]['login']) && (string) $users[0]['login'] === $login) {
                return ['login' => $login, 'extraDescription' => null];
            }
        } catch (\Throwable $e) {
            // Si no se puede consultar usuarios, NO forzamos switch-user a ciegas
            $this->logger->warning('redmine.userResolver.user_lookup_failed', [
                'correlation_id' => $context->correlationId,
                'login' => $login,
                'error' => get_class($e),
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
            ]);
        }

        // 2) Si es interno, intentamos crear usuario (si tenés permisos)
        if ($isInternal) {
            try {
                // Si no tenés lastname/nombre, igual mandamos algo válido
                $firstName = $nombre !== '' ? $nombre : $login;
                $lastName = $apellido !== '' ? $apellido : 'PIN';

                $payload = [
                    'user' => [
                        'login' => $login,
                        'firstname' => $firstName,
                        'lastname' => $lastName,
                        'mail' => $email !== '' ? $email : ($login . '@' . $this->config->internalEmailDomain),
                        // Si tu Redmine requiere password, esto puede fallar.
                        // Si usa LDAP, puede que NO se permitan passwords acá.
                        'password' => bin2hex(random_bytes(8)),
                    ],
                ];

                $this->client->request('POST', '/users.json', $payload, [], $context);

                // Si creó OK, usamos switch-user
                return ['login' => $login, 'extraDescription' => null];
            } catch (\Throwable $e) {
                // Si no pudo crear, NO devolvemos login (evita 404 por switch-user a user inexistente)
                $this->logger->error('redmine.userResolver.user_create_failed', [
                    'correlation_id' => $context->correlationId,
                    'login' => $login,
                    'email' => $email,
                    'error' => get_class($e),
                    'message' => $e->getMessage(),
                    'code' => $e->getCode(),
                    'errors' => property_exists($e, 'errors') ? $e->errors : null,
                ]);
                return ['login' => null, 'extraDescription' => null];
            }
        }

        // 3) Externo: ticket a nombre de fallbackUserLogin (si querés forzarlo)
        $extra = null;
        if ($email !== '' || $nombre !== '' || $apellido !== '') {
            $extra = "Contacto:\n"
                . "Nombre: " . trim($nombre . ' ' . $apellido) . "\n"
                . "Email: " . ($email !== '' ? $email : '-') . "\n"
                . "Login: " . $login;
        }

        // Si querés que sea a nombre de redminecrm, devolvés el fallback login.
        // Si tu Redmine NO permite impersonation, poné null acá.
        return ['login' => $this->config->fallbackUserLogin ?: null, 'extraDescription' => $extra];
    }


    private function userExists(string $login, RequestContext $context): bool
    {
        try {
            $response = $this->client->request(
                'GET',
                '/users.json?' . http_build_query(['name' => $login, 'limit' => 1]),
                null,
                [],
                $context,
            );

            $users = $response['users'] ?? [];

            if (!is_array($users)) {
                return false;
            }

            foreach ($users as $user) {
                if (is_array($user) && ($user['login'] ?? null) === $login) {
                    return true;
                }
            }

            return false;
        } catch (RedmineValidationException) {
            return false;
        }
    }

    private function createUser(RequestContext $context): void
    {
        $login = (string) $context->idUsuario;
        $email = (string) $context->emailUsuario;
        $firstName = $context->nombreUsuario ?? $login;
        $lastName = $context->apellidoUsuario ?? $login;

        $payload = [
            'user' => [
                'login' => $login,
                'firstname' => $firstName,
                'lastname' => $lastName,
                'mail' => $email,
                'password' => bin2hex(random_bytes(16)),
            ],
        ];

        $this->logger->info('redmine.create_user', [
            'login' => $login,
            'email' => $email,
            'correlation_id' => $context->correlationId,
        ]);

        $this->client->request('POST', '/users.json', $payload, [], $context);
    }

    private function isInternalEmail(string $email): bool
    {
        $emailLower = strtolower($email);
        $allDomains = array_merge(
            [$this->config->internalEmailDomain],
            $this->config->internalEmailDomains,
        );

        foreach ($allDomains as $domain) {
            $atDomain = '@' . ltrim(strtolower($domain), '@');
            if (str_ends_with($emailLower, $atDomain)) {
                return true;
            }
        }

        return false;
    }

    private function buildExternalUserDescription(RequestContext $context): string
    {
        $parts = [];
        $parts[] = '---';
        $parts[] = 'Contacto del autor original:';

        if ($context->nombreUsuario !== null || $context->apellidoUsuario !== null) {
            $name = trim(($context->nombreUsuario ?? '') . ' ' . ($context->apellidoUsuario ?? ''));
            if ($name !== '') {
                $parts[] = 'Nombre: ' . $name;
            }
        }

        if ($context->emailUsuario !== null) {
            $parts[] = 'Email: ' . $context->emailUsuario;
        }

        if ($context->idUsuario !== null) {
            $parts[] = 'Usuario: ' . $context->idUsuario;
        }

        return implode("\n", $parts);
    }
}
