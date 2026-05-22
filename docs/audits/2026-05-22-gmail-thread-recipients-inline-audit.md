# Auditoría — Hilo Gmail, destinatarios, inline images y acciones múltiples

- **Fecha:** 2026-05-22
- **Alcance:** Ciclo completo de un correo en el sistema — ingestión, parseo de partes/recipients/cabeceras RFC, persistencia, render del hilo en UI, editor de respuesta (To/CC), construcción y hilado de notificaciones outbound, acciones combinadas (comentario + cambio de estado + adjuntos), manejo de inline images y reescritura de URLs.
- **Disparada por:** Solicitud del owner del producto sobre representación del hilo tipo Gmail timeline, comportamiento de CC/To en respuestas, y verificación de inline images.
- **Método:** Audit estático con 5 agentes especializados (`acc:bug-hunter` ×2, `acc:business-logic-analyst` ×3) sobre el árbol completo del código.
- **Archivos auditados:**
  - `src/Service/GmailService.php`
  - `src/Service/GmailImportService.php`
  - `src/Service/TicketIngestionService.php`
  - `src/Service/TicketCommentService.php`
  - `src/Service/TicketPipelineService.php`
  - `src/Service/TicketAttachmentService.php`
  - `src/Service/EmailService.php`
  - `src/Service/Traits/GenericAttachmentTrait.php`
  - `src/Service/Traits/HtmlSanitizerTrait.php`
  - `src/Service/Util/EmailHeaderParser.php`
  - `src/Service/Util/NotificationStamp.php`
  - `src/Notification/Strategy/AbstractTicketStrategy.php`
  - `src/Notification/Strategy/TicketRespondedStrategy.php`
  - `src/Notification/Strategy/TicketCommentAddedStrategy.php`
  - `src/Notification/Strategy/TicketCreatedStrategy.php`
  - `src/Notification/Strategy/TicketStatusChangedStrategy.php`
  - `src/Notification/Email/Ticket/Template/*.php`
  - `src/Model/Entity/Ticket.php`
  - `src/Model/Entity/TicketComment.php`
  - `src/Model/Entity/Trait/EmailRecipientsTrait.php`
  - `src/Model/Table/AttachmentsTable.php`
  - `src/Controller/Trait/TicketActionsTrait.php`
  - `templates/Tickets/view.php`
  - `templates/element/tickets/comments_list.php`
  - `templates/element/tickets/_thread_message.php`
  - `templates/element/tickets/reply_editor.php`
  - `webroot/js/reply-editor-init.js`
  - `webroot/js/email-recipients.js`
  - `webroot/css/tickets-view.css`
  - `config/Migrations/20260430213127_Initial.php`
  - `config/Migrations/20260518120000_AddRfcThreadingToTickets.php`

---

## 1. Resumen ejecutivo

El pipeline Gmail → tickets cubre el caso de uso básico (ingerir email, crear ticket, responder vía editor, enviar notificación al cliente) **pero tiene cuatro gaps críticos y varias brechas de robustez** que afectan directamente la experiencia conversacional:

1. **Las respuestas del cliente a notificaciones se descartan silenciosamente** por un falso positivo en la detección de "notificación propia" (HMAC stamp).
2. **El threading outbound está roto en tres puntos** (Message-ID no se persiste, In-Reply-To/References no se inyectan, comments outbound quedan con `rfc_message_id` NULL) — el hilado en el cliente depende de un fallback frágil.
3. **Los CC añadidos por agentes no pueden responder** — el set autorizado para reattachment no se expande con los recipients de comments posteriores.
4. **Las imágenes inline no se procesan** — se extraen pero no se descargan, no se marcan como inline, y no se reescriben las URLs `cid:` (HTMLPurifier las elimina del body).

El UI muestra los comments en **lista plana cronológica**; no existe representación de árbol al estilo Gmail timeline aunque los datos necesarios (`rfc_message_id`, `in_reply_to`, `references_header`) están persistidos para los comments ingestados desde Gmail (no para los outbound).

**Hallazgos por severidad:**

| Severidad | Cantidad | IDs |
|-----------|----------|-----|
| Crítico | 4 | L2, J1+J2+J7 (compuesto), K3+K4+K5 (compuesto), F1+F2+G1 (compuesto) |
| Alto | 3 | L3, A3, B1 |
| Medio | 6 | I4, J3, J4, J5, G2, F3 |
| Bajo | 7 | A1, A2, A4, D1, D3, G4, J8 |
| Verificado OK | 8 | I1, I2, I3, I5, I7, K1, K2, E1, E2, G3 |

