# n8n WhatsApp Audit · Fase 2 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Spec:** `docs/superpowers/specs/2026-05-19-n8n-whatsapp-audit-fase-2-design.md`

**Goal:** Dejar el bot n8n WhatsApp y el sub-flujo de Auto Tagging en producción, conectados a los endpoints de Fase 1, robustos frente a reintentos de Meta y carreras del mismo usuario, con cobertura de smoke tests integrada Fase 1+2.

**Architecture:** Dos cambios paralelos: (a) extensión backend que acepta `content_base64` además de `url` en attachments — necesario porque la media de Meta requiere Bearer que vive en n8n; (b) split del workflow monolítico en dos (`Mesa de Ayuda - WhatsApp Bot` + `Mesa de Ayuda - Auto Tagging`), reemplazando el path de email y el INSERT directo por llamadas HTTP a los webhooks de Fase 1, más idempotencia/lock/retry/error-handling encima.

**Tech Stack:** PHP 8.5+, CakePHP 5.x, PHPUnit 12 (pure-unit bootstrap), n8n (via `mcp__claude_ai_n8n__*` MCP tools), Meta Cloud API (`graph.facebook.com/v24.0`) para el bot, Redis 7 para sesión + idempotencia + lock, Groq (`gpt-oss-120b`) para tagging.

**Testing constraint (heredado de Fase 1):** Backend bootstrap es pure-unit (sin DB, sin fixtures). Tests del Task 1 son unit-only contra el DTO y el service. End-to-end se valida con smoke tests bash + un workflow n8n de smoke.

**n8n discipline (mandatory):** Cada modificación al workflow sigue el flujo del SDK:
1. `mcp__claude_ai_n8n__get_sdk_reference` (sólo primera vez por sesión)
2. `mcp__claude_ai_n8n__search_nodes` + `get_node_types` para los nodos nuevos
3. Construir el código del workflow
4. `mcp__claude_ai_n8n__validate_workflow` — repetir hasta verde
5. `mcp__claude_ai_n8n__update_workflow` (modificación) o `create_workflow_from_code` (nuevo)
6. `mcp__claude_ai_n8n__get_workflow_details` para confirmar el estado post-cambio

Subagentes que toquen n8n DEBEN leer el SDK reference primero. No improvisar tipos de nodo.

---

## File Structure

| Acción | Archivo / Recurso | Responsabilidad |
|---|---|---|
| Modify | `CLAUDE.md` líneas 92-95 | Corrige nota errónea sobre Evolution API |
| Modify | `docs/audits/2026-05-18-n8n-whatsapp-workflow-audit.md` | Corrige cierre del punto #9 |
| Modify | `src/Service/Dto/WhatsappIngestPayloadAttachment.php` | XOR url/content_base64 + decodificación validada |
| Modify | `src/Service/TicketIngestionService.php` (método `downloadAndStoreWhatsappAttachment`) | Branch base64 vs url |
| Modify | `tests/TestCase/Service/Dto/WhatsappIngestPayloadTest.php` | +4 tests para content_base64 |
| Modify | `tests/smoke/whatsapp_import.sh` | +1 caso con `content_base64` |
| Create | `docs/operations/whatsapp-bot-smoke.md` | Checklist de 7 casos manuales |
| Create | `docs/operations/n8n/bot-workflow.json` | Dump del workflow del bot post-cambios |
| Create | `docs/operations/n8n/auto-tagging-workflow.json` | Dump del workflow de tagging |
| Create | `docs/operations/n8n/smoke-tests-workflow.json` | Dump del workflow de smoke n8n |
| Modify | Workflow `YrY1cuaU5YobAUGu` (n8n) | Renombrar a `WhatsApp Bot` + reestructurar |
| Create | Nuevo workflow `Mesa de Ayuda - Auto Tagging` (n8n) | Sub-flujo de tagging extraído |
| Create | Nuevo workflow `Mesa de Ayuda - Smoke Tests` (n8n) | Workflow de smoke programático |

Comprobación PHP-FPM `post_max_size` es manual (Task 2). El usuario aplica el cambio en docker-compose o php.ini según ambiente.

---

## Task 0: Corrección documental (CLAUDE.md + audit nota #9)

