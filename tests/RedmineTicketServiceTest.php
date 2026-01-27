<?php

declare(strict_types=1);

namespace Famiq\RedmineBridge\Tests;

use Famiq\RedmineBridge\RedmineConfig;
use Famiq\RedmineBridge\RedmineCustomFieldResolver;
use Famiq\RedmineBridge\RedminePayloadMapper;
use Famiq\RedmineBridge\RedmineTicketService;
use Famiq\RedmineBridge\RequestContext;
use Famiq\RedmineBridge\Http\RedmineHttpClient;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class RedmineTicketServiceTest extends TestCase
{
    public function testListarTicketsPorEmpresaExactMatch(): void
    {
        $requests = [];
        $handler = function (RequestInterface $request) use (&$requests): ResponseInterface {
            $requests[] = $request;
            $path = $request->getUri()->getPath();
            parse_str($request->getUri()->getQuery(), $params);

            if ($path === '/contacts.json') {
                return $this->jsonResponse([
                    'contacts' => [
                        [
                            'id' => 10,
                            'company' => 'Acme, Inc.',
                            'name' => 'Acme Inc.',
                            'first_name' => 'Acme',
                            'is_company' => true,
                        ],
                    ],
                ]);
            }

            if ($path === '/issues.json') {
                $this->assertSame('10', $params['contact_id'] ?? null);
                $this->assertSame('1', $params['page'] ?? null);
                $this->assertSame('50', $params['limit'] ?? null);

                return $this->jsonResponse([
                    'issues' => [
                        ['id' => 501, 'subject' => 'Ticket A'],
                    ],
                    'total_count' => 1,
                ]);
            }

            throw new \RuntimeException('Unexpected path');
        };

        $service = $this->makeService($handler);
        $result = $service->listarTicketsPorEmpresa('Acme Inc', null, 1, 50, null, RequestContext::generate());

        $this->assertSame(1, $result->total);
        $this->assertCount(1, $result->items);
        $this->assertSame(501, $result->items[0]['id']);
        $this->assertCount(2, $requests);
    }

    public function testListarTicketsPorEmpresaContainsMatch(): void
    {
        $handler = function (RequestInterface $request): ResponseInterface {
            $path = $request->getUri()->getPath();
            parse_str($request->getUri()->getQuery(), $params);

            if ($path === '/contacts.json') {
                return $this->jsonResponse([
                    'contacts' => [
                        [
                            'id' => 12,
                            'company' => 'Acme Corporation',
                            'name' => 'Acme Corporation',
                            'first_name' => 'Acme',
                            'is_company' => true,
                        ],
                    ],
                ]);
            }

            if ($path === '/issues.json') {
                $this->assertSame('12', $params['contact_id'] ?? null);

                return $this->jsonResponse([
                    'issues' => [
                        ['id' => 601, 'subject' => 'Ticket B'],
                    ],
                    'total_count' => 1,
                ]);
            }

            throw new \RuntimeException('Unexpected path');
        };

        $service = $this->makeService($handler);
        $result = $service->listarTicketsPorEmpresa('Acme', null, 1, 25, null, RequestContext::generate());

        $this->assertSame(1, $result->total);
        $this->assertSame(601, $result->items[0]['id']);
    }

    public function testListarTicketsPorEmpresaPrefersCompanyContacts(): void
    {
        $issueContactIds = [];
        $handler = function (RequestInterface $request) use (&$issueContactIds): ResponseInterface {
            $path = $request->getUri()->getPath();
            parse_str($request->getUri()->getQuery(), $params);

            if ($path === '/contacts.json') {
                return $this->jsonResponse([
                    'contacts' => [
                        [
                            'id' => 20,
                            'company' => 'Globex',
                            'name' => 'Globex Person',
                            'first_name' => 'Globex',
                            'is_company' => false,
                        ],
                        [
                            'id' => 21,
                            'company' => 'Globex',
                            'name' => 'Globex',
                            'first_name' => 'Globex',
                            'is_company' => true,
                        ],
                    ],
                ]);
            }

            if ($path === '/issues.json') {
                $issueContactIds[] = $params['contact_id'] ?? null;

                return $this->jsonResponse([
                    'issues' => [
                        ['id' => (int) ($params['contact_id'] ?? 0) + 1000],
                    ],
                ]);
            }

            throw new \RuntimeException('Unexpected path');
        };

        $service = $this->makeService($handler);
        $result = $service->listarTicketsPorEmpresa('Globex', null, 1, 25, null, RequestContext::generate());

        $this->assertSame(['21', '20'], $issueContactIds);
        $this->assertSame(2, $result->total);
    }

    public function testListarTicketsPorEmpresaDeduplicatesIssues(): void
    {
        $handler = function (RequestInterface $request): ResponseInterface {
            $path = $request->getUri()->getPath();
            parse_str($request->getUri()->getQuery(), $params);

            if ($path === '/contacts.json') {
                return $this->jsonResponse([
                    'contacts' => [
                        [
                            'id' => 31,
                            'company' => 'Initech',
                            'is_company' => true,
                        ],
                        [
                            'id' => 32,
                            'company' => 'Initech',
                            'is_company' => true,
                        ],
                    ],
                ]);
            }

            if ($path === '/issues.json') {
                if (($params['contact_id'] ?? null) === '31') {
                    return $this->jsonResponse([
                        'issues' => [
                            ['id' => 900],
                            ['id' => 901],
                        ],
                    ]);
                }

                return $this->jsonResponse([
                    'issues' => [
                        ['id' => 901],
                        ['id' => 902],
                    ],
                ]);
            }

            throw new \RuntimeException('Unexpected path');
        };

        $service = $this->makeService($handler);
        $result = $service->listarTicketsPorEmpresa('Initech', null, 1, 25, null, RequestContext::generate());

        $this->assertSame(3, $result->total);
        $this->assertCount(3, $result->items);
    }

    public function testListarTicketsPorEmpresaFetchAllPagination(): void
    {
        $issueRequests = [];
        $handler = function (RequestInterface $request) use (&$issueRequests): ResponseInterface {
            $path = $request->getUri()->getPath();
            parse_str($request->getUri()->getQuery(), $params);

            if ($path === '/contacts.json') {
                return $this->jsonResponse([
                    'contacts' => [
                        [
                            'id' => 41,
                            'company' => 'Umbrella',
                            'is_company' => true,
                        ],
                    ],
                ]);
            }

            if ($path === '/issues.json') {
                $issueRequests[] = $params;
                $page = (int) ($params['page'] ?? 1);

                if ($page === 1) {
                    $issues = [];
                    for ($i = 1; $i <= 100; $i++) {
                        $issues[] = ['id' => $i];
                    }

                    return $this->jsonResponse([
                        'issues' => $issues,
                    ]);
                }

                if ($page === 2) {
                    return $this->jsonResponse([
                        'issues' => [
                            ['id' => 101],
                            ['id' => 102],
                        ],
                    ]);
                }

                return $this->jsonResponse(['issues' => []]);
            }

            throw new \RuntimeException('Unexpected path');
        };

        $service = $this->makeService($handler);
        $result = $service->listarTicketsPorEmpresa('Umbrella', null, null, null, null, RequestContext::generate());

        $this->assertSame(102, $result->total);
        $this->assertSame(102, $result->perPage);
        $this->assertCount(102, $result->items);
        $this->assertSame('100', $issueRequests[0]['limit'] ?? null);
        $this->assertSame('1', $issueRequests[0]['page'] ?? null);
        $this->assertSame('2', $issueRequests[1]['page'] ?? null);
    }

    public function testListarTicketsPorEmpresaWithoutContacts(): void
    {
        $requests = [];
        $handler = function (RequestInterface $request) use (&$requests): ResponseInterface {
            $requests[] = $request;
            $path = $request->getUri()->getPath();

            if ($path === '/contacts.json') {
                return $this->jsonResponse([
                    'contacts' => [],
                ]);
            }

            throw new \RuntimeException('Unexpected path');
        };

        $service = $this->makeService($handler);
        $result = $service->listarTicketsPorEmpresa('Nope', null, 1, 20, null, RequestContext::generate());

        $this->assertSame(0, $result->total);
        $this->assertSame(0, $result->perPage);
        $this->assertSame([], $result->items);
        $this->assertCount(1, $requests);
    }

    private function makeService(callable $handler): RedmineTicketService
    {
        $client = new FakePsrClient($handler);
        $config = new RedmineConfig('https://redmine.test', 'user', 'pass', ['cliente_ref' => 99]);
        $http = new RedmineHttpClient($client, $config);

        return new RedmineTicketService(
            $http,
            $config,
            new RedminePayloadMapper(),
            new RedmineCustomFieldResolver($http),
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function jsonResponse(array $payload): ResponseInterface
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode($payload));
    }
}

final class FakePsrClient implements ClientInterface
{
    /**
     * @var callable
     */
    private $handler;

    /**
     * @param callable $handler
     */
    public function __construct(callable $handler)
    {
        $this->handler = $handler;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $handler = $this->handler;

        return $handler($request);
    }
}
