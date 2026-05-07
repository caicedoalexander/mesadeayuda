# Review del uso de la Gmail API

**Fecha:** 2026-05-07
**Alcance:** `src/Service/GmailService.php`, `src/Service/GmailImportService.php`, `src/Command/ImportGmailCommand.php`, `src/Controller/WebhooksController.php`, `src/Controller/Admin/SettingsController.php`, integración en `src/Service/TicketService.php` y `src/Service/EmailService.php`.
**Versión de la librería:** `google/apiclient: ^2.18` (composer.json:15).

Review enfocada en el uso de la Gmail API y de los clientes de correo destinatarios, ordenada por severidad.

---

## 🔴 Bugs funcionales

### 1. `isSystemNotification` rompe el flujo de respuestas legítimas
`src/Service/GmailService.php:419-452`

El check incluye un patrón de asunto:

```php
$notificationPatterns = ['Re: [Ticket #', 'Re: Tu Solicitud'];
foreach ($notificationPatterns as $pattern) {
    if (stripos($subject, $pattern) !== false) {
        return true;
    }
}
```

Cuando un usuario responde a la notificación de su ticket, Gmail pone automáticamente `Re: [Ticket #123] …` en el `Subject`. En `GmailImportService::run` (`src/Service/GmailImportService.php:112-116`) ese mensaje cae en `is_system_notification` y se descarta **antes** de llegar a la búsqueda por `gmail_thread_id`:

```php
if (!empty($emailData['is_system_notification'])) {
    $this->gmail->markAsRead($messageId);
    $skipped++;
    continue;          // ← nunca llega a createCommentFromEmail
}
```

**Resultado:** respuestas válidas del solicitante a un ticket existente se marcan como leídas y se ignoran silenciosamente.

La señal robusta ya existe — el header `X-Mesa-Ayuda-Notification: true` que `EmailService::sendEmail` añade en `src/Service/EmailService.php:148`.

**Recomendación:** quitar la heurística por subject y dejar solo `X-Mesa-Ayuda-Notification` + `From == GMAIL_USER_EMAIL`.

---

### 2. `sendEmail` saliente no enhebra (no threading)
`src/Service/GmailService.php:493-521`

Al enviar:

```php
$message = new Message();
$message->setRaw($encodedMessage);
$service->users_messages->send('me', $message);
```

Falta:

- `$message->setThreadId($ticket->gmail_thread_id)` — sin esto Gmail abre un hilo nuevo cada vez (en la pestaña de Gmail del usuario del sistema).
- Headers MIME `In-Reply-To: <Message-ID>` y `References: <Message-ID>` — sin esto los clientes de correo del solicitante (Outlook, Apple Mail, etc.) no anidan la respuesta bajo el correo original.

`createMimeMessage` no recibe ni inyecta esos headers, y `EmailService::sendEmail` (`src/Service/EmailService.php:111-172`) tampoco los propaga.

**Plan de fix:**

1. Capturar el `Message-ID` del primer correo entrante en `parseMessage`, persistirlo en `tickets` (nueva columna `gmail_rfc_message_id`).
2. Pasar `threadId` + `messageId` a través de `EmailService → GmailService::sendEmail` → MIME headers + `Message::setThreadId`.
3. Importante: cuando se usa `threadId`, Gmail exige que el subject coincida con el del hilo, así que **no** se debe alterar el prefijo `Re: [Ticket #N]`.

---

### 3. Imágenes inline se parsean pero nunca se guardan
`src/Service/GmailService.php:301-308` + `src/Service/TicketService.php:361-396`

`extractMessageParts` separa `inline_images` (parts con `Content-ID` y `image/*`) de `attachments`, pero `processEmailAttachments` solo itera `$emailData['attachments']`. Resultado: el HTML del cuerpo conserva referencias `src="cid:abc123@..."` que nunca se descargan ni se reemplazan; el ticket muestra imágenes rotas.

**Opciones:**

- Descargar también `inline_images`, guardarlas con `is_inline=true` y reescribir el `cid:` por la URL del attachment guardado durante `sanitizeHtml`.
- O fusionarlas en `attachments` para que al menos queden como adjuntos descargables.

---

## 🟠 Problemas de eficiencia / robustez

### 4. El constructor de `GmailService` hace I/O remoto en cada instanciación
`src/Service/GmailService.php:82-130`

`initializeClient()` llama síncronamente a `fetchAccessTokenWithRefreshToken()` cada vez que se construye el servicio. No hay caché del access token (`Google\Client` soporta `setCache()` con PSR-6).

Además, `TicketService::createFromEmail` y `createCommentFromEmail` instancian `new GmailService()` sin config (`src/Service/TicketService.php:81, 177`) **solo para usar `extractEmailAddress`/`extractName`**. Con config vacía el constructor loguea `Gmail client_secret not configured in system_settings` en cada email parseado — ruido en logs y pago de la inicialización del `GoogleClient` para nada.

**Recomendaciones:**

- Extraer los helpers de parsing (`extractEmailAddress`, `extractName`, `parseRecipients`, `getHeader`) a una clase `GmailMessageParser` o métodos estáticos, sin dependencia del cliente OAuth.
- Diferir `fetchAccessTokenWithRefreshToken` hasta el primer uso real (`getService()` lazy ya existe; mover el refresh allí).
- Configurar `$client->setCache(new SymfonyCache…)` para reutilizar access tokens dentro de la ventana de 1h.

---

### 5. `getSystemEmail` consulta DB por cada mensaje
`src/Service/GmailService.php:459-472`

