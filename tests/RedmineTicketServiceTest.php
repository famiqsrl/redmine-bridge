<?php

declare(strict_types=1);

namespace Famiq\RedmineBridge\Tests;

use Famiq\RedmineBridge\RedmineConfig;
use Famiq\RedmineBridge\RedmineCustomFieldResolver;
use Famiq\RedmineBridge\RedminePayloadMapper;
use Famiq\RedmineBridge\RedmineTicketService;
use Famiq\RedmineBridge\RequestContext;
use Famiq\RedmineBridge\Http\RedmineHttpClient;
use PHPUnit\Framework\TestCase;

final class RedmineTicketServiceTest extends TestCase
{
    public function testListarTicketsPorEmpresaReturnsEmptyWhenNoContacts(): void
    {
        $empresa = 'Acme';
        $contactsPath = $this->buildPathWithQuery('/contacts.json', [
            'search' => $empresa,
            'limit' => 200,
        ]);

        $client = new FakeRedmineHttpClient([
            $contactsPath => ['contacts' => []],
        ]);

        $service = $this->makeService($client);
        $context = new RequestContext('corr-1');

        $result = $service->listarTicketsPorEmpresa($empresa, null, null, null, null, $context);

        $this->assertSame([], $result->items);
        $this->assertSame(0, $result->total);
        $this->assertSame(1, $result->page);
        $this->assertSame(0, $result->perPage);
    }

    public function testListarTicketsPorEmpresaDedupesTicketsFromContacts(): void
    {
        $empresa = 'Acme';
        $contactsPath = $this->buildPathWithQuery('/contacts.json', [
            'search' => $empresa,
            'limit' => 200,
        ]);
        $contactOnePath = $this->buildPathWithQuery('/contacts/10.json', [
            'include' => 'tickets',
        ]);
        $contactTwoPath = $this->buildPathWithQuery('/contacts/20.json', [
            'include' => 'tickets',
        ]);

        $responses = [
            $contactsPath => [
                'contacts' => [
                    ['id' => 10, 'company' => $empresa],
                    ['id' => 20, 'company' => $empresa],
                ],
            ],
            $contactOnePath => [
                'contact' => [
                    'tickets' => [
                        ['issue_id' => 501],
                        ['id' => 502],
                    ],
                ],
            ],
            $contactTwoPath => [
                'contact' => [
                    'tickets' => [
                        ['issue_id' => 502],
                        ['issue_id' => 503],
                    ],
                ],
            ],
            '/helpdesk_tickets/501.json' => ['helpdesk_ticket' => ['id' => 501]],
            '/helpdesk_tickets/502.json' => ['helpdesk_ticket' => ['id' => 502]],
            '/helpdesk_tickets/503.json' => ['helpdesk_ticket' => ['id' => 503]],
        ];

        $client = new FakeRedmineHttpClient($responses);
        $service = $this->makeService($client);
        $context = new RequestContext('corr-2');

        $result = $service->listarTicketsPorEmpresa($empresa, null, null, null, null, $context);

        $this->assertSame(3, $result->total);
        $this->assertSame([503, 502, 501], array_map(static fn (array $item): int => (int) $item['id'], $result->items));
        $this->assertSame(3, $result->perPage);
    }