---

## 2. Estructura del flujo (resumen contextual)

```
   ┌───────────────────────────────────────────────────────────────┐
   │                          INBOUND                              │
   │                                                               │
   │  Cliente ──email──► Gmail API ──parseMessage()──► emailData   │
   │                                                               │
   │  emailData ──findExistingTicketByThreading()──► Ticket | null │
   │                                                               │
   │  null  ──createFromEmail()        ──► Ticket nuevo + event    │
   │  exist ──createCommentFromEmail() ──► Comment + event         │
   │                                                               │
   └───────────────────────────────────────────────────────────────┘

   ┌───────────────────────────────────────────────────────────────┐
   │                          OUTBOUND                             │
   │                                                               │
   │  Editor agente ──handleResponse()                             │
   │    TX1: addComment + uploads                                  │
   │    TX2: changeStatus (+ system note + transitionTo)           │
   │    post-commit: dispatch TicketResponded |                    │
   │                          TicketStatusChanged |                │
   │                          TicketCommentAdded                   │
   │                                                               │
   │  Listener ──Strategy.build()──► NotificationMessage           │
   │    EmailChannel ──EmailService::dispatch()──► Gmail API       │
   │                                                               │
   └───────────────────────────────────────────────────────────────┘
```

---

## 3. Hallazgos críticos

### CRIT-1 (L2) — Respuestas del cliente a notificaciones se descartan silenciosamente 🔴

**Síntoma:** Toda réplica del cliente a una notificación nuestra se identifica como `is_system_notification = true` y se descarta en el loop de import.

**Cadena de causa:**

1. `EmailService::sendEmail` línea 88-90 detecta `#<digits>` en el subject y llama a `NotificationStamp::append`, produciendo p.ej. `Tu ticket #123 fue creado [#123·s=ab12cd34]`.
2. El cliente recibe el correo y responde. Su MUA cita el subject verbatim con prefijo `Re:` — comportamiento estándar de Gmail, Outlook, Apple Mail, Thunderbird, etc.
3. Ingestamos la réplica. `parseMessage` → `isSystemNotification($headers)`.
4. `GmailService.php:667`:
   ```php
   if (NotificationStamp::verifiedTicketNumber($subject) !== null) return true;
   ```
5. `verifiedTicketNumber`:
   - `preg_match('/\[#(\d+)·s=([0-9a-f]{8})\]/u', $subject, $m)` matchea (el stamp sobrevive el `Re:`).
   - `hash_equals(compute('123'), 'ab12cd34')` → `true` (el salt es el mismo de cuando estampamos).
   - Retorna `'123'`.
6. `isSystemNotification` retorna `true`.
7. `GmailImportService::run` línea 196-200: `$skipped++; continue;`. **La réplica del cliente NUNCA se almacena.**

**Causa raíz:** El HMAC del stamp enlaza únicamente `'ticket:' . $ticketNumber` + `Security.salt`. **No incluye `From`, `To`, ni ningún nonce per-recipient.** Por diseño cualquier mail que cite un stamp válido se cataloga como "notificación propia" — pero esa categoría debería aplicar solo cuando *nosotros* somos el From, no cuando es el cliente respondiendo.

**Evidencia:** `src/Service/Util/NotificationStamp.php:48-55` (HMAC sin From en input), `src/Service/GmailService.php:662-691` (`isSystemNotification` evalúa stamp antes que From).

**Impacto:** 🔴 Crítico — funcionalidad central del producto rota en producción. Cada conversación que involucre al menos una notificación + una réplica del cliente queda partida en dos tickets (o pierde la réplica). Solo se salva si el cliente edita manualmente el subject quitando el stamp.

**Fix sugerido:**
- **Opción A (mínima)**: agregar guard "el stamp solo descarta si `From == system_email`". El loop interno de `isSystemNotification` ya tiene un check From==system (líneas 680-685) — basta moverlo antes del check de stamp y hacer el stamp condicional a ese match.
- **Opción B (rediseño)**: cambiar la semántica del stamp de **discard** a **route** — usar el `ticket_number` extraído del stamp como hint directo para reattachment (saltar `findExistingTicketByThreading`), y solo descartar cuando From==system (auto-loop real).

---

### CRIT-2 (J1+J2+J7 compuesto) — Threading outbound roto 🔴

Tres gaps que en cadena rompen el hilado en el lado cliente:

#### J1 — `Message-ID` outbound se descarta

