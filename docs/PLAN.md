# Plan de integración Redmine Bridge

## Inspección del repo
- Paquete Laravel `famiq/ad-user` con namespaces `Famiq\ActiveDirectoryUser`.
- Entry points actuales:
  - `FamiqADUserServiceProvider.php` registra comandos y publica `ldap.php`.
  - Comandos en `Commands/ExportConfigCommand.php` y `Commands/GetUserInfoCommand.php`.
  - Configuración actual en `ldap.php`.
- No hay controladores ni rutas HTTP en el paquete actual.

## Áreas a modificar
1. **Nuevo paquete** `packages/famiq/redmine-bridge`:
   - `composer.json` y `src/` mínimo (DTOs, servicios y cliente HTTP Redmine).
2. **Paquete existente** `famiq/ad-user`:
   - Extender `FamiqADUserServiceProvider.php` para publicar configuración de Redmine y registrar rutas si la feature flag está activa.
   - Agregar `redmine_bridge.php` como config publicable.
   - Agregar capa `RedmineBridgeFacade` + controladores + requests para endpoints nuevos.
   - Mantener comandos y API existentes intactos.
3. **Documentación** en `/docs`:
   - `README.md` (instalación y ejemplos de uso directo).
   - `INTEGRATION_API.md` (endpoints y payloads).
   - `MAPPING.md` (mapeos y custom fields).

## Decisiones clave
- DTOs y servicios directos (sin capa de commands/queries ni contratos separados).
- Idempotencia a través de store en memoria (la app puede implementar su propio store si necesita persistencia).
- Observabilidad con `LoggerInterface` (PSR-3) y `RequestContext` (correlation_id).
- Contactos/CRM con estrategia configurable (API / custom field / fallback).
- Feature flag `REDMINE_BRIDGE_ENABLED` para activar endpoints en el plugin existente.
