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

## Instrucciones de uso

1. Crear una instancia de `RedmineConfig` con los datos de tu Redmine/CRM.
2. Instanciar un cliente PSR-18 (Guzzle, Symfony HttpClient, etc.).
3. Crear `RedmineHttpClient`, `RedminePayloadMapper` y un store de idempotencia (por defecto `InMemoryIdempotencyStore`).
4. Usar `RedmineTicketService` y `RedmineClienteService` para operar.

## Ejemplo de uso directo

```php
use Famiq\RedmineBridge\Contacts\ApiContactResolver;
use Famiq\RedmineBridge\DTO\TicketDTO;
use Famiq\RedmineBridge\DTO\ClienteDTO;
use Famiq\RedmineBridge\DTO\MensajeDTO;
use Famiq\RedmineBridge\DTO\AdjuntoDTO;
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

$cliente = new ClienteDTO('empresa', 'ACME', null, null, '30-12345678-9', [], [], null, 'EXT-1', 'crm');
$clienteResult = $clienteService->upsertCliente($cliente, $context);

$mensaje = new MensajeDTO($result->issueId, 'Seguimiento interno', 'internal', null);
$ticketService->crearMensaje($mensaje, $context);

$adjunto = new AdjuntoDTO($result->issueId, 'archivo.txt', 'text/plain', 'contenido', null, null);
$ticketService->crearAdjunto($adjunto, 'adj-123', $context);
```
