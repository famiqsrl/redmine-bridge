<?php

declare(strict_types=1);

namespace Famiq\RedmineBridge\Http;

use Famiq\RedmineBridge\Exceptions\RedmineAuthException;
use Famiq\RedmineBridge\Exceptions\RedmineTransportException;
use Famiq\RedmineBridge\Exceptions\RedmineValidationException;
use Famiq\RedmineBridge\RedmineConfig;
use Famiq\RedmineBridge\RequestContext;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class RedmineHttpClient
{
    private Psr17Factory $factory;

    public function __construct(
        private ClientInterface $client,
        private RedmineConfig $config,
        private LoggerInterface $logger = new NullLogger(),
    ) {
        $this->factory = new Psr17Factory();
    }

    /**
     * @param array<string, mixed>|string|null $body
     * @param array<string, string> $headers
     * @return array<string, mixed>
     */
    public function request(string $method, string $path, array|string|null $body, array $headers, ?RequestContext $context): array
    {
        $url = $this->resolveUrl($path);

        // 1) primer intento (con switch-user si aplica)
        $request = $this->createRequest($method, $url, $body, $headers, $context);

        $this->logger->info('redmine.request', [
            'method' => $method,
            'url' => $url,
            'correlation_id' => $context?->correlationId,
        ]);

        try {
            $response = $this->client->sendRequest($request);
            return $this->handleResponse($response, $context);
        } catch (RedmineAuthException $e) {
            // 2) fallback: si es 412 y veníamos con switch-user, reintento sin switch-user
            if ((int) $e->getCode() === 412 && $this->hasSwitchUser($headers, $context)) {
                $this->logger->warning('redmine.switch_user.retry_without_switch_user', [
                    'method' => $method,
                    'url' => $url,
                    'correlation_id' => $context?->correlationId,
                    'switch_user' => $this->getSwitchUserValue($headers, $context),
                ]);

                $headersNoSwitch = $this->stripSwitchUserHeader($headers);
                $contextNoSwitch = $this->cloneContextWithoutSwitchUser($context);

                $retryRequest = $this->createRequest($method, $url, $body, $headersNoSwitch, $contextNoSwitch);

                try {
                    $retryResponse = $this->client->sendRequest($retryRequest);
                    return $this->handleResponse($retryResponse, $contextNoSwitch);
                } catch (ClientExceptionInterface $exception) {
                    $this->logger->error('redmine.transport_error', [
                        'message' => $exception->getMessage(),
                        'correlation_id' => $context?->correlationId,
                    ]);
                    throw new RedmineTransportException($exception->getMessage(), (int) $exception->getCode(), $exception);
                }
            }

            throw $e;
        } catch (ClientExceptionInterface $exception) {
            $this->logger->error('redmine.transport_error', [
                'message' => $exception->getMessage(),
                'correlation_id' => $context?->correlationId,
            ]);
            throw new RedmineTransportException($exception->getMessage(), (int) $exception->getCode(), $exception);
        }
    }

    /**
     * @param array<string, mixed>|string|null $body
     * @param array<string, string> $headers
     */
    public function requestRaw(string $method, string $path, array|string|null $body, array $headers, ?RequestContext $context): string
    {
        $url = $this->resolveUrl($path);

        // 1) primer intento
        $request = $this->createRequest($method, $url, $body, $headers, $context);

        $this->logger->info('redmine.request', [
            'method' => $method,
            'url' => $url,
            'correlation_id' => $context?->correlationId,
        ]);

        try {
            $response = $this->client->sendRequest($request);
            return $this->handleRawResponse($response, $context);
        } catch (RedmineAuthException $e) {
            // 2) fallback 412
            if ((int) $e->getCode() === 412 && $this->hasSwitchUser($headers, $context)) {
                $this->logger->warning('redmine.switch_user.retry_without_switch_user', [
                    'method' => $method,
                    'url' => $url,
                    'correlation_id' => $context?->correlationId,
                    'switch_user' => $this->getSwitchUserValue($headers, $context),
                ]);

                $headersNoSwitch = $this->stripSwitchUserHeader($headers);
                $contextNoSwitch = $this->cloneContextWithoutSwitchUser($context);

                $retryRequest = $this->createRequest($method, $url, $body, $headersNoSwitch, $contextNoSwitch);

                try {
                    $retryResponse = $this->client->sendRequest($retryRequest);
                    return $this->handleRawResponse($retryResponse, $contextNoSwitch);
                } catch (ClientExceptionInterface $exception) {
                    $this->logger->error('redmine.transport_error', [
                        'message' => $exception->getMessage(),
                        'correlation_id' => $context?->correlationId,
                    ]);
                    throw new RedmineTransportException($exception->getMessage(), (int) $exception->getCode(), $exception);
                }
            }

            throw $e;
        } catch (ClientExceptionInterface $exception) {
            $this->logger->error('redmine.transport_error', [
                'message' => $exception->getMessage(),
                'correlation_id' => $context?->correlationId,
            ]);
            throw new RedmineTransportException($exception->getMessage(), (int) $exception->getCode(), $exception);
        }
    }

    /**
     * @param array<string, string> $headers
     */
    private function hasSwitchUser(array $headers, ?RequestContext $context): bool
    {
        if ($context !== null && $context->switchUser !== null && trim((string) $context->switchUser) !== '') {
            return true;
        }

        foreach ($headers as $k => $v) {
            if (strcasecmp($k, 'X-Redmine-Switch-User') === 0 && trim((string) $v) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, string> $headers
     */
    private function getSwitchUserValue(array $headers, ?RequestContext $context): ?string
    {
        if ($context !== null && $context->switchUser !== null && trim((string) $context->switchUser) !== '') {
            return (string) $context->switchUser;
        }

        foreach ($headers as $k => $v) {
            if (strcasecmp($k, 'X-Redmine-Switch-User') === 0 && trim((string) $v) !== '') {
                return (string) $v;
            }
        }

        return null;
    }

    /**
     * @param array<string, string> $headers
     * @return array<string, string>
     */
    private function stripSwitchUserHeader(array $headers): array
    {
        $out = [];
        foreach ($headers as $k => $v) {
            if (strcasecmp($k, 'X-Redmine-Switch-User') === 0) {
                continue;
            }
            $out[$k] = $v;
        }
        return $out;
    }

    private function cloneContextWithoutSwitchUser(?RequestContext $context): ?RequestContext
    {
        if ($context === null) {
            return null;
        }

        return new RequestContext($context->correlationId, null);
    }

    /**
     * @param array<string, mixed>|string|null $body
     * @param array<string, string> $headers
     */
    private function createRequest(
        string $method,
        string $url,
        array|string|null $body,
        array $headers,
        ?RequestContext $context,
    ): \Psr\Http\Message\RequestInterface {
        $request = $this->factory->createRequest($method, $url)
            ->withHeader('Authorization', $this->buildAuthHeader())
            ->withHeader('Accept', 'application/json');

        if ($context !== null) {
            $request = $request->withHeader('X-Correlation-Id', $context->correlationId);

            if ($context->switchUser !== null) {
                $request = $request->withHeader('X-Redmine-Switch-User', $context->switchUser);
            }
        }

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        if ($body !== null) {
            if (is_array($body)) {
                $stream = $this->factory->createStream(json_encode($body, JSON_THROW_ON_ERROR));
                $request = $request->withHeader('Content-Type', 'application/json');
            } else {
                $stream = $this->factory->createStream($body);
            }

            $request = $request->withBody($stream);
        }

        return $request;
    }

    private function resolveUrl(string $path): string
    {
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        return $this->buildUrl($path);
    }

    private function buildUrl(string $path): string
    {
        $baseUrl = rtrim($this->config->baseUrl, '/');

        if (!$this->config->useSsl && str_starts_with($baseUrl, 'https://')) {
            $baseUrl = 'http://' . substr($baseUrl, 8);
        }

        return $baseUrl . '/' . ltrim($path, '/');
    }

    private function buildAuthHeader(): string
    {
        $token = base64_encode($this->config->username . ':' . $this->config->password);

        return 'Basic ' . $token;
    }

    /**
     * @return array<string, mixed>
     */
    private function handleResponse(ResponseInterface $response, ?RequestContext $context): array
    {
        $status = $response->getStatusCode();
        $payload = (string) $response->getBody();

        $this->logger->info('redmine.response', [
            'status' => $status,
            'correlation_id' => $context?->correlationId,
        ]);

        if ($status === 401 || $status === 403 || $status === 412) {
            $message = $status === 412
                ? 'Redmine switch-user rejected (user not found or inactive)'
                : 'Redmine authentication failed';
            throw new RedmineAuthException($message, $status);
        }

        if ($status >= 400) {
            $decoded = $payload !== '' ? $this->decodeJsonToArray($payload) : [];

            $this->logger->error('redmine.response.error_body', [
                'status' => $status,
                'body_raw' => mb_substr($payload, 0, 2000),
                'body_decoded' => $decoded,
                'correlation_id' => $context?->correlationId,
            ]);

            throw new RedmineValidationException('Redmine request failed', $decoded, $status, $payload);
        }

        if ($payload === '') {
            return [];
        }

        return $this->decodeJsonToArray($payload);
    }

    private function handleRawResponse(ResponseInterface $response, ?RequestContext $context): string
    {
        $status = $response->getStatusCode();
        $payload = (string) $response->getBody();

        $this->logger->info('redmine.response', [
            'status' => $status,
            'correlation_id' => $context?->correlationId,
        ]);

        if ($status === 401 || $status === 403 || $status === 412) {
            $message = $status === 412
                ? 'Redmine switch-user rejected (user not found or inactive)'
                : 'Redmine authentication failed';
            throw new RedmineAuthException($message, $status);
        }

        if ($status >= 400) {
            $decoded = $payload !== '' ? $this->decodeJsonToArray($payload) : [];

            $this->logger->error('redmine.response.error_body', [
                'status' => $status,
                'body_raw' => mb_substr($payload, 0, 2000),
                'body_decoded' => $decoded,
                'correlation_id' => $context?->correlationId,
            ]);

            throw new RedmineValidationException('Redmine request failed', $decoded, $status, $payload);
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonToArray(string $payload): array
    {
        $decoded = json_decode($payload, true);

        return is_array($decoded) ? $decoded : [];
    }
}
