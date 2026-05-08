# Diseño — Trocear `TicketService` + DI explícita (altos 4.1 y 4.3)

**Fecha:** 2026-05-08
**Auditoría origen:** `docs/audits/2026-05-07-architecture-audit.md` §4.1, §4.3
**Alcance:** `src/Service/TicketService.php` (1046 LOC, 21 métodos) y sus 2 callers.
**Riesgo:** Alto. Sin tests automatizados; smoke manual obligatorio.

---

## 1. Objetivo

1. Sustituir el god-service `TicketService` por **5 servicios cohesivos** alineados a dominios.
2. Sustituir la instanciación interna forzada (`new EmailService(...)`, `new WhatsappService(...)`) por **inyección por constructor con defaults**, estilo SGI (`?array $systemConfig = null` → `?Service $service = null`). Esto cierra el alto **4.3**.
3. Cero cambio funcional. Toda regla de dominio, log de historia, notificación, transición y persistencia debe comportarse idéntico desde el punto de vista del usuario.

## 2. Servicios resultantes

| Servicio | Responsabilidad | Métodos públicos | Métodos privados |
|---|---|---|---|
| `TicketIngestionService` | Crear tickets/comentarios desde fuentes externas (Gmail, WhatsApp futuro) | `createFromEmail`, `createCommentFromEmail` | `findOrCreateUser`, `isEmailInTicketRecipients`, `decodeEmailRecipients` |
| `TicketCommentService` | Comentarios manuales y sanitización HTML | `addComment` | `sanitizeHtml` |
| `TicketAttachmentService` | Subida y procesamiento de archivos adjuntos | `processEmailAttachments`, `saveUploadedFile` | (consume `GenericAttachmentTrait`) |
| `TicketPipelineService` | Cambios de estado, asignación, prioridad, tags, followers, response combinada | `assign`, `changeStatus`, `changePriority`, `addTag`, `removeTag`, `addFollower`, `handleResponse` | `buildResponseResult` |
| `TicketNotificationService` | Despacho de notificaciones email + WhatsApp + n8n | `dispatchCreationNotifications`, `dispatchUpdateNotifications`, `sendResponseNotifications` | — |

### Helpers compartidos

- **`Service/Traits/TicketHistoryLoggerTrait`** — encapsula `logHistory(Ticket, int $userId, string $field, ?string $old, ?string $new, string $description)`. Lo consumen `TicketCommentService`, `TicketPipelineService`, `TicketIngestionService`.
- **`Service/Traits/HtmlSanitizerTrait`** — encapsula `sanitizeHtml(string $html): string` (config HTMLPurifier). Lo consume `TicketCommentService` y `TicketIngestionService` (el body de email entrante también se sanitiza).

## 3. Dependencias entre servicios

```
TicketIngestionService
    ├── TicketCommentService           (para createCommentFromEmail si aplica)
    ├── TicketAttachmentService        (processEmailAttachments)
    └── TicketNotificationService      (dispatchCreationNotifications)

TicketPipelineService
    ├── TicketCommentService           (handleResponse llama addComment)
    ├── TicketAttachmentService        (handleResponse llama saveUploadedFile)
    └── TicketNotificationService      (dispatchUpdateNotifications, sendResponseNotifications)

TicketCommentService
    └── (independiente; usa logHistory via trait)

TicketAttachmentService
    └── (independiente; usa GenericAttachmentTrait)

TicketNotificationService
    ├── EmailService
    ├── WhatsappService
    └── N8nService (lazy)
```

## 4. Constructores con DI explícita

Patrón uniforme en los 5 servicios (mostrado para `TicketPipelineService`):

```php
public function __construct(
    ?array $systemConfig = null,
    ?TicketNotificationService $notifications = null,
    ?TicketCommentService $comments = null,
    ?TicketAttachmentService $attachments = null,
) {
    $this->systemConfig = $systemConfig;
    $this->notifications = $notifications ?? new TicketNotificationService($systemConfig);
    $this->comments = $comments ?? new TicketCommentService($systemConfig);
    $this->attachments = $attachments ?? new TicketAttachmentService();
}
```

Notas:
- El default por `new` interno **se conserva** para no romper callers actuales, igual que SGI. El cambio respecto al estado actual es que ahora **se acepta** el parámetro inyectable, lo cual habilita futuros tests y cierre de 4.3.
- `TicketNotificationService` mantiene la inicialización lazy de `N8nService` que ya existe.

## 5. Callers afectados

### 5.1 `src/Service/GmailImportService.php:48`

**Antes:**
```php
new TicketService(self::loadSystemSettings()),
```

**Después:**
```php
new TicketIngestionService(self::loadSystemSettings()),
```

Único método invocado: `createFromEmail` y `createCommentFromEmail`. Cambio mecánico.

### 5.2 `src/Controller/Trait/TicketServiceInitializerTrait.php`

**Antes:** una sola property `ticketService` cargada con `loadComponent('TicketService')` (componente PHP simple, no CakePHP Component) — actualmente se hace via `$this->ticketService = new TicketService(...)` en `initializeServices()`.

