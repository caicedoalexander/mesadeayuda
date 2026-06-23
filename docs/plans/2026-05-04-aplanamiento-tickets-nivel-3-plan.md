# Plan de implementación — Nivel 3: aplanar traits TicketSystem

Fecha: 2026-05-04
Diseño base: `docs/plans/2026-05-04-aplanamiento-tickets-design.md` (sección Nivel 3)

## Decisiones tomadas en brainstorming

- **GenericAttachmentTrait se conserva** (3 consumidores: `TicketsController`, `TicketService`, `EmailService`). No es indirección de un solo uso.
- **Controller**: aplanar los 8 traits a `TicketsController.php` (~1280 LOC resultantes).
- **Service**: aplanar `TicketSystemTrait` y `NotificationDispatcherTrait` a `TicketService.php` (~1050 LOC).
- **Commits**: estrategia híbrida — 3 commits.
- **Visibilidad**: preservar la original método por método; no mezclar refactor de visibilidad con aplanamiento.
- **PHPDoc `@property`** de los traits: borrar (redundantes en el consumidor real).
- **Orden en archivo final**: preservar agrupación temática con marcadores `// region: Listing` simples, sin reorganizar por flujo HTTP.

## Alcance

### Traits a aplanar (10)

Controller stack → `src/Controller/TicketsController.php`:
- `Controller/Traits/ServiceInitializerTrait.php` (47)
- `Controller/Traits/ViewDataNormalizerTrait.php` (58)
- `Controller/Traits/TicketSystemControllerTrait.php` (81)
- `Controller/Traits/TicketSystemActionsTrait.php` (231)
- `Controller/Traits/TicketSystemBulkTrait.php` (170)
- `Controller/Traits/TicketSystemListingTrait.php` (206)
- `Controller/Traits/TicketSystemViewTrait.php` (105)
- `Controller/Traits/TicketSystemHistoryTrait.php` (86)

Service stack → `src/Service/TicketService.php`:
- `Service/Traits/TicketSystemTrait.php` (344)
- `Service/Traits/NotificationDispatcherTrait.php` (104)

### Traits que NO se tocan

- `Service/Traits/GenericAttachmentTrait.php` (3 consumidores reales).
- `Service/Traits/ConfigResolutionTrait.php` (usado por `EmailTemplateRenderer`; verificar otros consumidores antes de cualquier futuro aplanamiento).
- `Service/Traits/SecureHttpTrait.php` (utilidad cross-cutting).
- `Utility/SettingsEncryptionTrait.php` (varios consumidores: `EmailService`, `WhatsappService`, …).
- `Cake\ORM\Locator\LocatorAwareTrait` (CakePHP).

---

## Commit 1 — Traits pequeños del controller

**Mensaje:** `refactor(tickets): aplanar traits pequeños del controller (nivel 3a)`

**Archivos eliminados (3):**
- `src/Controller/Traits/ServiceInitializerTrait.php`
- `src/Controller/Traits/ViewDataNormalizerTrait.php`
- `src/Controller/Traits/TicketSystemControllerTrait.php` *(solo elimina los métodos `getEntityComponents`/`getHistoryTable`; el composite `use` desaparece junto con la clase de trait — los `use` que componía se preservan moviéndolos al siguiente commit; ver nota de orden abajo).*

**Nota de orden importante:** `TicketSystemControllerTrait` compone los 5 traits grandes vía `use`. Si lo eliminamos en este commit, el controller queda sin esos métodos hasta el commit 2. Solución:

1. **Antes de borrar `TicketSystemControllerTrait`**, mover sus `use` de los 5 sub-traits y los 2 helpers (`use TicketSystemActionsTrait`, etc.) a `TicketsController` directamente.
2. Mover los métodos `getEntityComponents` y `getHistoryTable` a `TicketsController` como `private`.
3. Mover los métodos de `ServiceInitializerTrait` y `ViewDataNormalizerTrait` a `TicketsController` preservando visibilidad.
4. Borrar los 3 archivos de trait.
5. En `TicketsController.php`, los 5 `use TicketSystem*Trait;` quedan temporalmente (se aplanan en el commit 2).

**Pasos detallados:**

1. Leer los 3 traits afectados para inventario de métodos y propiedades.
2. Para cada método: copiarlo al final de `TicketsController.php`, dentro de un bloque `// region: <origen>` (p.ej. `// region: ServiceInitializer`).
3. Conservar visibilidad original (`private`/`protected`/`public`).
4. Borrar `@property` PHPDoc del trait que dupliquen propiedades ya declaradas en el controller.
5. En `TicketsController.php`:
   - Quitar `use App\Controller\Traits\TicketSystemControllerTrait;` y `use App\Controller\Traits\ServiceInitializerTrait;`.
   - Reemplazar por imports directos de los 5 traits restantes y de `GenericAttachmentTrait`/`ViewDataNormalizerTrait`. Tras el paso 3, `ViewDataNormalizerTrait` ya está aplanado → solo queda importar `GenericAttachmentTrait` y los 5 sub-traits.
   - El `use` interno: `use GenericAttachmentTrait;` y los 5 `use TicketSystem*Trait;`.
6. Borrar los 3 archivos de trait.
7. Verificación → ver bloque «Verificación commit 1» abajo.

### Verificación commit 1

- `php -l src/Controller/TicketsController.php` sin errores.
- `composer cs-check` sobre el archivo modificado.
- `bin/cake server`, ir a `/`, abrir un ticket. Renderiza sin error.
- Asignar un ticket, cambiar estado, comentar — los métodos absorbidos (`getEntityComponents`, `initializeServices`, `getEntityMetadata`) se ejercitan en estos flujos.

