# Redmine Bridge

Paquete PHP agnóstico de framework para integrar Redmine + RedmineUP Helpdesk/CRM.
Incluye DTOs, servicios y un cliente HTTP compatible con PSR-18/PSR-7 para crear
Tickets, mensajes y adjuntos, además de resolver contactos/CRM.

## Requisitos

- PHP 8.1 o superior.
- Extensión `ext-json` habilitada.
- Cliente HTTP PSR-18 (Guzzle, Symfony HttpClient, etc.).

## Instalación (Composer)

```bash
composer require famiq/redmine-bridge
```

## Uso recomendado (fachada)

La fachada `RedmineBridge` construye internamente los servicios necesarios y
genera automáticamente el `RequestContext` cuando no se lo pasas explícitamente.

```php
use Famiq\RedmineBridge\RedmineBridge;
use Famiq\RedmineBridge\RedmineConfig;
use Famiq\RedmineBridge\DTO\TicketDTO;
use Famiq\RedmineBridge\DTO\ContactsDTO;
use Famiq\RedmineBridge\DTO\ClienteDTO;
use Famiq\RedmineBridge\DTO\MensajeDTO;
use Famiq\RedmineBridge\DTO\AdjuntoDTO;
use Psr\Log\NullLogger;

$config = new RedmineConfig(
    'https://redmine.example.com',
    'usuario',
    'contraseña',
    ['external_ticket_id' => 11, 'cliente_ref' => 12],
);

$bridge = new RedmineBridge(
    $config,
    $psr18Client,
    new NullLogger(),
    '/contacts/search.json',
    '/contacts.json',
);

$contacts = new ContactsDTO([123], ['cliente@example.com']);
$ticket = new TicketDTO('Asunto', 'Detalle', 'media', null, 'email', 'EXT-123', 'C-1', [], $contacts);
$result = $bridge->crearTicket($ticket, 1, 2);

$cliente = new ClienteDTO('empresa', 'ACME', null, null, '30-12345678-9', [], [], null, 'EXT-1', 'crm');
$bridge->upsertCliente($cliente);

$mensaje = new MensajeDTO($result->issueId, 'Seguimiento interno', 'internal', null);
$bridge->crearMensaje($mensaje);

$adjunto = new AdjuntoDTO($result->issueId, 'archivo.txt', 'text/plain', 'contenido', null, null);
$bridge->crearAdjunto($adjunto);
```

## Uso con servicios

Si necesitas más control, puedes construir los servicios manualmente.

```php
use Famiq\RedmineBridge\DTO\TicketDTO;
use Famiq\RedmineBridge\DTO\ContactsDTO;
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
    ['external_ticket_id' => 11, 'cliente_ref' => 12],
);

$http = new RedmineHttpClient($psr18Client, $config, new NullLogger());
$mapper = new RedminePayloadMapper();
$ticketService = new RedmineTicketService($http, $config, $mapper, new NullLogger());
$clienteService = new RedmineClienteService($http, '/contacts/search.json', '/contacts.json', new NullLogger());

$context = RequestContext::generate();

$contacts = new ContactsDTO([123], ['cliente@example.com']);
$ticket = new TicketDTO('Asunto', 'Detalle', 'media', null, 'email', 'EXT-123', 'C-1', [], $contacts);
$result = $ticketService->crearTicket($ticket, 1, 2, $context);

$cliente = new ClienteDTO('empresa', 'ACME', null, null, '30-12345678-9', [], [], null, 'EXT-1', 'crm');
$clienteService->upsertCliente($cliente, $context);

$mensaje = new MensajeDTO($result->issueId, 'Seguimiento interno', 'internal', null);
$ticketService->crearMensaje($mensaje, $context);

$adjunto = new AdjuntoDTO($result->issueId, 'archivo.txt', 'text/plain', 'contenido', null, null);
$ticketService->crearAdjunto($adjunto, $context);
```

### Consultar tickets

```php
use Famiq\RedmineBridge\RequestContext;

$context = RequestContext::generate();

// Listar tickets con filtros y selección de campos
$filters = [
    'status_id' => 'open',
    'project_id' => 1,
];
$select = ['id', 'subject', 'status', 'priority'];

$result = $ticketService->consultarTickets($filters, $select, 1, 20, $context);

// Obtener un ticket individual
$ticket = $ticketService->obtenerTicket(123, ['id', 'subject', 'custom_fields'], $context);
```

## Calidad (PHPStan)

El proyecto incluye configuración de PHPStan al nivel máximo para asegurar un
análisis estricto del código. Para ejecutarlo:

```bash
composer install
vendor/bin/phpstan analyse
```

## Laravel y Symfony

Este paquete no depende de ningún framework y funciona en Laravel y Symfony con
cualquier cliente PSR-18.

- **Laravel**: puedes registrar el cliente PSR-18 (por ejemplo, Guzzle) en el
  contenedor e inyectarlo donde construyas `RedmineHttpClient` o `RedmineBridge`.
- **Symfony**: puedes usar `symfony/http-client` junto con el bridge PSR-18 o
  cualquier implementación PSR-18 compatible.

## Licencia

MIT