`GmailService.php:909`:
```php
$service->users_messages->send('me', $message);
return true;
```

La API de Gmail retorna un `Google\Service\Gmail\Message` con `id` y `threadId` del mensaje recién creado, y el `Message-ID` asignado por Gmail es recuperable vía `messages.get` con `metadataHeaders=['Message-ID']`. **No lo hacemos** — el resultado del send se descarta.

#### J2 — No se envían `In-Reply-To` ni `References`

`EmailService.php:115-118`:
```php
$options = [
    'from' => [$fromEmail => $systemTitle],
    'headers' => ['X-Mesa-Ayuda-Notification' => 'true'],
];
```

`GmailService::createMimeMessage` líneas 1047-1054 honra `$options['headers']` (sanitiza CRLF, los inyecta en el MIME), pero `EmailService` nunca incluye los headers de threading.

#### J7 — `ticket_comments.rfc_message_id` queda NULL para outbound

`TicketCommentService::addComment` líneas 60-66:
```php
$data = [
    'ticket_id' => $entityId,
    'user_id' => $userId,
    'comment_type' => $type,
    'body' => $sanitizedBody,
    'is_system_comment' => $isSystem,
];
```

No setea `rfc_message_id`, `in_reply_to`, `references_header`. Contrastar con `TicketIngestionService::createCommentFromEmail` líneas 321-323 que sí los persiste para inbound.

**Resultado en cascada:**

- Cliente responde a una notificación → su `In-Reply-To` apunta al `<message-id>` que **Gmail** asignó a nuestro outbound, ID que **jamás persistimos**.
- `lookupTicketByRfc` busca en `ticket_comments.rfc_message_id` (todos NULL para outbound) → no match. Busca en `tickets.rfc_message_id` (es el RFC del email original del cliente, no del outbound) → no match.
- `findExistingTicketByThreading` cae al fallback final `gmail_thread_id` (línea 396-400).

**Eso "funciona" solo porque Gmail (nuestra cuenta) mantiene el mismo `threadId` cuando ingerimos la réplica al outbound que enviamos.** Es frágil ante:
- Reenvíos.
- Clientes externos (Outlook con cuenta on-prem) que rompen threading al replicar.
- Hilos que Gmail decide partir por longitud o subject change.
- Subjects que cambian (el agente edita el subject de la notificación).

**Impacto:** 🔴 Crítico — el cliente ve cada notificación como un email suelto en su inbox, no hilados con su original. UX de soporte se degrada.

**Fix sugerido (patch entregado por el audit):**

```php
// 1) GmailService::sendEmail retorna ?string (Message-ID asignado)
public function sendEmail(...): ?string {
    // ... existing
    $sent = $service->users_messages->send('me', $message);
    $sentId = $sent->getId();
    if ($sentId !== null) {
        $full = $service->users_messages->get('me', $sentId, [
            'format' => 'metadata',
            'metadataHeaders' => ['Message-ID'],
        ]);
        foreach ($full->getPayload()?->getHeaders() ?? [] as $h) {
            if (strtolower($h->getName()) === 'message-id') {
                return EmailHeaderParser::extractMessageId($h->getValue());
            }
        }
    }
    return null;
}

// 2) EmailService::sendEmail inyecta threading headers
$options['headers']['In-Reply-To'] = '<' . $inReplyTo . '>';
$options['headers']['References']  = $referencesChain;  // '<id1> <id2>'

// 3) TicketCommentService gana attachOutboundMessageId(commentId, rfcId, refs)
//    invocado por el listener tras send() exitoso
```

Las strategies (`TicketResponded`, `TicketCommentAdded`, `TicketStatusChanged`) resuelven el "último ancla" y arman la cadena `References`:

```php
$lastInbound = TicketComments->find()
    ->where(['ticket_id' => $ticket->id, 'rfc_message_id IS NOT' => null])
    ->order(['id' => 'DESC'])->first();
$inReplyTo = $lastInbound->rfc_message_id ?? $ticket->rfc_message_id;

$chain = [];
if ($ticket->rfc_message_id) $chain[] = '<' . $ticket->rfc_message_id . '>';
foreach ($commentsWithRfc as $c) $chain[] = '<' . $c->rfc_message_id . '>';
$references = implode(' ', $chain);
```

---

### CRIT-3 (K3+K4+K5 compuesto) — Los CC añadidos por agentes no pueden responder 🔴

`TicketIngestionService::isEmailInTicketRecipients` líneas 700-738 consulta exclusivamente:
- `ticket.email_to_array`
- `ticket.email_cc_array`
- `ticket.requester.email`

