# Fase 2 audit — cierre de altos pendientes

**Fecha:** 2026-05-08
**Audit base:** `docs/audits/2026-05-07-architecture-audit.md`
**Fase previa:** `docs/superpowers/specs/2026-05-08-criticos-pendientes-auditoria-design.md` (cerró 3.2 / 3.3 / 3.5 / 4.6)
**Alcance:** hallazgos altos 4.1, 4.2, 4.3, 4.4, 4.5, 4.7, 4.8 + reconciliación documental de críticos cerrados de facto (3.1, 3.4, 3.6).

---

## 1. Objetivo

Cerrar los 7 hallazgos altos restantes del audit y dejar el documento del audit reconciliado con la realidad del código en disco.

## 2. Estado verificado al iniciar la fase

Al revisar el código contra el audit del 2026-05-07, varios críticos ya estaban cerrados de facto pero no anotados en el anexo:

| Hallazgo | Estado verificado en disco |
|---|---|
| 3.1 `src/Utility/` cajón de sastre | Carpeta no existe; `src/Constants/` poblado con `TicketConstants`, `RoleConstants`, `CacheConstants`, `SettingKeys`; `SettingsEncryptionTrait` reubicado en `src/Service/Traits/`. **Cerrado**. |
| 3.4 múltiples fuentes de verdad estados/prioridades | `TicketConstants` centraliza estados y prioridades; migration `ConsolidateLegacyTicketStatuses` consolidó a 4 estados (`nuevo`, `abierto`, `pendiente`, `resuelto`). Pendiente verificación de `StatusHelper`. **Cerrado salvo verificación**. |
| 3.6 `CLAUDE.md` desincronizado | `CLAUDE.md` actual describe los 6 traits reales y referencia constantes existentes. **Cerrado**. |
| 4.6 `src/Controller/Component/` vacío | Carpeta no existe. **Cerrado**. |

Pendientes 4.1–4.8 (sin 4.6) confirmados como abiertos por inspección de tamaños y contenido:

| Archivo | LOC | Hallazgo asociado |
|---|---|---|
| `src/Service/TicketService.php` | 1046 | 4.1, 4.3 |
| `src/Service/EmailTemplateRenderer.php` | 153 | 4.2 |
| `src/Service/Renderer/NotificationRenderer.php` | 136 | 4.2 |
| `src/View/Helper/StatusHelper.php` | 109 | 4.4 (HTML inline + datos de dominio duplicados) |
| `src/View/Helper/TicketHelper.php` | 26 | 4.4 (wrapper trivial) |
| `src/View/Cell/TicketsSidebarCell.php` | 68 | 4.5 (query inline) |
| `src/Service/SidebarCountsService.php` | 55 | 4.5 (destino del query) |
| `src/Service/SettingsService.php` | 110 | 4.8 (falta invalidación de cache) |

## 3. Decisiones tomadas en brainstorming

1. **Scope:** solo altos 4.1–4.8 (sin 4.6). Medios 5.1–5.7 quedan para fase 3.
2. **Orden:** primero los puntuales aislados (4.7, 4.8, 4.5, 4.4), luego los pesados estructurales (4.2, 4.1, 4.3). Reduce regresión y deja el entorno simplificado antes de tocar `TicketService`.
3. **Granularidad:** un solo spec (este) + un commit por hallazgo. Facilita revisión y rollback.
4. **Cierre documental:** paso 0 obligatorio para anexar al audit del 2026-05-07 los cierres de facto de 3.1, 3.4 y 3.6.
5. **4.1 (extracción moderada):** trocear `TicketService` en 3 servicios — `TicketIngestionService` (entrada Gmail/WhatsApp), `TicketNotificationService` (dispatch + render notificaciones) y `TicketService` que conserva pipeline + comments + attachments + tags + followers. Objetivo de tamaño: `TicketService` ≤ 600 LOC.
6. **Verificación:** smoke manual del flujo afectado + `composer cs-check` por commit. Sin introducir suite de tests automatizada en esta fase.

## 4. Plan de commits (8 commits, en orden)

### Commit 0 — Reconciliar audit doc

- Actualizar `docs/audits/2026-05-07-architecture-audit.md` (anexo o tabla resumen) marcando 3.1, 3.4 y 3.6 como cerrados con evidencia (rutas verificadas en disco).
- Verificar `src/View/Helper/StatusHelper.php`: si aún contiene constantes locales `PRIORITY_LABELS`, `TICKET_STATUS_LABELS`, etc. duplicadas con `TicketConstants`, dejar la limpieza para el commit 4 (4.4) y notarlo en el anexo.
- Mensaje: `docs(audit): close 3.1 / 3.4 / 3.6 (verified in code)`.

### Commit 1 — 4.7: Mover redirect OAuth fuera de Tickets

