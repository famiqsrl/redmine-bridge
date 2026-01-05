# Mapeos y custom fields

## Custom fields configurables

| Origen | Config | Descripción |
| --- | --- | --- |
| origen | `REDMINE_CF_ORIGEN` | Origen del ticket (CRM, portal, etc.). |
| external_ticket_id | `REDMINE_CF_EXTERNAL_TICKET_ID` | ID externo del ticket en el sistema origen. |
| canal | `REDMINE_CF_CANAL` | Canal de entrada (email, teléfono, web). |
| contact_ref | `REDMINE_CF_CONTACT_REF` | Referencia de cliente (fallback si no hay API de contactos). |

## Prioridad

| prioridad | priority_id Redmine |
| --- | --- |
| baja | 3 |
| media | 4 |
| alta | 5 |

## Estrategia de contactos

- `api`: requiere configurar `REDMINE_CONTACTS_SEARCH_PATH` y `REDMINE_CONTACTS_UPSERT_PATH`.
- `custom_field`: no crea clientes, solo devuelve `unchanged` y usa `contact_ref` como fallback.
- `fallback`: no interactúa con contactos; TODO para integraciones futuras.