Llamado desde `isSystemNotification`, que se ejecuta una vez por mensaje en el loop de import. Hace `find()->where(['setting_key' => GMAIL_USER_EMAIL])` cada vez en lugar de leer del cache de `SettingsService::loadAll()`. Para 50–200 mensajes son 50–200 queries innecesarias.

**Fix:** pasar `$systemEmail` por constructor (igual que `TicketService` recibe `$systemConfig`) o memoizar en propiedad de instancia.

---

### 6. N+1 en lookup de `gmail_thread_id`
`src/Service/GmailImportService.php:124-128`

Una query por mensaje. `existingMessageIds` ya se precarga en bulk (líneas 89-94) — replicar el patrón para thread IDs:

```php
$threadIds = array_filter(array_column($parsed, 'gmail_thread_id'));
$ticketsByThread = $ticketsTable->find()
    ->where(['gmail_thread_id IN' => $threadIds])
    ->all()->indexBy('gmail_thread_id')->toArray();
```

---

### 7. `getMessages` traga errores
`src/Service/GmailService.php:181-206`

```php
} catch (\Exception $e) {
    Log::error('Error fetching Gmail messages: ' . $e->getMessage());
    return [];
}
```

Un 401 (refresh token revocado), 429 (rate limit) o 5xx de Google se reportan como "0 mensajes nuevos" en el webhook. n8n no podrá distinguir "todo OK" de "API caída".

**Fix:** re-lanzar y dejar que `WebhooksController::gmailImport` lo convierta en 503/500.

---

### 8. `getMessages` no pagina

La API devuelve `nextPageToken` cuando hay más resultados que `maxResults`. Hoy el cap es 200 (en `GmailImportService::run`), suficiente normalmente, pero no hay defensa si la cola crece (p. ej. tras un outage) — el siguiente run procesará otros 200, y mientras tanto los más antiguos siguen sin leerse.

---

## 🟡 Higiene / detalles

| # | Ubicación | Observación |
|---|-----------|-------------|
| 9 | `GmailService.php:104-106` | `GMAIL_MODIFY` ya incluye lectura/modificación; pedir además `GMAIL_READONLY` es redundante en la pantalla de consentimiento. |
| 10 | `GmailService.php:269-275` | `body_html` y `body_text` se concatenan con `\n` cuando hay múltiples partes del mismo MIME type. Para `text/html` esto puede romper el HTML si hay dos partes alternativas. Mejor: tomar la primera y descartar el resto, o respetar `multipart/alternative` correctamente. |
| 11 | `GmailService.php:269-275` | Sin límite de tamaño para `base64_decode`. Un correo de 25 MB se carga entero a memoria. Considerar cap (`$body->getSize() > X` → reject o truncar). |
| 12 | `TicketService.php:364-371` | `usleep(200000)` por attachment es razonable, pero la API de Gmail soporta batch (`$client->setUseBatch(true)` + `Google\Http\Batch`) para descargar varios attachments en un solo HTTP. |
| 13 | `GmailService.php:377-403` | `is_auto_reply` cubre RFC 3834 parcialmente; falta `auto-notified`. Marginal. |
| 14 | `GmailService.php:108` | `setPrompt('consent')` solo importa cuando se llama `createAuthUrl()`. Está bien, pero sería más limpio aplicarlo solo en `getAuthUrl()` para no acoplarlo al cliente runtime. |
| 15 | `WebhooksController.php:53` | `@set_time_limit(...)` silencia errores; quita el `@`, y considera que PHP-FPM puede ignorarlo según `request_terminate_timeout`. |
| 16 | `GmailService.php:131-144` | Bien que `getService()` sea lazy, pero `initializeClient()` ya disparó el token refresh en el constructor — el lazy de `Gmail` es testimonial. |
| 17 | `GmailService.php:281-313` | Lógica de inline vs attachment correcta y bien documentada. Buen manejo del `Content-Disposition`. ✅ |
| 18 | `GmailService.php:530-541` | Sanitización de CRLF y encoding RFC 2047 con `mb_encode_mimeheader` correctos. ✅ |
| 19 | `SettingsService.php:28-33` | `gmail_settings` está incluido en `CACHE_KEYS` y se invalida al guardar settings. ✅ |
| 20 | `GmailService.php:336` | Decodificación url-safe base64 (`strtr('-_', '+/')`) correcta. ✅ |

---

## Recomendaciones priorizadas

1. **Quitar el patrón `Re: [Ticket #` de `isSystemNotification`** — bug con impacto directo en el flujo de respuestas. Cambio de 5 líneas.
2. **Threading saliente** — guardar `Message-ID` del correo original en `tickets`, y al enviar setear `threadId` + headers `In-Reply-To`/`References`. Requiere migración (columna `gmail_rfc_message_id`) + cambios en `EmailService::sendEmail` + `GmailService::createMimeMessage`.
3. **Procesar `inline_images`** — descarga + reescritura `cid:` → URL en el HTML sanitizado.
4. **Extraer parser puro** (`GmailMessageParser`) para que `TicketService` no instancie `GoogleClient` solo para leer un From header.
5. **Caché del access token** vía `Google\Client::setCache()` con PSR-6 (CakePHP expone uno desde `Cake\Cache\Engine`).
6. **`getMessages` debe propagar errores** para que el webhook devuelva el status correcto a n8n.
7. **Eliminar el N+1 de `gmail_thread_id`** en el import (bulk query por threads).

Las dos de mayor impacto y bajo riesgo son la #1 (heurística de subject) y la #3 (inline images).
