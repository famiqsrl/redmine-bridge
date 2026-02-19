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
        $request = $this->createRequest($method, $url, $body, $headers, $context);

        $this->logger->info('redmine.request', [
            'method' => $method,
            'url' => $url,
            'correlation_id' => $context?->correlationId,
        ]);

        try {
            $response = $this->client->sendRequest($request);
        } catch (ClientExceptionInterface $exception) {
            $this->logger->error('redmine.transport_error', [
                'message' => $exception->getMessage(),
                'correlation_id' => $context?->correlationId,
            ]);
            throw new RedmineTransportException($exception->getMessage(), (int) $exception->getCode(), $exception);
        }

        return $this->handleResponse($response, $context);
    }

    /**
     * @param array<string, mixed>|string|null $body
     * @param array<string, string> $headers
     */
    public function requestRaw(string $method, string $path, array|string|null $body, array $headers, ?RequestContext $context): string
    {
        $url = $this->resolveUrl($path);
        $request = $this->createRequest($method, $url, $body, $headers, $context);

        $this->logger->info('redmine.request', [
            'method' => $method,
            'url' => $url,
            'correlation_id' => $context?->correlationId,
        ]);

        try {
            $response = $this->client->sendRequest($request);
        } catch (ClientExceptionInterface $exception) {
            $this->logger->error('redmine.transport_error', [
                'message' => $exception->getMessage(),
                'correlation_id' => $context?->correlationId,
            ]);
            throw new RedmineTransportException($exception->getMessage(), (int) $exception->getCode(), $exception);
        }

        return $this->handleRawResponse($response, $context);
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