**No consulta `ticket_comments.email_to` ni `ticket_comments.email_cc`**.

Resultado: cuando el agente añade `colega@externo.com` como CC en una respuesta, el flujo es:

1. Comment se persiste con `ticket_comments[N].email_cc = '[{"email":"colega@externo.com",...}]'`.
2. `tickets.email_cc` **sigue NULL** (nada lo expande).
3. `EmailService::sendEmail` envía outbound con CC al colega.
4. El colega responde. Threading lo reattacha al ticket por `gmail_thread_id`.
5. `createCommentFromEmail` ejecuta `isEmailInTicketRecipients($ticket, 'colega@externo.com')`:
   - `email_to_array`: no match.
   - `email_cc_array`: no match (nunca se actualizó).
   - `requester.email`: no match.
   - **Retorna `false`**.
6. `createCommentFromEmail` retorna `null` con `Log::warning('Unauthorized email sender attempted to reply to ticket')`.
7. **Réplica del colega perdida silenciosamente.**

**Evidencia:** `src/Service/TicketIngestionService.php:265-281, 700-738`.

**Impacto:** 🔴 Crítico para el caso de uso "incluir a un experto externo en la conversación". Hoy no funciona — el experto recibe el correo pero su respuesta nunca llega al ticket.

**Fix sugerido:** expandir `isEmailInTicketRecipients` para hacer UNION sobre los recipients de todos los `ticket_comments` del ticket. Query directa:

```php
$authorized = $this->fetchTable('TicketComments')->find()
    ->where(['ticket_id' => $ticket->id])
    ->select(['email_to', 'email_cc'])
    ->all()
    ->reduce(function (array $acc, $c): array {
        foreach ([json_decode((string)$c->email_to, true), json_decode((string)$c->email_cc, true)] as $set) {
            if (is_array($set)) {
                foreach ($set as $r) {
                    if (!empty($r['email'])) $acc[strtolower($r['email'])] = true;
                }
            }
        }
        return $acc;
    }, []);
// ...check $normalizedEmail against $authorized in addition to ticket-level set
```

---

### CRIT-4 (F1+F2+G1 compuesto) — Inline images no se procesan + URLs no se reescriben 🔴

Tres gaps en cadena que rompen las imágenes embebidas (logos de firma, screenshots inline):

#### F1 — `inline_images[]` extraído pero ignorado

`TicketIngestionService::createFromEmail` línea 134-136:
```php
if (!empty($emailData['attachments'])) {
    $this->attachments->processEmailAttachments($ticket, $emailData['attachments'], $user->id);
}
```

Y `createCommentFromEmail` línea 346-348 hace lo mismo. **`$emailData['inline_images']` nunca se pasa a `processEmailAttachments`**, aunque `GmailService::parseMessage` lo extrae correctamente (separado de `attachments[]` en líneas 440-454 de `extractMessageParts`).

#### F2 — `is_inline` hardcoded false; `content_id` omitido

`GenericAttachmentTrait::buildAttachmentData` líneas 221-242:
```php
return [
    'ticket_id' => $entityId,
    'comment_id' => $commentId,
    'uploaded_by' => $userId,
    'is_inline' => false,                  // <-- hardcoded
    // ... no 'content_id' key at all
];
```

Sin parámetros para `isInline` ni `contentId`. Las columnas existen en BD (`config/Migrations/20260430213127_Initial.php:74-82`) con índices `idx_ticket_inline` y `idx_content_id`. Las reglas de validación en `AttachmentsTable.php:102-109` aceptan los campos. La infraestructura está lista pero no se usa.

#### G1 — Sin reescritura `cid:`; HTMLPurifier strippea el src

`HtmlSanitizerTrait.php:34`:
```php
$config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true]);
```

`cid:` no está permitido. Y `GmailService::parseMessage` líneas 358-360 sanitiza **antes** de cualquier oportunidad de reescritura. Resultado:

1. Email llega con `<img src="cid:abc@host">`.
2. Sanitize en `parseMessage` elimina el `src` (deja `<img>` vacío).
3. `TicketIngestionService` recibe HTML ya sin la referencia `cid:`.
4. No hay forma de descargar la imagen ni de saber a qué `<img>` re-vincularla.

**Búsqueda exhaustiva en `src/`:** no existe ninguna rama de código que mapee `cid:CID` → URL local.

**Impacto:** 🔴 Crítico para correos con firmas con logo, screenshots inline, o cualquier `<img>` embebido. El thread muestra `<img>` rotos (sin `src`).

