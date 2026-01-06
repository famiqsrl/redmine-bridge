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
        private readonly ClientInterface $client,
        private readonly RedmineConfig $config,
        private readonly LoggerInterface $logger = new NullLogger(),
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
        $url = rtrim($this->config->baseUrl, '/') . '/' . ltrim($path, '/');
        $request = $this->factory->createRequest($method, $url)
            ->withHeader('X-Redmine-API-Key', $this->config->apiKey)
            ->withHeader('Accept', 'application/json');

        if ($context !== null) {
            $request = $request->withHeader('X-Correlation-Id', $context->correlationId);
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

        if ($status === 401 || $status === 403) {
            throw new RedmineAuthException('Redmine authentication failed', $status);
        }

        if ($status >= 400) {
            $decoded = $payload !== '' ? json_decode($payload, true) : [];
            throw new RedmineValidationException('Redmine request failed', $decoded ?? [], $status);
        }

        if ($payload === '') {
            return [];
        }

        $decoded = json_decode($payload, true);

        return is_array($decoded) ? $decoded : [];
    }
}
