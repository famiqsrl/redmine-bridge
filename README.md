# Redmine Bridge

Paquete PHP agnóstico de framework para integrar Redmine + RedmineUP Helpdesk/CRM.
Incluye DTOs, servicios y un cliente HTTP compatible con PSR-18/PSR-7 para crear
Tickets, mensajes y adjuntos, además de resolver contactos/CRM.

## Requisitos

- PHP 8.2 o superior.
- Cliente HTTP PSR-18 (Guzzle, Symfony HttpClient, etc.).

## Instalación (Composer)

```bash
composer require famiq/redmine-bridge
```

## Uso básico

1. Crear una instancia de `RedmineConfig` con los datos de Redmine.
2. Instanciar un cliente PSR-18 y un logger PSR-3.
3. Crear `RedmineHttpClient`, `RedminePayloadMapper` y un store de idempotencia.
4. Usar `RedmineTicketService` y `RedmineClienteService`.

```php
use Famiq\RedmineBridge\Contacts\ApiContactResolver;
use Famiq\RedmineBridge\DTO\TicketDTO;
use Famiq\RedmineBridge\DTO\ClienteDTO;
use Famiq\RedmineBridge\DTO\MensajeDTO;
use Famiq\RedmineBridge\DTO\AdjuntoDTO;
use Famiq\RedmineBridge\Http\RedmineHttpClient;
use Famiq\RedmineBridge\RedmineClienteService;
use Famiq\RedmineBridge\RedmineConfig;
use Famiq\RedmineBridge\RedminePayloadMapper;
use Famiq\RedmineBridge\RedmineTicketService;
use Famiq\RedmineBridge\RequestContext;
use Psr\Log\NullLogger;

$config = new RedmineConfig(
    'https://redmine.example.com',
    'usuario',
    'contraseña',
    ['external_ticket_id' => 11],
);

$http = new RedmineHttpClient($psr18Client, $config, new NullLogger());
$mapper = new RedminePayloadMapper();
$ticketService = new RedmineTicketService($http, $config, $mapper, new NullLogger());
$clienteService = new RedmineClienteService(
    new ApiContactResolver($http, '/contacts/search.json', '/contacts.json', new NullLogger()),
    new NullLogger()
);

$ticket = new TicketDTO('Asunto', 'Detalle', 'media', null, 'email', 'EXT-123', 'C-1', 12, []);
$context = RequestContext::generate();

$result = $ticketService->crearTicket($ticket, 1, 2, $context);

$cliente = new ClienteDTO('empresa', 'ACME', null, null, '30-12345678-9', [], [], null, 'EXT-1', 'crm');
$clienteService->upsertCliente($cliente, $context);

$mensaje = new MensajeDTO($result->issueId, 'Seguimiento interno', 'internal', null);
$ticketService->crearMensaje($mensaje, $context);

$adjunto = new AdjuntoDTO($result->issueId, 'archivo.txt', 'text/plain', 'contenido', null, null);
$ticketService->crearAdjunto($adjunto, $context);
```

## Laravel y Symfony

Este paquete no depende de ningún framework y funciona en Laravel y Symfony con
cualquier cliente PSR-18.

- **Laravel**: puedes registrar el cliente PSR-18 (por ejemplo, Guzzle) en el
  contenedor e inyectarlo donde construyas `RedmineHttpClient`.
- **Symfony**: puedes usar `symfony/http-client` junto con el bridge PSR-18 o
  cualquier implementación PSR-18 compatible.

## Licencia

MIT