- Hoy un closure `specialRedirects` dentro del flujo de Tickets (`TicketServiceInitializerTrait` o `TicketListingTrait`) redirige `?code=…` a `Admin/Settings::gmailAuth`.
- Mover a una ruta dedicada en `config/routes.php`: `GET /oauth/gmail/callback` → `Admin/Settings::gmailAuth`.
- Eliminar el closure de Tickets.
- Actualizar la URL de redirect autorizada en Google Cloud Console (anotar el cambio en el commit message para que el operador lo reconfigure).
- Mensaje: `refactor(oauth): move Gmail callback to dedicated route — close audit 4.7`.

### Commit 2 — 4.8: Invalidar cache de `system_settings`

- En `SettingsService::set()` (y cualquier punto que persista settings) ejecutar `Cache::delete(CacheConstants::CACHE_SETTINGS, CacheConstants::CACHE_CONFIG)` tras la persistencia.
- Inspeccionar la elección de `CacheConstants::CACHE_CONFIG`: si apunta a `_cake_core_` (cache de bootstrap), documentar como deuda y dejar nota en el commit (no se cambia el config en este commit para evitar scope creep).
- Mensaje: `fix(settings): invalidate cache on persist — close audit 4.8`.

### Commit 3 — 4.5: Mover query inline de `TicketsSidebarCell` a `SidebarCountsService`

- Trasladar el cálculo de `agentStatusCounts` (find directo sobre `Tickets`) al servicio como método nuevo `getAgentStatusCounts(int $userId): array`.
- `TicketsSidebarCell` queda como wrapper de presentación que llama `getSidebarCounts` + `getAgentStatusCounts`.
- Mensaje: `refactor(sidebar): move agent counts query into SidebarCountsService — close audit 4.5`.

### Commit 4 — 4.4: Limpieza de Helpers

- `StatusHelper`: eliminar constantes locales duplicadas (`PRIORITY_LABELS`, `PRIORITY_COLORS`, `TICKET_STATUS_LABELS`, `TICKET_STATUS_COLORS` si existen). Leer todo de `TicketConstants`. Mover el HTML inline (`style="background-color: …; color: white; …"`) a un element reutilizable `templates/element/badge.php` con clases CSS — mantener clases Bootstrap para no cambiar visual.
- `TicketHelper`: eliminar la clase. Reemplazar las llamadas `$this->Ticket->getViewUrl($ticket)` en templates por `['action' => 'view', $ticket->id]` directo.
- Verificar que no queden referencias en `templates/`.
- Mensaje: `refactor(view): consolidate StatusHelper, drop trivial TicketHelper — close audit 4.4`.

### Commit 5 — 4.2: Decidir EmailTemplateRenderer vs NotificationRenderer

- Time-box de inspección: máximo 1 hora.
- Decisión a tomar y documentar en commit message:
  - **Opción A (capas):** `EmailTemplateRenderer` = template loader (carga tpl + interpola variables); `NotificationRenderer` = composer (decide qué template usar para cada evento de dominio, delega al loader).
  - **Opción B (consolidación):** absorber uno en el otro si la separación no aporta. El que sobrevive queda como única clase de render de notificaciones.
- Aplicar la decisión: o ajustar responsabilidades y eliminar duplicación, o eliminar la clase descartada y mover sus consumidores.
- Mensaje: `refactor(notifications): {layer|consolidate} renderers — close audit 4.2`.

### Commit 6 — 4.1: Trocear `TicketService` (extracción moderada)

- Crear `src/Service/TicketIngestionService.php`. Mover:
  - `createFromEmail` y métodos privados acoplados (parsing, mapeo de remitente, dedupe).
  - `addCommentFromEmail` y helpers asociados.
  - Helpers de attachments-from-email (si están encapsulados).
- Crear `src/Service/TicketNotificationService.php`. Mover:
  - `dispatchCreationNotifications`.
  - `dispatchUpdateNotifications`.
  - `sendResponseNotifications`.
  - Métodos privados de composición de payloads de notificación (sin tocar el `EmailTemplateRenderer` / `NotificationRenderer` que se decidieron en commit 5).
- `TicketService` conserva: pipeline (`changeStatus`, `assign`, `changePriority`), comments (creación normal vía UI), attachments (UI), tags, followers.
- Ajustar consumidores en `src/Controller/Trait/Ticket*Trait.php` y en `WebhooksController` / `ImportGmailCommand` para instanciar el nuevo servicio según corresponda.
- Si el commit crece demasiado (más de ~20 archivos tocados), dividir en 6a (extraer Notification) y 6b (extraer Ingestion). Decisión durante la implementación.
- Verificar al final: `wc -l src/Service/TicketService.php` ≤ 600.
- Mensaje: `refactor(ticket-service): extract Ingestion and Notification services — close audit 4.1`.

### Commit 7 — 4.3: Inyección de dependencias

