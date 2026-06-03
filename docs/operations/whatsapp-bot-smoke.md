# WhatsApp Bot · Smoke Test Checklist

Casos manuales a ejecutar tras activar los workflows en producción.

## Pre-requisitos

- Workflows activos: `Mesa de Ayuda - WhatsApp Bot` y `Mesa de Ayuda - Auto Tagging`.
- Backend deployed con commits de Fase 1 + Fase 2.
- Settings configurados:
  - `webhook_whatsapp_import_token` (token compartido con n8n credentials)
  - `webhook_tickets_tags_token`
  - `whatsapp_enabled = 1`
  - Credenciales Meta Cloud API en n8n (Bearer + Phone Number ID)
  - Credenciales Groq en n8n
- Variable n8n `MESADEAYUDA_URL` apunta al backend.

## Checklist (7 casos)

- [ ] **Caso 1 — Happy path sin archivos.** Desde un número whitelisteado, manda mensaje al bot → "Crear Ticket" → asunto → descripción → "Saltar" archivos → "Crear Ticket". Verifica:
  - Respuesta del bot incluye el `id` real del ticket.
  - Ticket aparece en `/` con `channel=whatsapp`, `whatsapp_message_id` poblado.

- [ ] **Caso 2 — Happy path con archivo.** Igual al caso 1 pero adjunta foto en el paso de archivos. Verifica:
  - Attachment guardado bajo `webroot/uploads/attachments/{id}/`.
  - Ticket muestra el adjunto en la UI.

- [ ] **Caso 3 — Cancelación.** Llega a confirmación, elige "Cancelar". Verifica:
  - Sin ticket creado.
  - Redis session borrada (`redis-cli GET mesadeayuda:session:{phone}` retorna nil).

- [ ] **Caso 4 — Idempotencia.** Fuerza reenvío del mismo mensaje por Meta (cancela y reactiva el webhook). Verifica:
  - NO se crea segundo ticket.
  - Logs n8n muestran "ya procesado".

- [ ] **Caso 5 — Lock.** Manda dos mensajes consecutivos rápidos. Verifica:
  - Segundo recibe respuesta "procesando, espera...".
  - Tras procesar el primero, el segundo se procesa (o el usuario reenvía).

- [ ] **Caso 6 — Auto Tagging.** Tras crear ticket vía bot (caso 1), verifica:
  - `Auto Tagging` workflow fue invocado (revisar execuciones en n8n UI).
  - Tags aparecen en el ticket en `/`.
  - Si Groq devuelve tag_id inválido, `skipped_unknown` se loguea sin afectar tagging.

- [ ] **Caso 7 — Error transitorio.** Apaga el backend deliberadamente (`docker compose stop php-fpm` o equivalente). Completa flujo del bot hasta confirmar. Verifica:
  - Usuario recibe mensaje "Ups, tuvimos un problema...".
  - Redis session limpia.
  - Redis lock limpio.
  - Tras restaurar backend, próximo intento del usuario funciona.

## Reporte

Pegar resultado en el ticket interno de Fase 2 con timestamp por caso.

## Notas

- Casos 4, 5, 6 (idempotencia, lock, auto tagging) requieren la cirugía del bot
  (Tasks 4-10 del plan Fase 2). Mientras el bot del workflow `YrY1cuaU5YobAUGu`
  no se haya reestructurado, estos casos no son aplicables. Ver
  `docs/superpowers/plans/2026-05-19-n8n-whatsapp-audit-fase-2.md` §Tasks 4-10
  y el reporte de implementación de Fase 2.
