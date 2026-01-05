# Redmine Bridge (Laravel + Symfony)

## Instalación (Composer)

En aplicaciones Laravel/Symfony:

```bash
composer require famiq/redmine-bridge
```

Para un monorepo, agregar repositorio de tipo path:

```json
{
  "repositories": [
    {"type": "path", "url": "packages/famiq/redmine-bridge"}
  ]
}
```

## Configuración (.env Laravel)

```env
REDMINE_BRIDGE_ENABLED=true
REDMINE_BASE_URL=https://redmine.example.com
REDMINE_API_KEY=xxx
REDMINE_PROJECT_ID=1
REDMINE_TRACKER_ID=2
REDMINE_CF_ORIGEN=10
REDMINE_CF_EXTERNAL_TICKET_ID=11
REDMINE_CF_CANAL=12
REDMINE_CF_CONTACT_REF=13
REDMINE_CONTACT_STRATEGY=fallback
```

Publicar config/migrations:

```bash
php artisan vendor:publish --tag=redmine-bridge-config
php artisan migrate
```

## Configuración (Symfony services.yaml)

```yaml
redmine_bridge:
  base_url: 'https://redmine.example.com'
  api_key: '%env(REDMINE_API_KEY)%'
  project_id: 1
  tracker_id: 2
  custom_fields:
    origen: 10
    external_ticket_id: 11
    canal: 12
    contact_ref: 13
  contact_strategy: 'fallback'
```

Registrar bundle:

```php
// config/bundles.php
return [
    Famiq\RedmineBridge\Infrastructure\Symfony\RedmineBridgeBundle::class => ['all' => true],
];
```

Comando de verificación:

```bash
php bin/console redmine:bridge:check
```

## Ejemplo Laravel (uso directo)

```php
use Famiq\RedmineBridge\Contracts\Commands\CrearTicketCommand;
use Famiq\RedmineBridge\Contracts\Context\RequestContext;
use Famiq\RedmineBridge\Contracts\DTO\TicketDTO;
use Famiq\RedmineBridge\Contracts\Interfaces\TicketServiceInterface;

$ticketService = app(TicketServiceInterface::class);
$command = new CrearTicketCommand(
    new TicketDTO('Asunto', 'Detalle', 'media', null, 'email', 'EXT-123', 'C-1', []),
    'idempotency-123',
    RequestContext::generate(),
);

$result = $ticketService->crearTicket($command);
```

## Ejemplo Symfony (uso directo)

```php
use Famiq\RedmineBridge\Contracts\Commands\CrearTicketCommand;
use Famiq\RedmineBridge\Contracts\Context\RequestContext;
use Famiq\RedmineBridge\Contracts\DTO\TicketDTO;
use Famiq\RedmineBridge\Contracts\Interfaces\TicketServiceInterface;

$ticketService = $container->get(TicketServiceInterface::class);
$command = new CrearTicketCommand(
    new TicketDTO('Asunto', 'Detalle', 'media', null, 'email', 'EXT-123', 'C-1', []),
    'idempotency-123',
    RequestContext::generate(),
);

$result = $ticketService->crearTicket($command);
```