- En `TicketService`, `TicketIngestionService`, `TicketNotificationService`: aceptar `EmailService` y `WhatsappService` como parámetros opcionales del constructor, estilo SGI (`?EmailService $emailService = null` → `$this->emailService = $emailService ?? new EmailService(...)`).
- No introducir interfaces nuevas (sin tests no hay valor de mocking aún).
- No introducir un DI container.
- Aplica a los tres servicios resultantes del commit 6: `TicketService`, `TicketIngestionService`, `TicketNotificationService`.
- Mensaje: `refactor(ticket-services): allow injection of Email/Whatsapp services — close audit 4.3`.

## 5. Verificación por commit

Cada commit cumple:

1. `composer cs-check` pasa (corregir con `composer cs-fix` si necesario).
2. Smoke manual del flujo afectado:

| Commit | Smoke manual mínimo |
|---|---|
| 0 | Lectura del audit doc actualizado; `git diff` revisable |
| 1 (4.7) | Iniciar OAuth en `/admin/settings`, completar callback, verificar token guardado y `system_settings.gmail_*` poblados |
| 2 (4.8) | Cambiar un setting en `/admin/settings`, recargar la página y verificar que el cambio se refleja sin reinicio del contenedor |
| 3 (4.5) | Cargar layout principal (`/`), verificar contadores de sidebar (totales + por agente) |
| 4 (4.4) | Listado de tickets (badges status + priority sin diff visual), vista de un ticket, verificar que ningún template referencia `TicketHelper` |
| 5 (4.2) | Crear ticket por UI; ejecutar `bin/cake import_gmail --max 5`; verificar email saliente y plantilla render |
| 6 (4.1) | `bin/cake import_gmail --max 5` (smoke ingestion completa); cambiar estado de un ticket vía UI; reasignar; comentar; verificar notificaciones |
| 7 (4.3) | Repetir smoke del 6 — sin regresión funcional |

## 6. Riesgos y mitigaciones

| Riesgo | Mitigación |
|---|---|
| Commit 6 (trocear servicio) rompe traits del controller | Extracción mecánica primero (mover métodos preservando firma), luego ajustar consumidores en el mismo commit. División en 6a/6b si crece |
| Commit 4 (mover HTML a element) cambia estilos visualmente | Conservar clases Bootstrap existentes; comparar render antes/después en una vista de prueba |
| Commit 1 (mover OAuth) rompe flujo Gmail callback | Probar callback completo en local antes de commitear; el operador debe actualizar la URL de redirect en Google Cloud Console — anotar en commit message |
| Commit 5 (decidir renderers) cae en parálisis de análisis | Time-box de 1 h. Si no hay decisión clara, ir por consolidación (opción B) |
| Commit 2 invalida caches activos en producción | Cambio aditivo (solo añade `Cache::delete`). Sin breaking |
| Commit 7 (DI) introduce parámetros nuevos en constructores que rompen llamadas existentes | Parámetros opcionales con default `null` + fallback a `new`. 100% retrocompatible |

## 7. Definition of done (criterios de éxito)

- 8 commits con mensajes que referencian su hallazgo.
- Audit doc anexa cierres de **3.1, 3.4, 3.6, 4.1, 4.2, 4.3, 4.4, 4.5, 4.7, 4.8** (10 ítems en total).
- `CLAUDE.md` del proyecto actualizado para reflejar:
  - `TicketIngestionService` y `TicketNotificationService` como servicios nuevos.
  - Decisión de capas tomada en commit 5 (renderer único o capas).
  - Eliminación de `TicketHelper`.
  - Ruta dedicada de OAuth Gmail callback.
- `composer cs-check` pasa sin errores en HEAD.
- `wc -l src/Service/TicketService.php` ≤ 600.
- Cero referencias a `App\View\Helper\TicketHelper` en `src/` y `templates/`.
- Cero queries inline a tablas de Tickets en `src/View/Cell/`.

## 8. Estimación

- Commits 0–4 (puntuales): ~1.5 sesiones (3–4 h).
- Commits 5–7 (pesados): ~2 sesiones (4–5 h).
- **Total:** ~3–4 sesiones de trabajo.

## 9. Fuera de alcance (explícito)

Lo siguiente NO se aborda en esta fase y queda para fase 3 o backlog:

- Crear `tests/` ni configurar PHPUnit.
- Introducir `src/Event/` ni domain events (medio 5.1).
- Introducir un DI container PSR-11.
- Crear interfaces para `EmailService` / `WhatsappService` (esperar a tener tests que justifiquen mocks).
- Auditar mass-assignment de `assignee_id` (medio 5.4).
- Inlineado o expansión de `SidebarCountsService` (medio 5.6).
- Auditoría de tipos de foreign keys (medio 5.7).
- Cambiar el cache config de `system_settings` (queda anotado como deuda en commit 2).
