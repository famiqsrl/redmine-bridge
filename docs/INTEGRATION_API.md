# API de Integración Redmine (plugin existente)

Base path: `/api/redmine`

> Todos los endpoints nuevos respetan la feature flag `REDMINE_BRIDGE_ENABLED`.

## Buscar cliente
`POST /api/redmine/clientes/buscar`

```json
{
  "query": "ACME",
  "external_id": "C-123"
}
```

## Upsert cliente
`POST /api/redmine/clientes`

```json
{
  "tipo": "empresa",
  "razon_social": "ACME",
  "nombre": null,
  "apellido": null,
  "cuit": "30-00000000-0",
  "emails": ["contacto@acme.com"],
  "telefonos": ["+54 11 5555-5555"],
  "direccion": "Calle Falsa 123",
  "external_id": "C-123",
  "source_system": "crm"
}
```

## Crear ticket
`POST /api/redmine/tickets`

```json
{
  "subject": "Problema con acceso",
  "description": "Detalle del problema",
  "prioridad": "media",
  "categoria": null,
  "canal": "email",
  "external_ticket_id": "EXT-1",
  "cliente_ref": "C-123",
  "custom_fields": {
    "origen": "crm"
  },
  "idempotency_key": "ticket-EXT-1"
}
```

## Listar tickets
`GET /api/redmine/tickets?status=open&page=1&per_page=25`

## Crear mensaje
`POST /api/redmine/tickets/{id}/mensajes`

```json
{
  "body": "Comentario interno",
  "visibility": "internal"
}
```

## Crear adjunto
`POST /api/redmine/tickets/{id}/adjuntos`

```json
{
  "filename": "captura.png",
  "mime": "image/png",
  "content": "<base64-o-path>",
  "sha256": "...",
  "external_attachment_id": "ATT-1",
  "idempotency_key": "ticket-EXT-1:ATT-1"
}
```
