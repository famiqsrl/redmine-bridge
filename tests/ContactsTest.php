<?php

declare(strict_types=1);

namespace Famiq\RedmineBridge\Tests;

use Famiq\RedmineBridge\DTO\ContactDTO;
use Famiq\RedmineBridge\DTO\TicketDTO;
use Famiq\RedmineBridge\RedmineBridge;
use Famiq\RedmineBridge\RedmineConfig;
use Famiq\RedmineBridge\RedmineContactService;
use Famiq\RedmineBridge\Http\RedmineHttpClient;
use Famiq\RedmineBridge\RequestContext;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class ContactsTest extends TestCase
{
    /**
     * @dataProvider contactEmailResponseProvider
     * @param array<string, mixed> $responsePayload
     */
    public function testFindContactIdByEmailMatchesShapes(array $responsePayload): void
    {
        $client = new FakeClient([new Response(200, [], json_encode($responsePayload, JSON_THROW_ON_ERROR))]);
        $config = new RedmineConfig('https://redmine.test', 'user', 'pass', []);
        $http = new RedmineHttpClient($client, $config);
        $service = new RedmineContactService($http, '/contacts.json');

        $context = RequestContext::generate();
        $contactId = $service->findContactIdByEmail('  TEST@Example.com ', $context);

        self::assertSame(42, $contactId);
        self::assertSame('GET', $client->requests[0]->getMethod());
        self::assertSame('/contacts.json', $client->getRequestPath(0));
        self::assertSame('search=test%40example.com', $client->getRequestQuery(0));
    }

    public function testUpsertContactBuildsPayload(): void
    {
        $responses = [
            new Response(200, [], json_encode(['contacts' => []], JSON_THROW_ON_ERROR)),
            new Response(200, [], json_encode(['contact' => ['id' => 99]], JSON_THROW_ON_ERROR)),
        ];
        $client = new FakeClient($responses);
        $config = new RedmineConfig('https://redmine.test', 'user', 'pass', []);
        $http = new RedmineHttpClient($client, $config);
        $service = new RedmineContactService($http, '/contacts.json');

        $context = RequestContext::generate();
        $dto = new ContactDTO(
            false,
            'Ada',
            'Lovelace',
            null,
            ['Ada@Example.com', ''],
            ['1234', ''],
            'Main St',
            [
                ['id' => 1, 'value' => 'vip'],
            ],
        );

        $contactId = $service->upsertContact($dto, $context);

        self::assertSame(99, $contactId);
        self::assertSame('POST', $client->requests[1]->getMethod());
        self::assertSame('/contacts.json', $client->getRequestPath(1));

        $payload = $client->getJsonBody(1);
        self::assertSame(
            [
                'contact' => [
                    'is_company' => false,
                    'first_name' => 'Ada',
                    'last_name' => 'Lovelace',
                    'emails' => [
                        ['address' => 'ada@example.com'],
                    ],
                    'phones' => [
                        ['number' => '1234'],
                    ],
                    'address_attributes' => ['full_address' => 'Main St'],
                    'custom_fields' => [
                        ['id' => 1, 'value' => 'vip'],
                    ],
                ],
            ],
            $payload,
        );
    }

    public function testHelpdeskTicketUsesContactIdEndpoint(): void
    {
        $responses = [
            new Response(200, [], json_encode(['contacts' => []], JSON_THROW_ON_ERROR)),
            new Response(200, [], json_encode(['contact' => ['id' => 77]], JSON_THROW_ON_ERROR)),
            new Response(200, [], json_encode(['custom_fields' => []], JSON_THROW_ON_ERROR)),
            new Response(200, [], json_encode(['helpdesk_ticket' => ['issue_id' => 555]], JSON_THROW_ON_ERROR)),
        ];

        $client = new FakeClient($responses);
        $config = new RedmineConfig('https://redmine.test', 'user', 'pass', []);
        $bridge = new RedmineBridge($config, $client);

        $context = RequestContext::generate();
        $ticket = new TicketDTO('Subject', 'Description');

        $result = $bridge->crearHelpdeskTicketUsandoContacto(
            $ticket,
            'Customer@Example.com',
            1,
            2,
            $context,
        );

        self::assertSame(['issueId' => 555], $result);
        self::assertSame('/contacts.json', $client->getRequestPath(0));
        self::assertSame('/contacts.json', $client->getRequestPath(1));
        self::assertSame('/helpdesk_tickets.json', $client->getRequestPath(3));

        $payload = $client->getJsonBody(3);
        self::assertSame(77, $payload['helpdesk_ticket']['contact_id'] ?? null);
        self::assertArrayNotHasKey('contact', $payload['helpdesk_ticket'] ?? []);
    }

    /**
     * @return array<string, array<int, array<string, mixed>>>
     */
    public static function contactEmailResponseProvider(): array
    {
        return [
            'email string' => [
                [
                    'contacts' => [
                        ['id' => 42, 'email' => 'test@example.com'],
                    ],
                ],
            ],
            'emails array strings' => [
                [
                    'contacts' => [
                        ['id' => 42, 'emails' => ['test@example.com']],
                    ],
                ],
            ],
            'emails array objects' => [
                [
                    'contacts' => [
                        ['id' => 42, 'emails' => [['address' => 'test@example.com']]],
                    ],
                ],
            ],
        ];
    }
}

final class FakeClient implements ClientInterface
{
    /** @var RequestInterface[] */
    public array $requests = [];

    /** @var ResponseInterface[] */
    private array $responses;

    /**
     * @param ResponseInterface[] $responses
     */
    public function __construct(array $responses)
    {
        $this->responses = $responses;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;

        if ($this->responses === []) {
            return new Response(200, [], json_encode([], JSON_THROW_ON_ERROR));
        }

        return array_shift($this->responses);
    }

    public function getRequestPath(int $index): string
    {
        return $this->requests[$index]->getUri()->getPath();
    }

    public function getRequestQuery(int $index): string
    {
        return $this->requests[$index]->getUri()->getQuery();
    }

    /**
     * @return array<string, mixed>
     */
    public function getJsonBody(int $index): array
    {
        $body = (string) $this->requests[$index]->getBody();
        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : [];
    }
}