---

## Commit 2 — Traits grandes del controller

**Mensaje:** `refactor(tickets): aplanar traits TicketSystem* en TicketsController (nivel 3b)`

**Archivos eliminados (5):**
- `src/Controller/Traits/TicketSystemActionsTrait.php`
- `src/Controller/Traits/TicketSystemBulkTrait.php`
- `src/Controller/Traits/TicketSystemListingTrait.php`
- `src/Controller/Traits/TicketSystemViewTrait.php`
- `src/Controller/Traits/TicketSystemHistoryTrait.php`

**Pasos:**

1. Por cada trait, en este orden (Listing → View → Actions → Bulk → History — respeta el orden de actions HTTP típico):
   1. Copiar todos los métodos al final de `TicketsController.php` dentro de `// region: <Nombre>` y `// endregion`.
   2. Preservar visibilidad y firma exactas.
   3. Eliminar PHPDoc `@property` del trait si dupica propiedades ya en el controller.
2. Borrar de `TicketsController.php`:
   - Los 5 `use TicketSystem*Trait;` internos.
   - Los 5 `use App\Controller\Traits\TicketSystem*Trait;` del namespace.
3. Borrar los 5 archivos de trait.
4. Después de borrar: `Controller/Traits/` debe quedar vacío. Borrar el directorio si está vacío.
5. Verificación → ver bloque abajo.

### Riesgos específicos

- **Métodos privados con mismo nombre** entre traits: improbable (cada trait tenía scope claro), pero hacer un grep al copiar para detectar colisiones.
- **Propiedades `$this->ticketService`, `$this->Tickets`, `$this->Authorization`**: ya están en el controller real; ningún trait las redeclara.
- **`use` de Cake\Http\Response, etc.**: consolidar imports al top de `TicketsController.php` sin duplicar.

### Verificación commit 2

Manual end-to-end (ningún test automatizado existe):

1. Login con admin, agent, requester, servicio_cliente.
2. **Listing** (`/`): paginación, filtros, búsqueda, badges de estado, contadores sidebar.
3. **View** (`/tickets/view/<id>`): header, comentarios, editor, adjuntos, lazy-load de historial.
4. **Actions**: asignar, cambiar estado, cambiar prioridad, agregar comentario público + interno, descargar adjunto.
5. **Bulk**: seleccionar varios → asignar masivo, cambiar prioridad masiva, agregar etiqueta masiva, eliminar masivo.
6. **History API** (JSON): `/tickets/history/<id>` retorna 200 con payload válido.
7. `composer cs-check` limpio.

---

## Commit 3 — Traits del service

**Mensaje:** `refactor(tickets): aplanar TicketSystemTrait y NotificationDispatcherTrait (nivel 3c)`

**Archivos eliminados (2):**
- `src/Service/Traits/TicketSystemTrait.php`
- `src/Service/Traits/NotificationDispatcherTrait.php`

**Pasos:**

1. Confirmar que `NotificationDispatcherTrait` solo se consume en `TicketService` (grep ya hecho — confirmado).
2. Copiar todos los métodos de `TicketSystemTrait` al final de `TicketService.php` dentro de `// region: TicketSystem`.
3. Copiar todos los métodos de `NotificationDispatcherTrait` dentro de `// region: NotificationDispatcher`.
4. Si `TicketSystemTrait` invoca métodos de `NotificationDispatcherTrait` (el PHPDoc original menciona "Requires NotificationDispatcherTrait"), tras aplanar quedan como llamadas internas normales `$this->dispatch...()`. Verificar que no haya métodos abstractos esperados.
5. Borrar de `TicketService.php`:
   - `use \App\Service\Traits\TicketSystemTrait;`
   - `use \App\Service\Traits\NotificationDispatcherTrait;`
6. **Conservar** `use \App\Service\Traits\GenericAttachmentTrait;` y `use LocatorAwareTrait;`.
7. Borrar los 2 archivos de trait.
8. Verificación → ver bloque abajo.

### Verificación commit 3

1. Crear ticket nuevo (manual o vía Gmail worker).
2. Comentar (público + interno) → notificaciones email + WhatsApp + n8n disparadas correctamente.
3. Cambiar estado → audit en `ticket_history`, notificación al solicitante.
4. Asignar → notificación al asignado.
5. Cambiar prioridad → audit + notificación.
6. Worker Gmail (`bin/cake gmail_worker`) procesa al menos un email entrante sin errores en log.
7. `composer cs-check` limpio.

---

## Riesgos transversales

- **No hay tests automatizados.** Toda verificación es manual; cada commit debe pasar el humo correspondiente antes del siguiente.
- **PHPDoc `@property` en traits**: si el trait declaraba `@property TicketsTable $Tickets` y la clase real no usa la misma anotación, el IDE pierde autocompletado. No bloquea ejecución.
- **Imports duplicados**: al copiar `use Cake\…\Foo;` desde varios traits, consolidar y deduplicar en el top del consumidor.
- **Reverso fácil**: cada commit es atómico. `git revert <hash>` deja el código en estado anterior.

## Criterio de éxito

- `src/Controller/Traits/` no existe (o está vacío).
- `src/Service/Traits/` contiene solo: `ConfigResolutionTrait.php`, `GenericAttachmentTrait.php`, `SecureHttpTrait.php`.
- `TicketsController.php` y `TicketService.php` compilan, `cs-check` limpio.
- Humo manual del commit 3 completo sin regresiones.