**Después:** cuatro properties tipadas en el `AppController`/trait:
```php
protected TicketPipelineService $ticketPipeline;
protected TicketCommentService $ticketComments;
protected TicketAttachmentService $ticketAttachments;
protected TicketNotificationService $ticketNotifications;
```

`TicketIngestionService` **no** se carga en controller (solo CLI/webhook).

### 5.3 Traits del controller

| Trait | Uso actual de `$this->ticketService->X()` | Property nueva |
|---|---|---|
| `TicketActionsTrait` | `addTag`, `removeTag`, `addFollower`, `addComment`, `assign`, `changeStatus`, `changePriority`, `handleResponse` | `ticketPipeline` (pipeline + delega comentarios y attachments internamente) |
| `TicketBulkTrait` | `assign`, `changePriority` (en bucle) | `ticketPipeline` |
| `TicketViewTrait` | `handleResponse` (si aplica) | `ticketPipeline` |

`addComment` independiente (no via `handleResponse`) → `ticketPipeline->addComment(...)` actúa de fachada o se llama `ticketComments->addComment(...)` directo. **Decisión:** llamar `ticketComments` directo para que cada controller property apunte al servicio canónico.

## 6. Plan de migración (orden seguro)

1. **Crear traits compartidos** (`TicketHistoryLoggerTrait`, `HtmlSanitizerTrait`). Sin callers todavía. Smoke: ninguno.
2. **Crear `TicketNotificationService`** con los 3 métodos despacho. Sin callers todavía.
3. **Crear `TicketAttachmentService`** con los 2 métodos. Sin callers todavía.
4. **Crear `TicketCommentService`** con `addComment`. Sin callers todavía.
5. **Crear `TicketPipelineService`** con todos los métodos pipeline + `handleResponse` que internamente delega a `TicketCommentService` y `TicketAttachmentService`. Sin callers todavía.
6. **Crear `TicketIngestionService`** con `createFromEmail`, `createCommentFromEmail`. Sin callers todavía.
7. **Migrar `GmailImportService`** → usa `TicketIngestionService`. **Smoke:** `bin/cake import_gmail --max 1` con un thread Gmail real (creación) y un reply (comentario). Verificar `ticket_history` y notificaciones.
8. **Migrar `TicketServiceInitializerTrait`** y los 3 traits del controller a las 4 properties nuevas. **Smoke completo de UI:**
   - Crear ticket manual (si UI lo permite) o reusar uno existente
   - assign / unassign
   - changeStatus en transición legal e ilegal (debe lanzar `InvalidStatusTransitionException` y mostrar flash de error)
   - changePriority
   - addComment con upload de archivo
   - addTag / removeTag
   - addFollower
   - bulkAssign / bulkChangePriority sobre 3 tickets
9. **Eliminar `TicketService`** una vez verificados los smokes. Buscar referencias finales: `grep -rn "TicketService" src/ config/ templates/`.

Cada paso es un commit independiente. Si un smoke falla, se revierte solo ese commit.

## 7. Riesgos y mitigaciones

| Riesgo | Mitigación |
|---|---|
| `handleResponse` orquesta 3 dominios → bug de transacción | Mantener llamadas en mismo orden; `TicketPipelineService` recibe `TicketCommentService` y `TicketAttachmentService` por constructor; no introducir transacciones explícitas nuevas (el comportamiento actual no las tiene). |
| `logHistory` se invoca desde varios servicios → divergencia | Trait único `TicketHistoryLoggerTrait`; firma idéntica a la actual. |
| Falta de tests → regresión silenciosa | Smoke manual exhaustivo en pasos 7 y 8; cada commit aislado para revert quirúrgico. |
| `GenericAttachmentTrait` en `TicketAttachmentService` → cambia el `$this` host del trait | Hoy el trait lo usan `TicketsController`, `EmailService` y `TicketService`. Verificar al implementar que el trait no asuma propiedades específicas de `TicketService`; los otros dos hosts sugieren que es portable. |
| `N8nService` lazy → si vive en `TicketNotificationService`, los métodos pipeline ya no lo tocan directo | Correcto por diseño; `TicketPipelineService` no debe instanciar `N8nService`. |
| Controller cargando 4 properties → `initializeServices()` más verboso | Aceptable; las properties tipadas son un beneficio (era array antes). |

## 8. Cierre del alto 4.3

La nueva firma `__construct(?array $systemConfig, ?EmailService $email, ?WhatsappService $whatsapp, ?N8nService $n8n, ?Otros)` cumple el patrón SGI. Cuando exista `tests/`, los servicios serán mockables sin cambios. Documentar en `CLAUDE.md` el patrón.

## 9. Actualización de documentación al cierre

Al terminar:
- `CLAUDE.md` — sección "src/Service/" enumerar los 5 servicios nuevos y eliminar mención a `TicketService` monolítico.
- `docs/audits/2026-05-07-architecture-audit.md` — Anexo 4 (cierre 4.1 + 4.3).

## 10. Fuera de alcance

- No se introducen Domain Events (queda como medio 5.1).
- No se crea `tests/` (queda como medio 5.2).
- No se cambia el modelo de datos.
- No se toca `TicketBulkTrait` más allá de actualizar property names.
- No se renombra `EmailTemplateRenderer`/`NotificationRenderer` (cerrado en fase 2).
