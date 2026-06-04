# Diseño: eliminar la abstracción de numeración de tickets

**Fecha:** 2026-06-03
**Estado:** Aprobado para planificación
**Contexto previo:** `docs/superpowers/specs/2026-05-15-ticket-numbering-refactor-design.md` (introdujo el contador global y la tabla `ticket_number_sequences`).

## Problema

La numeración de tickets arrastra una abstracción heredada de un diseño abandonado
(secuencias por año, formato `TKT-YYYY-NNNNN`):

- La tabla `ticket_number_sequences` tiene clave primaria `year`, pero solo existe
  una fila con `year = 0` — un valor mágico que significa "contador global".
- `NumberGenerationService` mantiene un contador atómico propio
  (`INSERT ... ON DUPLICATE KEY UPDATE` + `LAST_INSERT_ID()`) para evitar carreras
  bajo creación concurrente (webhook n8n/Gmail + web + múltiples workers FPM).
- El comportamiento es correcto, pero el nombre de la tabla, la columna `year` y la
  cadena de dos migraciones hacen que el sistema **parezca más sofisticado de lo que es**.

El proyecto está en desarrollo, sin datos que preservar. La decisión de producto es
usar el `id` autoincremental de la tabla `tickets` como **único identificador**,
iniciando en 1000. MySQL ya garantiza unicidad y atomicidad en `AUTO_INCREMENT` —
el contador propio y su tabla dejan de tener razón de existir.

## Objetivo

`tickets.id` (autoincremental, desde 1000) es el único identificador. Se elimina por
completo `ticket_number`: la columna, el servicio generador y la tabla de secuencias.

## Diseño

### 1. Esquema — limpiar el historial de migraciones

El usuario optó por dejar el esquema limpio en lugar de migrar hacia adelante. Como es
dev, se recrea la BD desde cero.

- **Editar `config/Migrations/20260430213127_Initial.php`**:
  - Eliminar la columna `ticket_number` (`string(20)`) de la tabla `tickets`.
  - Eliminar el índice único `idx_ticket_number_unique`.
  - Tras el `->create()` de `tickets`, añadir
    `$this->execute('ALTER TABLE tickets AUTO_INCREMENT = 1000')`.
    (Phinx no siembra el seed de AUTO_INCREMENT vía `addColumn`; se hace con un ALTER explícito.)
- **Borrar** `config/Migrations/20260509120000_AddTicketNumberSequencesTable.php`.
- **Borrar** `config/Migrations/20260515120000_SwitchTicketNumberToGlobalCounter.php`.

Verificación: `bin/cake migrations migrate` sobre BD limpia; el primer ticket creado
recibe `id = 1000`.

### 2. Código eliminado

- `src/Service/NumberGenerationService.php` → borrado completo.
- `TicketsTable::generateTicketNumber()` → borrado (junto con el `use` del servicio).
- `Ticket` entity: quitar `ticket_number` del `@property` y del array `_accessible`.
- `Ticket::fromEmailIngest()` y `Ticket::fromWhatsappIngest()`: eliminar el parámetro
  `$ticketNumber` y la asignación `$ticket->ticket_number = ...`. El `id` lo asigna
  MySQL en el `save()`.
- `TicketIngestionService` (líneas ~99, ~235): eliminar
  `$ticketNumber = $ticketsTable->generateTicketNumber();` y el argumento en las
  llamadas a las factories.

### 3. Reemplazo de referencias `ticket_number` → `id`

Identificadores internos / display (controlados por nosotros):

- `TicketsTable::initialize()`: `setDisplayField('id')`.
- `TicketsTable`: eliminar validación de `ticket_number` (`scalar`, `maxLength`,
  `requirePresence`, `notEmptyString`, `add unique`) y la regla
  `isUnique(['ticket_number'])` en `buildRules()`.
- `TicketsTable::findWithFilters()` (búsqueda): reemplazar
  `Tickets.ticket_number LIKE '%search%'` por coincidencia por `id` cuando el término
  es numérico (`Tickets.id = (int)$search`); si no es numérico, no aplica el criterio de número.
- `TicketListingTrait` (campos ordenables): `ticket_number` → `id`.
- `GenericAttachmentTrait` (líneas ~127, ~307): carpeta de adjuntos
  `webroot/uploads/attachments/{id}/` en vez de `{ticket_number}/`.
  (Las carpetas dev existentes no importan.)
- Plantillas de email (`TicketCreatedTemplate`, `TicketUpdatedTemplate`,
  `TicketStatusChangedTemplate`, `TicketCommentAddedTemplate`) y
  `TicketCard`: usar `$ctx->ticket->id`. Se **conserva el literal `#`** → "#1000".
- Templates de vista (`templates/Tickets/index.php`, `view.php`,
  `templates/element/tickets/header.php`).
- `NotificationRenderer` (texto WhatsApp): usar `$ticket->id`.
- `PreviewFixture`: actualizar el valor de ejemplo.

### 4. Frontera externa — payloads salientes (renombrar clave a `id`)

Decisión: renombrar la clave JSON `ticket_number` → `id` en los payloads salientes.

- `N8nService` (líneas ~112, ~152): clave `'ticket_number'` → `'id'`, valor `$ticket->id`.
- `WebhooksController` (línea ~159, respuesta del import): idem.
- `TicketIngestionService` (payloads a líneas ~189, ~298, ~323, ~438): idem.

**Dependencia externa (fuera del repo):** el workflow de n8n y cualquier consumidor
del webhook deben actualizarse para leer `id` en lugar de `ticket_number`. Actualizar
también `docs/operations/n8n-gmail-webhook.md`.

### 5. Tests

- Actualizar los tests que asignan o asertan `ticket_number` para usar `id`:
  `TicketTest`, `TicketsTableTest`, tests de estrategias de notificación, tests de
  plantillas de email, `TicketCardTest`, `TemplateContextTest`.
- Eliminar cualquier test de `NumberGenerationService`.
- Las factories `fromEmailIngest` / `fromWhatsappIngest` ya no reciben número:
  ajustar los tests que las construyen.

## Alternativa considerada y descartada

Mantener `ticket_number` como alias de `id` (columna generada o copia post-insert).
Descartada: reintroduce exactamente la indirección que se busca eliminar — una columna
que nunca difiere del `id`. YAGNI.

## Consecuencia de orden de ejecución

Hoy el número se genera *antes* de `save()`. Con `id`, el número **no existe hasta
después del primer `save()`**. Todo lo que usa el número (adjuntos, payloads,
notificaciones) ya corre después del save, por lo que el impacto es bajo; las
factories simplemente dejan de recibir un número que aún no existe.

## Criterios de éxito

1. No queda ninguna referencia a `ticket_number`, `NumberGenerationService` ni
   `ticket_number_sequences` en `src/`, `config/Migrations/`, `templates/` ni `tests/`
   (verificable con grep).
2. `bin/cake migrations migrate` sobre BD limpia crea el esquema sin la columna ni la tabla.
3. Crear un ticket nuevo le asigna `id = 1000` (primero) y la UI muestra "#1000".
4. `composer test` y `composer cs-check` pasan.
5. Los payloads salientes envían la clave `id`.