**Files:**
- Modify: `CLAUDE.md` líneas 92-95
- Modify: `docs/audits/2026-05-18-n8n-whatsapp-workflow-audit.md` (cierre del #9)

- [ ] **Step 1: Reemplazar nota en CLAUDE.md**

Lee primero las líneas 92-95 con: `Read CLAUDE.md offset=85 limit=15`.

Reemplaza el bloque actual:

```markdown
**WhatsApp = Evolution API (canónica).** Cualquier referencia a Meta Cloud
API (`graph.facebook.com`) en n8n es deuda heredada y está agendada para
migrar en la Fase 2 del audit 2026-05-18 (ver
`docs/superpowers/specs/2026-05-19-n8n-whatsapp-audit-fase-1-design.md` §5).
```

Por:

```markdown
**WhatsApp: dos integraciones por diseño.**
- **Bot WhatsApp (inbound + outbound conversacional)** → Meta Cloud API (`graph.facebook.com`), gestionado en n8n.
- **Notificaciones outbound de ticket creado al equipo de soporte** → Evolution API, gestionado en backend (`WhatsappService::sendNewEntityNotification`).

Cada API tiene su propio caso de uso y credenciales en `system_settings`.
```

- [ ] **Step 2: Corregir cierre #9 en el audit**

Lee primero `docs/audits/2026-05-18-n8n-whatsapp-workflow-audit.md` y busca dónde marca el punto #9 como resuelto. Es la nota añadida en commit `8e55400` (Task 8 de Fase 1).

Reemplaza la nota "9 ✅" para reflejar coexistencia en vez de migración:

```markdown
✅ 9 — Coexistencia documentada (no migración): Bot WhatsApp usa Meta Cloud API en n8n; notificaciones de ticket usan Evolution API en backend. Ver `docs/superpowers/specs/2026-05-19-n8n-whatsapp-audit-fase-2-design.md` §0.
```

Si la marca actual es diferente (por ejemplo `✅ #2, #3, #9 — resueltos en Fase 1`), reescribir esa entrada para separar #9 con el texto de coexistencia, manteniendo #2 y #3 como resueltos en Fase 1.

- [ ] **Step 3: Commit**

```bash
git add CLAUDE.md docs/audits/2026-05-18-n8n-whatsapp-workflow-audit.md
git commit -m "docs: aclara coexistencia Meta Cloud API (bot) + Evolution API (notif)"
```

---

## Task 1: Backend — `content_base64` en WhatsappIngestPayloadAttachment (TDD)

**Files:**
- Modify: `src/Service/Dto/WhatsappIngestPayloadAttachment.php`
- Modify: `src/Service/TicketIngestionService.php` (método `downloadAndStoreWhatsappAttachment`)
- Modify: `tests/TestCase/Service/Dto/WhatsappIngestPayloadTest.php`

### Step 1: Escribir tests que fallan

Edita `tests/TestCase/Service/Dto/WhatsappIngestPayloadTest.php`. Añade estos 4 tests al final de la clase:

```php
    public function testAttachmentBase64HappyPath(): void
    {
        $raw = $this->validRaw();
        $content = 'hello world';  // 11 bytes
        $raw['attachments'] = [[
            'filename' => 'note.txt',
            'mime' => 'text/plain',
            'size' => strlen($content),
            'content_base64' => base64_encode($content),
        ]];

        $p = WhatsappIngestPayload::fromArray($raw);

        self::assertCount(1, $p->attachments);
        self::assertNull($p->attachments[0]->url);
        self::assertSame(base64_encode($content), $p->attachments[0]->contentBase64);
        self::assertSame('note.txt', $p->attachments[0]->filename);
        self::assertSame(11, $p->attachments[0]->size);
    }

    public function testAttachmentRejectsBothUrlAndBase64(): void
    {
        $this->expectException(InvalidWhatsappPayloadException::class);
        $this->expectExceptionMessageMatches("/exactly one of 'url' or 'content_base64'/");

        $raw = $this->validRaw();
        $raw['attachments'] = [[
            'filename' => 'a.jpg',
            'mime' => 'image/jpeg',
            'size' => 100,
            'url' => 'https://example.com/x',
            'content_base64' => base64_encode('x'),
        ]];

        WhatsappIngestPayload::fromArray($raw);
    }

    public function testAttachmentRejectsNeitherUrlNorBase64(): void
    {
        $this->expectException(InvalidWhatsappPayloadException::class);
        $this->expectExceptionMessageMatches("/exactly one of 'url' or 'content_base64'/");

        $raw = $this->validRaw();
        $raw['attachments'] = [[
            'filename' => 'a.jpg',
            'mime' => 'image/jpeg',
            'size' => 100,
        ]];

        WhatsappIngestPayload::fromArray($raw);
    }

    public function testAttachmentRejectsInvalidBase64(): void
    {
        $this->expectException(InvalidWhatsappPayloadException::class);
        $this->expectExceptionMessageMatches("/content_base64/");

        $raw = $this->validRaw();
        $raw['attachments'] = [[
            'filename' => 'a.jpg',
            'mime' => 'image/jpeg',
            'size' => 100,
            'content_base64' => '@@@not-valid-base64@@@',
        ]];

        WhatsappIngestPayload::fromArray($raw);
    }
```

NB: el primer test referencia `$p->attachments[0]->url` (espera `null`) y `$p->attachments[0]->contentBase64` (espera no-null). El DTO actual no tiene `contentBase64`. Step 2 lo modifica.

### Step 2: Correr los tests para confirmar fallos

Run: `vendor/bin/phpunit tests/TestCase/Service/Dto/WhatsappIngestPayloadTest.php`
Expected: 4 fallos nuevos por `Undefined property` o `required string` (porque `url` es required hoy). Los 13 tests existentes siguen verdes.

### Step 3: Refactorizar `WhatsappIngestPayloadAttachment` para XOR

Edita `src/Service/Dto/WhatsappIngestPayloadAttachment.php`. Reemplaza el contenido completo con:

```php
<?php
declare(strict_types=1);

namespace App\Service\Dto;

use App\Service\Exception\InvalidWhatsappPayloadException;

final class WhatsappIngestPayloadAttachment
{
    private const MAX_SIZE_BYTES = 10485760; // mirrors GenericAttachmentTrait::MAX_FILE_SIZE
    private const MAX_FILENAME = 255;

    /**
     * Use fromArray() — direct construction is reserved for the factory.
     *
     * Exactly one of $url / $contentBase64 is non-null; the other is null.
     * Enforced in fromArray().
     */
    private function __construct(
        public readonly ?string $url,
        public readonly ?string $contentBase64,
        public readonly string $filename,
        public readonly string $mime,
        public readonly int $size,
    ) {
    }

    /**
     * @param array<string, mixed> $raw
     */
    public static function fromArray(array $raw, int $index): self
    {
        $field = static fn(string $name): string => "field 'attachments[{$index}].{$name}'";

        // Required string fields (always).
        foreach (['filename', 'mime'] as $required) {
            if (!isset($raw[$required]) || !is_string($raw[$required]) || $raw[$required] === '') {
                throw new InvalidWhatsappPayloadException($field($required) . ': required string');
            }
        }

        // XOR: exactly one of url or content_base64 must be present and non-empty.
        $hasUrl = isset($raw['url']) && is_string($raw['url']) && $raw['url'] !== '';
        $hasB64 = isset($raw['content_base64']) && is_string($raw['content_base64']) && $raw['content_base64'] !== '';

        if ($hasUrl === $hasB64) {
            throw new InvalidWhatsappPayloadException(
                "field 'attachments[{$index}]': exactly one of 'url' or 'content_base64' is required",
            );
        }

        $url = null;
        $contentBase64 = null;

        if ($hasUrl) {
            $url = $raw['url'];
            if (!str_starts_with($url, 'https://')) {
                throw new InvalidWhatsappPayloadException($field('url') . ': must be https://');
            }
        } else {
            $contentBase64 = $raw['content_base64'];
            // strict=true rejects any non-base64 chars.
            if (base64_decode($contentBase64, true) === false) {
                throw new InvalidWhatsappPayloadException($field('content_base64') . ': not valid base64');
            }
        }

        $filename = $raw['filename'];
        if (
            $filename !== basename($filename)
            || str_contains($filename, '..')
            || str_contains($filename, '/')
            || str_contains($filename, '\\')
            || str_contains($filename, "\0")
        ) {
            throw new InvalidWhatsappPayloadException($field('filename') . ': path traversal not allowed');
        }
        if (mb_strlen($filename) > self::MAX_FILENAME) {
            throw new InvalidWhatsappPayloadException(
                $field('filename') . ': exceeds ' . self::MAX_FILENAME . ' chars',
            );
        }

        if (!isset($raw['size']) || !is_int($raw['size']) || $raw['size'] < 1) {
            throw new InvalidWhatsappPayloadException($field('size') . ': required positive int');
        }
        if ($raw['size'] > self::MAX_SIZE_BYTES) {
            throw new InvalidWhatsappPayloadException($field('size') . ': exceeds ' . self::MAX_SIZE_BYTES . ' bytes');
        }

        return new self($url, $contentBase64, $filename, $raw['mime'], $raw['size']);
    }
}
```

NB: `url` y `contentBase64` ahora son `?string`. El test happy-path original (`testAttachmentParsed`) que asserta `$p->attachments[0]->filename` sigue pasando. Asegúrate de actualizar también el test del null byte path traversal que asserta `url` — busca con `Grep` y verifica.

### Step 4: Modificar `downloadAndStoreWhatsappAttachment` para branch base64

Edita `src/Service/TicketIngestionService.php`. Reemplaza el contenido completo del método `downloadAndStoreWhatsappAttachment` (entre las líneas que lo abren y cierran). El método actual está alrededor de la línea 593-660. Reemplazar por:

```php
    /**
     * Persist a WhatsApp attachment, choosing source by payload shape:
     * - content_base64 → decode and save (Meta Cloud media path).
     * - url             → secure HTTPS download (generic external link).
     *
     * Failures are logged at warning and do NOT abort the ticket.
     */
    private function downloadAndStoreWhatsappAttachment(
        Ticket $ticket,
        WhatsappIngestPayloadAttachment $attachment,
        int $userId,
    ): void {
        try {
            $binary = $attachment->contentBase64 !== null
                ? $this->decodeAttachmentBase64($attachment, $ticket)
                : $this->fetchAttachmentFromUrl($attachment, $ticket);

            if ($binary === null) {
                return;
            }

            if (strlen($binary) !== $attachment->size) {
                Log::warning('WhatsApp attachment size mismatch', [
                    'declared' => $attachment->size,
                    'actual' => strlen($binary),
                    'ticket_id' => $ticket->id,
                ]);
            }

            $this->attachments->saveAttachmentFromBinary(
                entity: $ticket,
                filename: $attachment->filename,
                binaryContent: $binary,
                mimeType: $attachment->mime,
                commentId: null,
                userId: $userId,
            );
        } catch (Exception $e) {
            Log::warning('WhatsApp attachment processing failed', [
                'ticket_id' => $ticket->id,
                'filename' => $attachment->filename,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Decode an inline base64 attachment. Returns null on decode failure
     * (already validated in DTO, but defense-in-depth at IO boundary).
     */
    private function decodeAttachmentBase64(
        WhatsappIngestPayloadAttachment $attachment,
        Ticket $ticket,
    ): ?string {
        $binary = base64_decode((string)$attachment->contentBase64, true);
        if ($binary === false) {
            Log::warning('WhatsApp attachment base64 decode failed', [
                'ticket_id' => $ticket->id,
                'filename' => $attachment->filename,
            ]);

            return null;
        }

        return $binary;
    }

    /**
     * Download an attachment from an HTTPS URL via stream_context.
     * SecureHttpTrait exposes only secureCurlPost() today; for binary GET
     * we use file_get_contents restricted to https. Swap when a binary
     * helper exists.
     */
    private function fetchAttachmentFromUrl(
        WhatsappIngestPayloadAttachment $attachment,
        Ticket $ticket,
    ): ?string {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 15,
                'follow_location' => 0,
                'header' => "User-Agent: MesaDeAyuda-WhatsAppIngest/1.0\r\n",
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);

        $binary = @file_get_contents((string)$attachment->url, false, $context);
        if ($binary === false) {
            Log::warning('WhatsApp attachment download failed', [
                'url' => $attachment->url,
                'ticket_id' => $ticket->id,
            ]);

            return null;
        }

        return $binary;
    }
```

### Step 5: Correr todos los tests

Run: `vendor/bin/phpunit tests/TestCase/Service/Dto/WhatsappIngestPayloadTest.php tests/TestCase/Service/TicketIngestionServiceTest.php tests/TestCase/Model/Entity/TicketWhatsappFactoryTest.php tests/TestCase/Service/Util/LogMaskerTest.php tests/TestCase/Service/TicketPipelineServiceTest.php`
Expected: 37 PASS (33 previos + 4 nuevos). Si algún test previo falla (porque asume `url` es required), ajustar el test para incluir `'url' => 'https://...'` explícitamente.

### Step 6: Verificar style + static analysis

Run: `vendor/bin/phpcs --standard=vendor/cakephp/cakephp-codesniffer/CakePHP src/Service/Dto/WhatsappIngestPayloadAttachment.php src/Service/TicketIngestionService.php tests/TestCase/Service/Dto/WhatsappIngestPayloadTest.php`
Expected: clean (warnings pre-existentes `@file_get_contents` permanecen).

Run: `vendor/bin/phpstan analyse src/Service/Dto/WhatsappIngestPayloadAttachment.php src/Service/TicketIngestionService.php`
Expected: PASS.

### Step 7: Añadir caso base64 al smoke bash

Edita `tests/smoke/whatsapp_import.sh`. Añade ANTES del bloque "POST without token":

```bash
echo "→ POST with content_base64 attachment (expect 200 created:true)"
B64=$(printf '%s' 'Smoke binary content' | base64)
curl -sS -X POST "$HOST/webhooks/whatsapp/import" \
    -H "X-Webhook-Token: $WHATSAPP_TOKEN" \
    -H "Content-Type: application/json" \
    -d "{
        \"message_id\": \"wamid.smoke.b64.$(date +%s)\",
        \"phone_number\": \"+573001234567\",
        \"subject\": \"Smoke base64 ticket\",
        \"description\": \"Adjunto inline\",
        \"attachments\": [{
            \"filename\": \"smoke.txt\",
            \"mime\": \"text/plain\",
            \"size\": 20,
            \"content_base64\": \"$B64\"
        }]
    }" | tee /tmp/whatsapp_smoke_3.json
echo
```

### Step 8: Commit

```bash
git add src/Service/Dto/WhatsappIngestPayloadAttachment.php \
        src/Service/TicketIngestionService.php \
        tests/TestCase/Service/Dto/WhatsappIngestPayloadTest.php \
        tests/smoke/whatsapp_import.sh
git commit -m "feat(ingest): accept content_base64 attachments (Meta media path)"
```

---

## Task 2: Verificar `post_max_size` PHP-FPM permite ~14 MiB

**Files:**
- (lectura) `docker-compose.yml` / `docker/php/conf.d/uploads.ini` / similar

- [ ] **Step 1: Encontrar la configuración PHP actual**

Run: `Grep -rn "post_max_size\|upload_max_filesize" docker docker-compose.yml 2>/dev/null` (ajustar paths según hallazgos).

También: `Grep -rn "post_max_size\|upload_max_filesize" config 2>/dev/null`.

- [ ] **Step 2: Verificar el valor actual**

Si la búsqueda halla un archivo `.ini` o entrada en `docker-compose.yml` con `post_max_size`, anotar el valor. Si no hay configuración explícita, el default de PHP es 8M, lo cual es **insuficiente** porque base64 infla payloads (10 MiB binario → ~13.4 MiB JSON).

- [ ] **Step 3: Si el valor es <14M, proponer cambio (no aplicar todavía)**

Reportar al controlador del plan:
- Archivo donde vive la config.
- Valor actual vs requerido (≥ 14M).
- Comando o edición necesaria.

**Si el cambio se aprueba**, editar el archivo correspondiente (típicamente `docker/php/conf.d/uploads.ini` o equivalente):

```ini
post_max_size = 16M
upload_max_filesize = 14M
```

Y commit:
```bash
git add docker/php/conf.d/uploads.ini    # o el path real
git commit -m "fix(docker): raise post_max_size to 16M for content_base64 attachments"
```

Si la config ya permite ≥14M, el step se cierra con un commit vacío skip (no se cambia nada). Reportar como `Status: DONE_WITH_CONCERNS` y proceder a Task 3.

---

## Task 3: Crear nuevo workflow `Mesa de Ayuda - Auto Tagging` en n8n

**Recursos:**
- n8n MCP tools (`mcp__claude_ai_n8n__*`)
- Workflow ID actual del bot (a referenciar): `YrY1cuaU5YobAUGu`

### Step 1: Cargar el SDK reference

Run: `mcp__claude_ai_n8n__get_sdk_reference` con `section` por defecto.

Lee la respuesta completamente. NO empezar a generar workflow sin haberlo leído.

### Step 2: Discover nodes requeridos

Run: `mcp__claude_ai_n8n__search_nodes` con queries:
```
["webhook", "set", "groq", "openai chat", "structured output parser", "langchain basic chain", "code", "http request"]
```

Anotar los `nodeIds` exactos que se usarán.

### Step 3: Obtener type definitions

Run: `mcp__claude_ai_n8n__get_node_types` pasando TODOS los nodeIds de Step 2. Anotar los parámetros exactos.

### Step 4: Construir el código del workflow

Estructura objetivo (los IDs concretos vienen del Step 3):

```
[Webhook: POST /tagging]                  (webhook node)
    name: "Asignacion de Tags"
    method: POST
    path: "tagging"
    responseMode: onReceived
    responseData: "Workflow got started."
    │
    ▼
[Set: extract ticket data]
    name: "Set Data Webhook"
    assignments: ticket.id, ticket.subject, ticket.description, available_tags
    (mismas expresiones del workflow original)
    │
    ▼
[Code: format LLM prompt]
    name: "Formatear JSON"
    (mismo código del workflow original)
    │
    ▼
[Basic LLM Chain]                         (langchain.chainLlm)
    name: "Basic LLM Chain"
    system message: el mismo del workflow original
    hasOutputParser: true
    Retry On Fail: true
    Max Tries: 3
    Wait Between Tries: 1000
        ├─ Groq Chat Model (langchain.lmChatGroq)
        │      name: "Groq Chat Model"
        │      model: "openai/gpt-oss-120b"
        └─ Structured Output Parser
               name: "Structured Output Parser"
               schema: { ticket_id: 1, tag_ids: [1] }
    │
    ▼
[Code: Validar tag_ids]                  (NEW)
    name: "Validar tag_ids"
    code: (ver §5.3 del spec)
    │
    ▼
[HTTP Request: POST /webhooks/tickets/{id}/tags]   (NEW)
    name: "Aplicar Tags"
    method: POST
    url: "={{ $env.MESADEAYUDA_URL }}/webhooks/tickets/{{ $json.ticket_id }}/tags"
    sendHeaders: true
    headerParameters: X-Webhook-Token from credential
    sendBody: true
    bodyParameters: { tag_ids: $json.tag_ids, source: "auto" }
    Retry On Fail: true
    Max Tries: 3
    │
    ▼
[Code: Log resultado]                     (NEW)
    name: "Log Tagging Result"
    code: registra added/skipped_existing/skipped_unknown del response
```

NB: la URL del backend (`MESADEAYUDA_URL`) debe ser una variable de entorno n8n. Si no existe, documentarla como pre-requisito; el subagente NO crea la variable (es operacional).

NB: el token `X-Webhook-Token` viene de una **credencial n8n** tipo "Header Auth" o similar. Crear la credencial está fuera del workflow JSON; documentarla en el reporte.

### Step 5: Validar el workflow

Run: `mcp__claude_ai_n8n__validate_workflow` con el código completo.

Iterar hasta verde. Reportar cada error y la corrección aplicada.

### Step 6: Crear el workflow

Run: `mcp__claude_ai_n8n__create_workflow_from_code` con:
- Nombre: `Mesa de Ayuda - Auto Tagging`
- Descripción: `Asigna tags a tickets vía LLM (Groq) y POST a /webhooks/tickets/{id}/tags. Reemplaza el sub-flujo de tagging del workflow del bot.`

Anotar el `workflowId` retornado para los siguientes Tasks.

NB: el workflow se crea **inactive** por default. NO activarlo en este task.

### Step 7: Dump del JSON al repo

Run: `mcp__claude_ai_n8n__get_workflow_details` con el nuevo workflowId. Guarda el JSON completo en `docs/operations/n8n/auto-tagging-workflow.json` (crear el directorio si no existe).

### Step 8: Commit

```bash
mkdir -p docs/operations/n8n
# (guardar el JSON via Write tool)
git add docs/operations/n8n/auto-tagging-workflow.json
git commit -m "feat(n8n): nuevo workflow 'Mesa de Ayuda - Auto Tagging' (split del bot)"
```

---

## Task 4: Remover sub-flujo de tagging + renombrar workflow del bot

**Recursos:**
- Workflow `YrY1cuaU5YobAUGu`

### Step 1: Obtener el workflow actual

Run: `mcp__claude_ai_n8n__get_workflow_details` con `workflowId: YrY1cuaU5YobAUGu`.

### Step 2: Identificar nodos del sub-flujo de tagging a eliminar

Lista de nodos a remover (verificar IDs en el JSON actual):
- `Asignacion de Tags` (webhook) — id `d3a62e3e-f792-4809-9fdf-48d94332da8f`
- `Set Data Webhook` (set) — id `80b85ee6-9076-4bea-88ed-507f6d41e3f6`
- `Formatear JSON` (code) — id `e57b0524-8e7d-4c19-b445-b63ac64013d7`
- `Basic LLM Chain` — id `6a4ec1df-d04b-4709-ac1e-041503b85ceb`
- `Groq Chat Model` — id `685df489-4d5f-4bc8-8531-cbc45afcf173`
- `Structured Output Parser` — id `28c8c154-e963-4b67-8fca-5a040a3a2896`
- `Set Data Agent` (set) — id `af4ccb8f-6f04-48c1-a853-6f755d5920cb`
- `Formatear datos` (code) — id `d91ea35c-ca3c-4c0d-a160-b913b0fda94e`
- `Insert rows in a table` (mySql) — id `a2489717-2fa5-4948-89d2-bfd90206d343`

### Step 3: Construir el código del workflow modificado

Tomar el workflow actual, eliminar los 9 nodos listados, eliminar todas sus conexiones (`connections` keys que los referencien). También:
- Renombrar el workflow de `Mesa de Ayuda - COPC SA` → `Mesa de Ayuda - WhatsApp Bot`.

NO tocar otros nodos en este Task. Tasks 5-10 los modifican.

### Step 4: Validar

Run: `mcp__claude_ai_n8n__validate_workflow` con el código resultante. Iterar hasta verde.

### Step 5: Update

Run: `mcp__claude_ai_n8n__update_workflow` con `workflowId: YrY1cuaU5YobAUGu` y el código validado.

### Step 6: Verificar

Run: `mcp__claude_ai_n8n__get_workflow_details` y confirmar:
- `name === "Mesa de Ayuda - WhatsApp Bot"`
- Ninguno de los 9 nodos eliminados existe en `nodes[]`.
- Las conexiones del sub-flujo eliminado no existen en `connections`.
- Los otros nodos (FSM, WhatsApp Trigger, etc.) están intactos.

### Step 7: Commit (sólo dump JSON; los cambios reales viven en n8n)

```bash
# Guardar el dump actualizado via Write tool a docs/operations/n8n/bot-workflow.json
git add docs/operations/n8n/bot-workflow.json
git commit -m "refactor(n8n): split tagging sub-flow from bot workflow"
```

---

## Task 5: Reemplazar email path por HTTP POST en el bot (Crear Ticket)

**Recursos:**
- Workflow `YrY1cuaU5YobAUGu`

### Step 1: Identificar nodos del email path a eliminar

Lista actual (verificar IDs):
- `Aggregate` — id `4f6289f0-5c0c-43f5-bd52-ed8f4ca96f98`
- `If1` — id `af681529-07e8-432d-a32d-4ea5d347dfa4`
- `Descargar Archivos` — id `3860ce12-765c-4cb1-9818-cf45a5850ccb`
- `Enviar Ticket` (gmail) — id `c9c92f3c-3437-438f-8baa-4deb1daa724b`
- `Enviar Ticket con Archivos` (gmail) — id `f43cba1e-a605-4721-8397-9de586d63e39`
- `Parse Email Data` — id `ea115dd9-f972-4ce6-81ca-2cf8d0390301`
- `Parse Attachments Data` — id `fd30e958-3322-4e1f-8e9f-e985f0304124`
- `Send message` (WhatsApp ack post-email) — id `291142b3-e244-4bff-9c60-f91a693c22e3`

### Step 2: Diseñar la nueva cadena de creación

Reemplazar el path `Switch1 (branch "Crear Ticket") → Parse Email Data → Parse Attachments Data → If1 → ...` por:

```
[Switch1: branch "Crear Ticket"]   (existente)
  → [Item Lists: Split Out attachments]   (NEW)
       sessionData.attachments es string JSON. Code-Set previo lo parsea a array.
  → [HTTP Get Meta media URL]              (NEW; one per attachment)
       URL: ={{ "https://graph.facebook.com/v24.0/" + $json.id }}
       Auth: HTTP Bearer (Meta credential)
       Returns: { url, mime_type, file_size, ... }
  → [HTTP Get binary]                      (NEW)
       URL: ={{ $json.url }}
       Auth: HTTP Bearer (Meta credential)
       Response: binary, output property "data"
  → [Code: encode to base64]               (NEW)
       Devuelve { filename, mime, size, content_base64 }
  → [Aggregate: collect all attachments]   (NEW; or reuse existing Aggregate by renaming)
  → [Code: Build import payload]           (NEW)
       Construye body completo:
       {
         message_id, phone_number, contact_name,
         subject, description, attachments: [...]
       }
  → [HTTP POST /webhooks/whatsapp/import]  (NEW)
       url: ={{ $env.MESADEAYUDA_URL }}/webhooks/whatsapp/import
       headers: X-Webhook-Token (from credential)
       body: $json
       Retry On Fail: 3 tries, 1s wait
  → [If: $statusCode === 200 && $json.ok === true]
       true →
         [Send WhatsApp: success message with ticket_number]
                URL: ={{ $('Parse Data Whatsapp').item.json.whatsappApiUrl }}
                body: WhatsApp text message
                texto: "✅ Listo. Tu ticket #{{ $json.ticket_number }} fue creado."
            → [Redis Delete Session]
       false → [→ Sub-flujo "Notificar Error al Usuario" del Task 10]
```

### Step 3: Detalle del Code "Build import payload"

```js
// Code: Build import payload
const sessionData = $('Redis Update Session').first().json.sessionData;
const parsedMsg = $('Parse Data Whatsapp').first().json;

// Aggregate retorna { data: [ {filename, mime, size, content_base64}, ... ] }
const attachments = $input.first().json.data ?? [];

return [{
  json: {
    message_id: parsedMsg.messageId,
    phone_number: sessionData.phoneNumber.startsWith('+')
      ? sessionData.phoneNumber
      : '+' + sessionData.phoneNumber,
    contact_name: sessionData.userName,
    subject: sessionData.subject,
    description: sessionData.description,
    attachments: attachments,
  }
}];
```

### Step 4: Construir el código del workflow

Tomar el workflow del estado post-Task 4. Aplicar:
- Eliminar los 8 nodos listados en Step 1.
- Agregar los nodos nuevos del Step 2.
- Conectar `Switch1 → Item Lists → HTTP Get URL → HTTP Get binary → Code base64 → Aggregate → Code Build → HTTP POST → If → Send WhatsApp / Notificar Error`.

### Step 5: Validar

Run: `mcp__claude_ai_n8n__validate_workflow`. Iterar.

### Step 6: Update

Run: `mcp__claude_ai_n8n__update_workflow`.

### Step 7: Verificar

Run: `mcp__claude_ai_n8n__get_workflow_details`. Confirmar:
- Los 8 nodos del email path NO existen.
- Los nuevos nodos del HTTP path SÍ existen y están conectados al `Switch1`.

### Step 8: Commit del dump

```bash
git add docs/operations/n8n/bot-workflow.json
git commit -m "feat(n8n): bot crea ticket via POST /webhooks/whatsapp/import (Meta media base64)"
```

---

## Task 6: Eliminar nodos muertos del bot

**Recursos:** Workflow `YrY1cuaU5YobAUGu`

### Step 1: Identificar nodos a eliminar

- `OpenAI` (disabled) — id `effd2210-e7dc-4dad-a246-c45d82083e1c`
- `Asignación de Agente` (webhook disabled) — id `04b97a91-aba1-4e45-aade-9ff58c5fb2be`
- `Set Data Webhook1` (disabled) — id `cc01246c-62dd-46c1-aba0-45f50f4e68c7`

### Step 2: Obtener el workflow actual

Run: `mcp__claude_ai_n8n__get_workflow_details` con `workflowId: YrY1cuaU5YobAUGu`.

### Step 3: Construir el código del workflow modificado

Tomar el JSON actual y:
- Eliminar los 3 nodos listados en Step 1 del array `nodes`.
- Eliminar cualquier conexión en `connections` que mencione cualquiera de los 3 nodos (clave o valor).
- Nada más.

### Step 4: Validar el workflow

Run: `mcp__claude_ai_n8n__validate_workflow` con el código resultante. Iterar hasta verde.

### Step 5: Update

Run: `mcp__claude_ai_n8n__update_workflow` con `workflowId: YrY1cuaU5YobAUGu`.

### Step 6: Verificar

Run: `mcp__claude_ai_n8n__get_workflow_details`. Confirmar que ninguno de los 3 nodos existe en `nodes[]` y que las conexiones del resto siguen intactas.

### Step 7: Re-dump del JSON

Guardar el JSON actualizado via Write a `docs/operations/n8n/bot-workflow.json`.

### Step 8: Commit

```bash
git add docs/operations/n8n/bot-workflow.json
git commit -m "chore(n8n): remove dead nodes (OpenAI/Asignación de Agente/Set Data Webhook1)"
```

---

## Task 7: Idempotencia por `message.id`

**Recursos:** Workflow `YrY1cuaU5YobAUGu`

### Step 1: Diseñar el nodo

Después de `Parse Data Whatsapp` y antes de `Redis Get Session`, insertar:

```
[Redis SET NX]
  name: "Redis Dedupe Message"
  operation: setIfNotExists (verificar nombre exacto en el SDK)
  key: ={{ "mesadeayuda:msg:" + $json.messageId }}
  value: "1"
  expire: true
  ttl: 86400
  │
  ▼
[If: lock acquired]
  name: "If Message Already Processed"
  condition: ={{ $json.success === false || $json.value === null }}  (verificar shape de salida del nodo Redis)
  ├─ true (already processed) → [NoOp exit]
  └─ false (first time) → [Redis Get Session]   (flujo existente)
```

NB: La forma exacta de detectar "ya existía" depende de la versión del nodo Redis n8n. Verificar con `mcp__claude_ai_n8n__get_node_types` para `n8n-nodes-base.redis` y leer `setIfNotExists` operation docs. La condición debe diferenciar "se escribió ahora" vs "ya estaba".

### Step 2: Build, validate, update, verify

### Step 3: Commit

```bash
git add docs/operations/n8n/bot-workflow.json
git commit -m "feat(n8n): idempotency by message.id (24h Redis SET NX)"
```

---

## Task 8: Lock por `phoneNumber`

**Recursos:** Workflow `YrY1cuaU5YobAUGu`

### Step 1: Diseñar nodos

Después del idempotency check (Task 7), antes de `Redis Get Session`:

```
[Redis SET NX EX]
  name: "Redis Lock Phone"
  operation: setIfNotExists
  key: ={{ "mesadeayuda:lock:" + $('Parse Data Whatsapp').first().json.phoneNumber }}
  value: ={{ $('Parse Data Whatsapp').first().json.messageId }}
  expire: true
  ttl: 60
  │
  ▼
[If: lock acquired]
  ├─ true (no había lock previo) → [Redis Get Session]
  └─ false (otro proceso tiene el lock) →
       [HTTP POST Meta: enviar texto "procesando, espera..."]
       → [NoOp exit]
```

Y en CADA path de salida final del bot (confirm-success, cancel, error), AÑADIR antes del NoOp:

```
[Redis Delete: lock]
  operation: delete
  key: ={{ "mesadeayuda:lock:" + $('Parse Data Whatsapp').first().json.phoneNumber }}
```

Es decir: liberar lock cuando termina la conversación con éxito, cancelación o error. Si el workflow muere de hambre, el TTL=60s lo libera.

### Step 2: Build, validate, update, verify

Cuidado: este task toca varios path de salida del bot. Asegurar que ningún path olvida el `Redis Delete lock`. Lista de paths actuales (post-Task 5):
- Confirm path → Send WhatsApp success → Redis Delete Session → ... aquí poner Delete lock
- Cancel path → Send WhatsApp cancel → Redis Delete Session → ... aquí poner Delete lock
- Error path (Task 5 ramifica al sub-flujo Notificar Error) → ese sub-flujo del Task 10 incluirá el Delete lock

### Step 3: Commit

```bash
git add docs/operations/n8n/bot-workflow.json
git commit -m "feat(n8n): per-phoneNumber Redis lock with 60s TTL safety net"
```

---

## Task 9: Validación tag_ids + retry Groq en Auto Tagging

**Recursos:** Workflow del Task 3 (Auto Tagging)

### Step 1: Verificar el state actual

El Task 3 ya creó el workflow con el nodo `Code: Validar tag_ids` y `Basic LLM Chain` con `Retry On Fail` configurado. Este Task verifica que ambos estén bien configurados y añade refinamientos.

Run: `mcp__claude_ai_n8n__get_workflow_details` con el workflowId del Auto Tagging.

### Step 2: Verificar `Code: Validar tag_ids`

Confirmar que el código del nodo es exactamente:

```js
const llmOutput = $input.first().json.output;
const available = $('Set Data Webhook').first().json.available_tags;
const availableIds = (available || []).map(t => Number(t.id));

const requested = (llmOutput.tag_ids || []).map(Number);
const valid = requested.filter(id => availableIds.includes(id));
const dropped = requested.filter(id => !availableIds.includes(id));

if (dropped.length > 0) {
  console.warn('LLM hallucinated tag_ids', { dropped, ticket_id: llmOutput.ticket_id });
}

return [{ json: {
  ticket_id: Number(llmOutput.ticket_id),
  tag_ids: valid,
}}];
```

Si difiere, actualizar.

### Step 3: Verificar retry config en `Basic LLM Chain`

Confirmar parámetros:
- `retryOnFail`: true
- `maxTries`: 3
- `waitBetweenTries`: 1000

Si difieren, actualizar.

### Step 4: Build, validate, update, verify

### Step 5: Re-dump y commit

```bash
git add docs/operations/n8n/auto-tagging-workflow.json
git commit -m "chore(n8n): verify tag_ids validation + Groq retry config"
```

---

## Task 10: Sub-flujo "Notificar Error al Usuario"

**Recursos:** Workflow `YrY1cuaU5YobAUGu` (bot)

### Step 1: Diseñar el sub-flujo (inline en el bot, no es un workflow separado)

Crear un sub-grafo dentro del workflow del bot, sin trigger (se invoca por conexión desde puntos de error):

```
[Code: Build error message]
  name: "Build Error Message"
  code:
    const phone = $('Parse Data Whatsapp').first().json.phoneNumber;
    const reason = $input.first().json.error?.message ?? 'unknown';
    return [{ json: {
      whatsappPayload: {
        messaging_product: "whatsapp",
        recipient_type: "individual",
        to: phone,
        type: "text",
        text: {
          preview_url: false,
          body: "⚠️ Ups, tuvimos un problema procesando tu solicitud.\n\n" +
                "Por favor reintenta en unos minutos.\n\n" +
                "Si el problema persiste, contacta a soporte.",
        },
      },
      reason: reason,
    }}];
  │
  ▼
[HTTP POST Meta: Enviar error message]
  url: ={{ $('Parse Data Whatsapp').item.json.whatsappApiUrl }}
  body: $json.whatsappPayload
  │
  ▼
[Code: Log structured]
  name: "Log Error"
  code: Log error con phone (masked), reason, sessionState
  │
  ▼
[Redis Delete Session]
  key: ={{ "mesadeayuda:session:" + $('Parse Data Whatsapp').first().json.phoneNumber }}
  │
  ▼
[Redis Delete Lock]
  key: ={{ "mesadeayuda:lock:" + $('Parse Data Whatsapp').first().json.phoneNumber }}
```

### Step 2: Conectar puntos de error al sub-flujo

Para CADA nodo HTTP crítico (HTTP POST Meta media URL, HTTP POST `/webhooks/whatsapp/import`, etc.):
- Activar "Continue On Fail" en el nodo.
- Conectar el output a un nodo `If: was error?` que detecta el error.
- Si true → conectar al `Code: Build error message`.

### Step 3: Build, validate, update, verify

### Step 4: Commit

```bash
git add docs/operations/n8n/bot-workflow.json
git commit -m "feat(n8n): sub-flow 'Notificar Error al Usuario' end-to-end"
```

---

## Task 11: Crear workflow `Mesa de Ayuda - Smoke Tests`

**Recursos:** n8n MCP

### Step 1: Diseñar el workflow

```
[Manual Trigger]
  → [Set: load test config]
       BACKEND_URL, WHATSAPP_TOKEN, TAGS_TOKEN, TICKET_ID
  → [HTTP POST whatsapp/import (happy with content_base64)]
       expected: 200, ok:true, created:true
  → [If assertion]
  → [HTTP POST whatsapp/import (same message_id)]
       expected: 200, ok:true, created:false
  → [If assertion]
  → [HTTP POST tickets/{id}/tags (mix valid + invalid)]
       expected: 200, skipped_unknown non-empty
  → [If assertion]
  → [HTTP POST tickets/9999999/tags]
       expected: 404
  → [If assertion]
  → [HTTP POST whatsapp/import (no token)]
       expected: 401
  → [If assertion]
  → [Code: summary log]
```

Cada "If assertion" tiene branch true (sigue) y false (log error + NoOp). Output final: un Code que loguea resumen de los 5 casos.

### Step 2: Construir, validar, crear

Run: `mcp__claude_ai_n8n__create_workflow_from_code`:
- Nombre: `Mesa de Ayuda - Smoke Tests`
- Descripción: `Smoke tests programáticos para los endpoints de Fase 1 (whatsapp/import + tickets/{id}/tags). Trigger manual.`

Permanece `active: false` (manual trigger only).

### Step 3: Dump al repo + commit

```bash
# Write the JSON dump
git add docs/operations/n8n/smoke-tests-workflow.json
git commit -m "feat(n8n): workflow 'Mesa de Ayuda - Smoke Tests' (manual trigger)"
```

---

## Task 12: Docs operacionales `whatsapp-bot-smoke.md`

**Files:**
- Create: `docs/operations/whatsapp-bot-smoke.md`

### Step 1: Crear el documento

```markdown
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
  - Respuesta del bot incluye `ticket_number` real.
  - Ticket aparece en `/` con `channel=whatsapp`, `whatsapp_message_id` poblado.

- [ ] **Caso 2 — Happy path con archivo.** Igual al caso 1 pero adjunta foto en el paso de archivos. Verifica:
  - Attachment guardado bajo `webroot/uploads/attachments/{ticket_number}/`.
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
```

### Step 2: Commit

```bash
git add docs/operations/whatsapp-bot-smoke.md
git commit -m "docs(operations): smoke checklist 7 casos manuales bot WhatsApp"
```

---

## Task 13: Re-dump JSONs finales + commit consolidado

**Files:**
- Modify (re-dump): `docs/operations/n8n/bot-workflow.json`
- Modify (re-dump): `docs/operations/n8n/auto-tagging-workflow.json`
- Modify (re-dump): `docs/operations/n8n/smoke-tests-workflow.json`

### Step 1: Re-dump cada workflow

Para cada workflowId (bot, auto-tagging, smoke-tests):
- Run `mcp__claude_ai_n8n__get_workflow_details`.
- Guardar el JSON completo en el archivo correspondiente.

### Step 2: Verificar diff vs commits previos

Run: `git diff docs/operations/n8n/`

Si hay diff (probable, los tasks anteriores commitearon dumps intermedios), se confirma que el snapshot final está sincronizado con n8n.

### Step 3: Commit

```bash
git add docs/operations/n8n/
git commit -m "docs(n8n): snapshot final de los 3 workflows tras Fase 2"
```

---

## Task 14: Activación (operacional, no produce commit)

**Files:** Ninguno

Este Task NO produce código ni commit. Es un checklist operacional.

### Step 1: Validación pre-activación

Run los smokes:

```bash
WHATSAPP_TOKEN=<token> ./tests/smoke/whatsapp_import.sh
TAGS_TOKEN=<token> TICKET_ID=<id_real> ./tests/smoke/tickets_tags.sh
```

Ejecutar manualmente el workflow `Mesa de Ayuda - Smoke Tests` desde n8n UI (Manual Trigger). Verificar 5/5 aserts verdes.

### Step 2: Activar workflows en n8n UI

- `Mesa de Ayuda - WhatsApp Bot`: toggle active.
- `Mesa de Ayuda - Auto Tagging`: toggle active.
- `Mesa de Ayuda - Smoke Tests`: **mantener inactive** (sólo trigger manual).

### Step 3: Ejecutar checklist manual

Seguir `docs/operations/whatsapp-bot-smoke.md` (Task 12). Marcar cada caso completo.

### Step 4: Reportar

Status: DONE | ROLLED_BACK | partial.

Si algún caso del checklist falla:
- Capturar logs n8n + backend.
- Decidir: rollback (volver a `active: false` ambos workflows) o fix forward (crear ticket de seguimiento).

---

## Criterios de éxito (verificar al final)

- [ ] `composer test` verde (al menos 37 PASS tras Task 1).
- [ ] `composer cs-check` clean en archivos modificados.
- [ ] `vendor/bin/phpstan analyse src` sin nuevos errores.
- [ ] `tests/smoke/whatsapp_import.sh` con caso base64 retorna 200.
- [ ] Workflow `Auto Tagging` creado y validado en n8n.
- [ ] Workflow `WhatsApp Bot` reestructurado (≤28 nodos, sin email path, sin INSERT directo, con idempotencia + lock + error handling).
- [ ] Workflow `Smoke Tests` ejecutado manualmente con 5/5 aserts verdes.
- [ ] `docs/operations/whatsapp-bot-smoke.md` con los 7 casos completos.
- [ ] `docs/operations/n8n/*.json` snapshots commited.
- [ ] `CLAUDE.md` corregida.
- [ ] Audit nota #9 corregida.
- [ ] Workflows activados en producción.

## Notas para subagentes

- **NO push de la rama**. El controlador decide cuándo pushear.
- **NO activar workflows en n8n** salvo en Task 14 (operacional).
- **Pre-condición de cada Task n8n**: verificar `git branch --show-current` antes de commitear. Si cae en `main` por interferencia externa, hacer `git checkout feature/...` antes de commitear (con stash si hay dirty files).
- Los Tasks de n8n NO son TDD (no aplica para workflow JSON). En su lugar usan el ciclo `validate → update → get_workflow_details para verificar`.
- Si `validate_workflow` falla repetidamente con el mismo error tras 3 iteraciones, status BLOCKED con el mensaje exacto.
