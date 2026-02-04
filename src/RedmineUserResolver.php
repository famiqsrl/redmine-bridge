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
    ) {
    }

    /**
     * Resolves a user for ticket creation. Returns the login to use for
     * X-Redmine-Switch-User and any extra description text for external users.
     *
     * @return array{login: ?string, extraDescription: ?string}
     */
    public function resolveUserForTicket(RequestContext $context): array
    {
        if (empty($context->idUsuario)) {
            return ['login' => null, 'extraDescription' => null];
        }

        if ($this->userExists($context->idUsuario, $context)) {
            return ['login' => $context->idUsuario, 'extraDescription' => null];
        }

        $email = $context->emailUsuario;

        if ($email !== null && $this->isInternalEmail($email)) {
            $this->createUser($context);

            return ['login' => $context->idUsuario, 'extraDescription' => null];
        }

        $this->logger->info('redmine.user_fallback', [
            'original_user' => $context->idUsuario,
            'fallback_user' => $this->config->fallbackUserLogin,
            'correlation_id' => $context->correlationId,
        ]);

        $extraDescription = $this->buildExternalUserDescription($context);

        return ['login' => $this->config->fallbackUserLogin, 'extraDescription' => $extraDescription];
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
        $domain = $this->config->internalEmailDomain;
        $atDomain = '@' . ltrim($domain, '@');

        return str_ends_with(strtolower($email), strtolower($atDomain));
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