    public function testListarTicketsPorEmpresaAppliesFiltersInMemory(): void
    {
        $empresa = 'Acme';
        $contactsPath = $this->buildPathWithQuery('/contacts.json', [
            'search' => $empresa,
            'limit' => 200,
        ]);
        $contactOnePath = $this->buildPathWithQuery('/contacts/10.json', [
            'include' => 'tickets',
        ]);
        $contactTwoPath = $this->buildPathWithQuery('/contacts/20.json', [
            'include' => 'tickets',
        ]);

        $responses = [
            $contactsPath => [
                'contacts' => [
                    ['id' => 10, 'company' => $empresa],
                    ['id' => 20, 'company' => $empresa],
                ],
            ],
            $contactOnePath => [
                'contact' => [
                    'tickets' => [
                        ['issue_id' => 501],
                        ['id' => 502],
                    ],
                ],
            ],
            $contactTwoPath => [
                'contact' => [
                    'tickets' => [
                        ['issue_id' => 502],
                        ['issue_id' => 503],
                    ],
                ],
            ],
            '/helpdesk_tickets/501.json' => [
                'helpdesk_ticket' => [
                    'id' => 501,
                    'status' => ['id' => 1],
                    'project' => ['id' => 100],
                    'tracker' => ['id' => 5],
                    'custom_fields' => [
                        ['id' => 99, 'value' => 'REF-1'],
                    ],
                ],
            ],
            '/helpdesk_tickets/502.json' => [
                'helpdesk_ticket' => [
                    'id' => 502,
                    'status' => ['id' => 2],
                    'project' => ['id' => 100],
                    'tracker' => ['id' => 5],
                    'custom_fields' => [
                        ['id' => 99, 'value' => 'REF-1'],
                    ],
                ],
            ],
            '/helpdesk_tickets/503.json' => [
                'helpdesk_ticket' => 'invalid',
            ],
            '/issues/503.json' => [
                'issue' => [
                    'id' => 503,
                    'status' => ['id' => 1],
                    'project' => ['id' => 200],
                    'tracker' => ['id' => 5],
                    'custom_fields' => [
                        ['id' => 99, 'value' => 'REF-2'],
                    ],
                ],
            ],
        ];

        $client = new FakeRedmineHttpClient($responses);
        $service = $this->makeService($client);
        $context = new RequestContext('corr-3');
        $context->projectId = 100;
        $context->trackerId = 5;

        $result = $service->listarTicketsPorEmpresa($empresa, '1', null, null, 'REF-1', $context);

        $this->assertSame(1, $result->total);
        $this->assertSame([501], array_map(static fn (array $item): int => (int) $item['id'], $result->items));
    }

    public function testListarTicketsPorEmpresaPagination(): void
    {
        $empresa = 'Acme';
        $contactsPath = $this->buildPathWithQuery('/contacts.json', [
            'search' => $empresa,
            'limit' => 200,
        ]);
        $contactPath = $this->buildPathWithQuery('/contacts/10.json', [
            'include' => 'tickets',
        ]);

        $responses = [
            $contactsPath => [
                'contacts' => [
                    ['id' => 10, 'company' => $empresa],
                ],
            ],
            $contactPath => [
                'contact' => [
                    'tickets' => [
                        ['issue_id' => 501],
                        ['issue_id' => 502],
                        ['issue_id' => 503],
                    ],
                ],
            ],
            '/helpdesk_tickets/501.json' => ['helpdesk_ticket' => ['id' => 501]],
            '/helpdesk_tickets/502.json' => ['helpdesk_ticket' => ['id' => 502]],
            '/helpdesk_tickets/503.json' => ['helpdesk_ticket' => ['id' => 503]],
        ];

        $client = new FakeRedmineHttpClient($responses);
        $service = $this->makeService($client);
        $context = new RequestContext('corr-4');

        $result = $service->listarTicketsPorEmpresa($empresa, null, 2, 1, null, $context);

        $this->assertSame(3, $result->total);
        $this->assertSame(1, $result->perPage);
        $this->assertSame([502], array_map(static fn (array $item): int => (int) $item['id'], $result->items));
    }

    private function buildPathWithQuery(string $path, array $params): string
    {
        if ($params === []) {
            return $path;
        }

        return $path . '?' . http_build_query($params);
    }

    private function makeService(FakeRedmineHttpClient $client): RedmineTicketService
    {
        $config = new RedmineConfig('https://example.test', 'user', 'pass', [
            'cliente_ref' => 99,
        ]);
        $mapper = new RedminePayloadMapper();
        $resolver = new RedmineCustomFieldResolver($client);

        return new RedmineTicketService($client, $config, $mapper, $resolver);
    }
}

final class FakeRedmineHttpClient extends RedmineHttpClient
{
    /**
     * @param array<string, array<string, mixed>> $responses
     */
    public function __construct(private array $responses)
    {
    }

    /**
     * @param array<string, mixed>|string|null $body
     * @param array<string, string> $headers
     * @return array<string, mixed>
     */
    public function request(string $method, string $path, array|string|null $body, array $headers, ?RequestContext $context): array
    {
        return $this->responses[$path] ?? [];
    }
}