**Fix sugerido (patch entregado por el audit):**

```php
// 1) Eliminar sanitize prematuro en GmailService::parseMessage:358-360
//    (o guard detrás de flag opt-in).

// 2) GenericAttachmentTrait::buildAttachmentData acepta isInline + contentId.
private function buildAttachmentData(
    ...,
    bool $isInline = false,
    ?string $contentId = null,
): array {
    return [
        // ...
        'is_inline' => $isInline,
        'content_id' => $contentId,
        // ...
    ];
}

// 3) saveAttachmentFromBinary acepta y forward los params.

// 4) Nuevo TicketAttachmentService::processInlineImages que descarga,
//    persiste con isInline=true/contentId, y retorna mapa [cid => /uploads/url].

// 5) TicketIngestionService rewrite cid: ANTES del sanitize:
private function rewriteCidReferences(string $html, array $cidMap): string {
    return preg_replace_callback(
        '/(<img\b[^>]*\bsrc\s*=\s*["\'])cid:([^"\']+)(["\'])/i',
        fn($m) => isset($cidMap[trim($m[2])])
            ? $m[1] . $cidMap[trim($m[2])] . $m[3]
            : $m[0],
        $html,
    ) ?? $html;
}

// 6) Flujo en createFromEmail:
$cidMap = !empty($emailData['inline_images'])
    ? $this->attachments->processInlineImages($ticket, $emailData['inline_images'], $userId)
    : [];
$rawBody = $emailData['body_html'] ?: $emailData['body_text'];
$rewritten = $this->rewriteCidReferences($rawBody, $cidMap);
$description = $this->sanitizeHtml($rewritten);
```

El template `comments_list.php:42-50, 140-146` ya tiene la lógica correcta para filtrar inline images cuyo `content_id` aparece en el body — solo necesita que los datos lleguen correctamente.

---

## 4. Hallazgos altos

### ALT-1 (L3) — `isAutoReply` falsos positivos por `List-Unsubscribe` o `Feedback-ID` 🟠

`GmailService.php:633-641`:
```php
if (trim($this->getHeader($headers, 'List-Unsubscribe')) !== '') {
    return true;
}
if (trim($this->getHeader($headers, 'Feedback-ID')) !== '') {
    return true;
}
```

Casos que rompen:
- Boletín corporativo forwardeado a soporte (`List-Unsubscribe` presente) → ticket no se crea.
- Email transaccional (Stripe, GitHub, Shopify) reenviado al equipo → `Feedback-ID` presente → silent drop.
- Usuarios de Google Workspace groups con `List-Unsubscribe` automático en cada mensaje.

Las RFCs 2369/8058 dicen que mail bulk/list TIENE `List-Unsubscribe`, pero el converso no es cierto: Google/Yahoo desde 2024 empujan a todos los senders transaccionales a incluirlo.

**Impacto:** 🟠 Alto — silent ticket loss, observable solo por discrepancia entre `fetched` y `created+comments+skipped` counters.

**Fix sugerido:** requerir combinación (`List-Unsubscribe` o `Feedback-ID`) **+** (`Precedence: bulk|list|junk` o `Auto-Submitted != no`). La combinación es el patrón real de bulk sender.

### ALT-2 (A3) — `withinReattachWindow` rechaza tickets abiertos antiguos 🟠

`src/Service/TicketIngestionService.php:431-443`:

```php
if ($ticket->isResolved() && $modified->lessThan($cutoff)) {
    return false;
}
return $modified->greaterThanOrEquals($cutoff);
```

El docblock dice "rechaza cerrados-antiguos pero acepta abiertos-antiguos". La realidad: el `return` final rechaza **todo** ticket más viejo que el cutoff (90 días), abierto o cerrado.

**Impacto:** 🟠 Alto — un ticket abierto que recibe una réplica legítima > 90 días después se fragmenta como ticket nuevo. Común en proyectos largos.

**Fix sugerido:**
```php
if ($ticket->isResolved()) {
    return $modified->greaterThanOrEquals($cutoff);
}
return true;  // open ticket: no recency gate
```

### ALT-3 (B1) — UI sin árbol de hilo (lista plana cronológica) 🟠

`templates/element/tickets/comments_list.php:119-161` renderiza una `<section class="thread-messages">` que itera linealmente sobre `$comments`. La única "línea vertical" es decorativa (`.thread-messages::before`, ancla todos los avatares).

