# n8n — Workflow para disparar el webhook de Gmail

Workflow externo a este repositorio. Reemplaza al servicio `worker` (que corría
`bin/cake gmail_worker` en bucle) por una invocación HTTP periódica al endpoint
`POST /webhooks/gmail/import`.

> **Ver también:** `gmail-oauth-setup.md` cubre los pre-requisitos de OAuth en
> Google Cloud Console (estado de publicación del proyecto, scope único,
> redirect URI) y la vigilancia de la dependencia `google/apiclient`.

## Pasos en n8n

1. **Crear workflow** llamado `Mesa de Ayuda — Gmail Import Trigger`.

2. **Schedule Trigger**
   - Modo: cada 5 minutos (configurable; el endpoint tiene rate limit propio de
     60s, así que cualquier intervalo ≥ 1 min funciona).

3. **HTTP Request**
   - Method: `POST`
   - URL: `${FULL_BASE_URL}/webhooks/gmail/import`
     - Ejemplo: `https://mesa.copcsa.local/webhooks/gmail/import`
   - Authentication: `Generic Credential Type` → `Header Auth`
     - Credencial: ver punto 6.
   - Headers (manuales, sin auth):
     - `Content-Type: application/json`
   - Body (JSON):
     ```json
     { "max": 50, "query": "is:unread" }
     ```
   - Timeout: `300000` ms (5 minutos — debe ser ≥ `nginx fastcgi_read_timeout`).
   - Retry on fail: `0`. El servidor ya maneja deduplicación (lock + rate limit
     + `gmail_message_id` único), reintentar desde n8n solo amplifica ruido.
   - Continue on fail: `ON` (o equivalente: marcar `Never Error` en el nodo).
     Permite que la rama de error reaccione a 4xx/5xx sin abortar el workflow.

4. **IF**
   - Condición de éxito (rama TRUE):
     ```javascript
     {{ $json.ok === true || $json.error === 'too_soon' || $json.error === 'already_running' }}
     ```
     - `too_soon` (429) y `already_running` (409) son condiciones esperadas en
       picos de carga; no requieren alerta.
   - Rama TRUE → `NoOp` (silencioso).
   - Rama FALSE → notificación.

5. **Notificación de error** (Slack, email, lo que aplique):
   - Mensaje:
     ```
     Gmail webhook import falló: {{ $json.error }}
     Detalles: {{ JSON.stringify($json) }}
     ```
   - Errores que requieren intervención humana:
     - `invalid_token` (401): token regenerado pero no actualizado en n8n.
     - `not_configured` (503): OAuth de Gmail caducado o nunca autorizado.
     - `import_failed` (500): excepción no controlada en el servidor — revisar
       logs de la app.

6. **Credencial Header Auth**
   - En n8n: `Credentials → New → Header Auth`.
     - **Name:** `X-Webhook-Token`
     - **Value:** plaintext del token.
     - Guardar la credencial con un nombre identificable (p. ej. `Mesa de Ayuda
       - Gmail Webhook Token`) y asignarla al nodo `HTTP Request`.
   - **Nunca hardcoded** en el nodo (ni en headers, ni como expresión inline).
     n8n cifra el valor en su almacenamiento de credenciales.
   - Para obtener el plaintext del token: UI `/admin/settings` → sección
     "Webhooks — Gmail Import" → botón "Mostrar / ocultar".
   - Si se regenera el token desde la UI, editar la credencial Header Auth en
     n8n con el nuevo valor. **No requiere reiniciar el contenedor**: n8n
     re-lee la credencial en cada ejecución.

7. **Activar el workflow.**

## Verificación post-deploy

- Después de ~5 min: en `n8n executions` debe aparecer una corrida con HTTP 200
  o un 429/409 (esperado durante validación paralela mientras el worker viejo
  sigue corriendo).
- Comparar el conteo del response (`fetched`, `created`, `comments`, `skipped`,
  `errors`) con la salida de `bin/cake import_gmail` ejecutado manualmente:
  los conteos deben converger una vez el worker se apague.
- **Identificador de ticket en el payload:** el response usa la clave `id` (entero,
  arranca en 1000) como identificador de cada ticket creado. La clave anterior
  `ticket_number` fue eliminada; actualizar cualquier expresión n8n que la
  referenciara para leer `id` en su lugar.
- En logs de la app: `Gmail import completed` con los mismos totales.

## Códigos de respuesta

| Código | `error` | Significado | Acción |
|--------|---------|-------------|--------|
| 200 | — | Importación exitosa | Ninguna |
| 401 | `invalid_token` | Token ausente o no coincide | Actualizar credencial en n8n |
| 409 | `already_running` | Otro proceso tiene el lock | Reintentar en próxima ventana |
| 429 | `too_soon` | Última corrida hace < 60s | Esperar `retry_after_seconds` |
| 500 | `import_failed` | Excepción no controlada | Revisar logs |
| 503 | `not_configured` | Falta `gmail_refresh_token` | Re-autorizar Gmail en `/admin/settings` |

## Workflow exportado

> **TODO:** después de crear y validar el workflow en n8n, exportarlo
> (`Workflows → ⋮ → Download`) y guardar el JSON aquí mismo, en este directorio,
> como `n8n-gmail-webhook-workflow.json`. Esto permite reconstruir el workflow
> en otra instancia de n8n sin reinventar la configuración.
