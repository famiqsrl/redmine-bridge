# Redmine Bridge (mínimo)

## Instalación (Composer)

Instalar el paquete:

```json
{
  "repositories": [
    {"type": "path", "url": "packages/famiq/redmine-bridge"}
  ]
}
```

## Configuración mínima

Configurar a mano los parámetros de Redmine (base URL, API key, ids de proyecto/tracker y campos custom).

## Ejemplo de uso directo

```php
use Famiq\RedmineBridge\Contacts\ApiContactResolver;
use Famiq\RedmineBridge\DTO\TicketDTO;
use Famiq\RedmineBridge\Http\RedmineHttpClient;
use Famiq\RedmineBridge\Idempotency\InMemoryIdempotencyStore;
use Famiq\RedmineBridge\RedmineClienteService;
use Famiq\RedmineBridge\RedmineConfig;
use Famiq\RedmineBridge\RedminePayloadMapper;
use Famiq\RedmineBridge\RedmineTicketService;
use Famiq\RedmineBridge\RequestContext;
use Psr\Log\NullLogger;

$config = new RedmineConfig(
    'https://redmine.example.com',
    'api-key',
    1,
    2,
    ['external_ticket_id' => 11],
    'https://redmine.example.com',
    '/contacts/search.json',
    '/contacts.json',
    'api',
);

$http = new RedmineHttpClient($psr18Client, $config, new NullLogger());
$mapper = new RedminePayloadMapper();
$idempotency = new InMemoryIdempotencyStore();
$ticketService = new RedmineTicketService($http, $config, $mapper, $idempotency, new NullLogger());
$clienteService = new RedmineClienteService(new ApiContactResolver($http, $config, new NullLogger()), new NullLogger());

$ticket = new TicketDTO('Asunto', 'Detalle', 'media', null, 'email', 'EXT-123', 'C-1', []);
$context = RequestContext::generate();

$result = $ticketService->crearTicket($ticket, 'idempotency-123', $context);
```