Datos disponibles para construir el árbol:
- `tickets.rfc_message_id`, `tickets.in_reply_to`, `tickets.references_header` (migración `AddRfcThreadingToTickets`).
- `ticket_comments` con las mismas columnas, pobladas **solo para inbound** (vía `createCommentFromEmail`); NULL para outbound (ver CRIT-2 / J7).

**Impacto:** 🟠 Alto en UX — el agente no ve a qué mensaje responde cada réplica, especialmente en threads largos con múltiples participantes.

**Fix sugerido (plan en 3 fases entregado por el audit):**
1. **Fase 1**: implementar CRIT-2 (persistir Message-ID outbound). Sube cobertura de threading a ~100% en tickets nuevos.
2. **Fase 2**: chip `↳ En respuesta a: "Asunto del mensaje"` + botón "Responder" en cada `_thread_message`. Bajo riesgo, alto valor informativo.
3. **Fase 3**: árbol completo con `ThreadTreeHelper` (construcción O(n), cycle prevention, depth clamp 3 niveles), nuevo `_thread_node.php` recursivo, system notes a nivel raíz por `created`.

---

## 5. Hallazgos medios

### MED-1 (I4) — Mensaje de éxito engañoso si TX2 hace rollback no-excepción

`TicketPipelineService.php:184-235`. Si `$table->save($entity)` retorna `false` dentro de la closure transactional (línea 299), la TX2 rollbackea **silenciosamente** sin disparar `InvalidStatusTransitionException`. El catch del `try` no entra. `$pendingEvents` queda vacío. El return en línea 254 invoca `buildResponseResult($hasComment, $hasStatusChange, ...)` con `$hasStatusChange=true` y produce mensaje "Comentario agregado y estado actualizado exitosamente" aunque el estado no cambió.

**Fix sugerido:** captura el `$tx2Ok` del `transactional()` (devuelve `bool`), y si es false propaga un mensaje de éxito parcial.

### MED-2 (J3) — Threading depende exclusivamente del fallback `gmail_thread_id`

Ya cubierto en CRIT-2. Se conserva como ítem separado para tracking porque la mitigación parcial (mientras CRIT-2 no se implementa) es **monitorear el ratio "reattachment por RFC vs por threadId"** en logs.

### MED-3 (J4) — Stamp HMAC se concatena indefinidamente en subjects

`NotificationStamp::append` línea 35-38 hace `rtrim($subject) . ' [#...]'` sin `preg_replace` previo. Si el subject ya tiene stamp (porque el cliente quoteó), append añade un segundo.

Después de N rondas: `Re: foo [#123·s=...] [#123·s=...] [#123·s=...]`. La verificación matchea el primero (regex no anclado), así que no rompe funcionalidad — cosmético + bloat de subject.

**Fix sugerido:** `preg_replace(STAMP_RE, '', $subject)` antes del append.

### MED-4 (J5) — Templates outbound sin `Re:` y con subjects que cambian

`TicketCommentAddedTemplate.php:42`: `"$agentName te respondió en el ticket #N"`.
`TicketUpdatedTemplate.php:38`: `"$agentName actualizó tu ticket #N"`.

Sin `Re:` y con subjects que mutan entre notificaciones, los MUAs que carecen de `In-Reply-To` (J2) no hilan visualmente. El stamp ayuda a identificación lógica pero no al hilado del cliente.

**Fix sugerido:** prefijar `Re:` cuando el evento es respuesta a una conversación existente (todos los `TicketResponded`, `TicketCommentAdded`, `TicketStatusChanged`); dejar `TicketCreated` sin `Re:`.

### MED-5 (G2) — URLs `googleusercontent.com` y tracking pixels permanecen en body

Búsqueda en `src/`: no hay filtro/reescritura de `googleusercontent.com`, `mail.google.com`, `gstatic.com` (solo entradas CSP no relacionadas en `Application.php:162`).

Riesgo: cada vez que un agente abre un ticket, el browser fetch'ea avatares de Google (`lh3.googleusercontent.com`), proxies de imágenes Gmail (`ci3.googleusercontent.com`), y pixels de tracking de terceros. **Google y vendors externos saben cuándo se abren los tickets** (leak de timeline).

**Fix sugerido:** regex pre-pass antes de sanitize:
```php
$html = preg_replace_callback(
    '/<img\b[^>]+\bsrc\s*=\s*["\']https?:\/\/([^"\'\/]+)\/[^"\']*["\'][^>]*>/i',
    function (array $m): string {
        $host = strtolower($m[1]);
        $blocked = ['googleusercontent.com', 'gstatic.com', 'mail.google.com'];
        foreach ($blocked as $b) {
            if (str_ends_with($host, $b)) return '';
        }
        return $m[0];
    },
    $html,
);
```

