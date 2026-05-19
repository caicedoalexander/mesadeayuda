# Smoke tests para webhooks

Estos scripts NO corren en `composer test` (la suite es pure-unit). Se ejecutan
manualmente contra el dev server tras `bin/cake server` y antes de mergear cambios
en el endpoint correspondiente.

## Prerrequisitos

1. Dev server corriendo: `bin/cake server` (puerto 8765)
2. Los tokens correspondientes seteados en `system_settings`:
   - `webhook_whatsapp_import_token`
   - `webhook_tickets_tags_token`
3. WhatsApp habilitado: `whatsapp_enabled = '1'`

## Uso

```bash
WHATSAPP_TOKEN=<your-token> ./tests/smoke/whatsapp_import.sh
TAGS_TOKEN=<your-token> TICKET_ID=42 ./tests/smoke/tickets_tags.sh
```

Verificar manualmente:
- HTTP 200 con `created:true` la primera vez.
- HTTP 200 con `created:false` al repetir (idempotencia).
- HTTP 401 sin header.
- HTTP 400 con payload inválido.