### MED-6 (F3) — Filtro inline en `comments_list.php` es lógica muerta hoy

`comments_list.php:42-50` y `:140-146` filtran inline images cuyo `content_id` ya aparece en el body. Lógicamente correcto, pero hoy `is_inline` siempre es false (F2) y `content_id` siempre NULL (F2). El filtro existe pero no opera.

Cierre automático una vez F1+F2+G1 se implementen.

---

## 6. Hallazgos bajos

### BAJ-1 (A1) — Forwards anidados duplican `<html>...</html>` en el body

`GmailService.php:392-463`. En el caso `multipart/mixed(alternative1, alternative2)` (forward con dos branches HTML), la recursión visita ambos `alternative`, cada uno elige su HTML, y la línea 414 concatena con `\n`. Resultado: `<html>html1</html>\n<html>html2</html>`.

Browsers lo renderizan tolerantes; markup inválido. Bajo impacto.

### BAJ-2 (A2) — `extractMessageId` tolera bordes pero falla en valores con comentarios RFC

`EmailHeaderParser.php:56-68` solo strip de outer brackets + trim. Falla en `<id@x> (comment)` (no termina en `>`); devuelve `id@x> (comment)`. Probabilidad baja (sólo MUAs muy antiguos emiten comments dentro del header).

### BAJ-3 (A4) — `gmail_history_id` capturado pero nunca persistido

`GmailService.php:334` pone el campo en `$emailData`, pero ni `tickets` ni `ticket_comments` tienen columna para él. Se usa solo transientemente en `GmailImportService` para advance del checkpoint global. El naming (`gmail_history_id`) sugiere persistencia que no existe — confunde al lector.

**Fix sugerido:** renombrar a `_transient_history_id` o documentar en docblock.

### BAJ-4 (D1) — Requester duplicable en `initialTo` del editor

`reply_editor.php:166-188`. Si `ticket.email_to` ya contiene al requester (lo cual ocurre cuando Gmail puso al requester en To explícito al enviar), `array_merge([requester], buildRecipients(email_to))` produce dos chips con el mismo email.

**Fix sugerido:** dedupe en `$initialTo` por email lowercased después del merge.

### BAJ-5 (D3) — `filterRecipients` no dedupe entradas internas ni cross To/Cc

`AbstractTicketStrategy::filterRecipients` líneas 57-79 excluye contra `$excludeEmails` pero no contra duplicados dentro del propio array, ni contra entradas que aparecen tanto en `email_to` como en `email_cc`.

Mitigado en `EmailService::sendEmail` líneas 92-105 al usar email como clave del array `$toRecipients`/`$ccRecipients` (PHP colapsa duplicados de clave). Cross-array (mismo email en To y Cc) no se mitiga.

### BAJ-6 (G4) — Tracking pixels 1×1 no se filtran

Sin regex que elimine `<img width="1" height="1">`. Igual que G2 — privacy hygiene.

**Fix sugerido:** mismo pre-pass que G2, regex adicional:
```php
$html = preg_replace(
    '/<img\b[^>]*\b(width|height)\s*=\s*["\']?1["\']?[^>]*\b(width|height)\s*=\s*["\']?1["\']?[^>]*>/i',
    '',
    $html,
);
```

### BAJ-7 (J8) — `Reply-To` no se setea explícito

Funciona por convención (`From` = `GMAIL_USER_EMAIL` que es la cuenta ingestora). Si en el futuro el setup separa `noreply@` (outbound) de `soporte@` (inbound), hay que setear `Reply-To` apuntando al inbox correcto.

---

## 7. Verificados OK (sin cambios)

| ID | Verificación | Evidencia |
|----|--------------|-----------|
| I1 | Comentario + cambio de estado → un solo email (`TicketResponded`) | `TicketPipelineService.php:181-215`, `deferDispatch=true` línea 195 |
| I2 | Tres ramas mutuamente excluyentes (público / interno / status) | `TicketPipelineService.php:181-246`, double guard en `TicketCommentAddedStrategy.php:37` |
| I3 | System note del cambio de estado siempre se crea (audit trail) | `TicketPipelineService.php:316-317` |
| I5 | Cleanup de archivos huérfanos correcto (try/finally) | `TicketPipelineService.php:118-167` |
| I7 | Validación de payload vacío | `TicketPipelineService.php:102-108` |
| K1 | Match by RFC apunta al comment correcto (orden `id DESC`) | `TicketIngestionService.php:450-466` |
| K2 | Cadena `References` parseada newest-first | `TicketIngestionService.php:387, 411-423` |
| E1 | JS dedup, validación de formato, bloqueo de system email | `webroot/js/email-recipients.js:40-49, 52, 138` |
| E2 | Cada comment muestra sus propios recipients | `comments_list.php:145-157`, `_thread_message.php:55-86` |
| G3 | HTMLPurifier scheme allowlist correcta (`http/https/mailto`) | `HtmlSanitizerTrait.php:34` |

---

## 8. Roadmap priorizado

Ordenado por **impacto / esfuerzo**. Cada ítem indica qué desbloquea.

| # | Fix | Severidad | Esfuerzo | Bloquea |
|---|-----|-----------|----------|---------|
| 1 | **CRIT-1 (L2)** — Stamp solo descarta si `From == system_email`; o cambiar a routing en vez de discard | 🔴 Crítico | XS | — |
| 2 | **ALT-1 (L3)** — Endurecer `isAutoReply` (combinación de headers) | 🟠 Alto | XS | — |
| 3 | **CRIT-2 (J1+J2+J7)** — Capturar Message-ID outbound, inyectar In-Reply-To/References, persistir en comments | 🔴 Crítico | M | ALT-3 (UI árbol Fase 1) |
| 4 | **CRIT-3 (K3+K4+K5)** — UNION en `isEmailInTicketRecipients` con `ticket_comments.email_to/cc` | 🔴 Crítico | S | — |
| 5 | **CRIT-4 (F1+F2+G1)** — Procesar inline images con `is_inline=true`/`content_id`; reescribir `cid:` antes del sanitize | 🔴 Crítico | M | ALT-3 (UI árbol Fase 2 informativa) |
| 6 | **ALT-2 (A3)** — Corregir `withinReattachWindow` para tickets abiertos | 🟠 Alto | XS | — |
| 7 | **MED-4 (J5)** — Añadir `Re:` a templates de respuesta | 🟡 Medio | XS | — |
| 8 | **MED-3 (J4)** — Reemplazar stamp existente en vez de concatenar | 🟡 Medio | XS | — |
| 9 | **MED-1 (I4)** — Detectar rollback no-excepción y reportar éxito parcial | 🟡 Medio | XS | — |
| 10 | **MED-5 (G2) + BAJ-6 (G4)** — Filtro de tracking pixels + blocklist de googleusercontent | 🟡 Medio | S | — |
| 11 | **ALT-3 Fase 2** — Chip "En respuesta a" + botón "Responder" en cada `_thread_message` | 🟠 Alto | S | (3) |
| 12 | **ALT-3 Fase 3** — Árbol completo con `ThreadTreeHelper` | 🟠 Alto | L | (3), (5), cobertura datos |
| 13 | Resto de bajos (BAJ-1..5, BAJ-7) | 🟢 Bajo | XS-S | — |

**Recomendación de orden de implementación**: 1 → 2 → 6 (todos XS, alto impacto, sin dependencias) → 4 (S, alto impacto) → 3 (M, desbloquea árbol) → 5 (M, desbloquea visualización completa inline) → 7-10 → 11 → 12 → 13.

---

## 9. Datos del audit

- **Agentes lanzados (5 en paralelo):**
  - Bloque A + C + L → `acc:bug-hunter` (parsing & detection)
  - Bloque D + E + K → `acc:business-logic-analyst` (To/CC flow)
  - Bloque F + G → `acc:bug-hunter` (inline images & URL rewriting)
  - Bloque I + J → `acc:business-logic-analyst` (multi-action + outbound threading)
  - Bloque B → `acc:business-logic-analyst` (thread tree UI design)
- **Duración total:** ~3.5 min (paralelo).
- **Tokens consumidos:** ~446k.
- **`agentId` (conservados por si se necesita follow-up):**
  - A: `ad9fcd1fc32ef896f`
  - B (To/CC): `ab3f1764de264083d`
  - C: `af3183f2411c96668`
  - D: `ad74f042218fca031`
  - E: `accddc644f83493e1`

---

## 10. Cambios en este documento

| Fecha | Cambio | Autor |
|-------|--------|-------|
| 2026-05-22 | Versión inicial — 4 críticos, 3 altos, 6 medios, 7 bajos, 10 verificados OK | Audit multi-agente |
