# Aplanamiento de abstracciones tras eliminar Compras

Fecha: 2026-05-04

## Contexto

Al eliminar Compras (commit `56fc686`), las abstracciones diseñadas para soportar dos módulos quedaron sobre-genéricas: parámetros `$entityType` que solo aceptan `'ticket'`, `match` con un solo caso, traits que ya no comparten consumidores, y un enum (`EntityType`) con un único `case`. Este plan colapsa esa indirección en tres niveles incrementales para limitar riesgo.

Cada nivel es un commit independiente y revisable por separado.

---

## Nivel 1 — Quirúrgico (bajo riesgo)

**Objetivo:** eliminar `EntityType` enum y aplanar todos los servicios pequeños y helpers cuyo `match($entityType)` solo tiene rama ticket.

### Archivos afectados (~10)

#### Servicios
- **`src/Service/AuthorizationService.php`**: `isAssignmentDisabled` deja de tomar `$entityType`. Firma: `isAssignmentDisabled($user): bool`. Cuerpo: solo allowedRoles `[ROLE_ADMIN, ROLE_AGENT]`.
- **`src/Service/NumberGenerationService.php`**: borrar map `ENTITY_CONFIG`. Renombrar método a `generateTicketNumber()` o dejar `generate()` sin parámetros con valores hardcoded de Tickets/CPR.
- **`src/Service/SidebarCountsService.php`**: borrar `match`. Hardcodear estados `['resuelto', 'convertido']` y rol `ROLE_AGENT` para `myItems`. Renombrar método si aplica.
- **`src/Service/EmailService.php`**: borrar todos los `match($entityType)` (5 sitios). Borrar parámetro `$entityType` de `sendNewEntityNotification`, `sendEntityStatusChangeNotification`, `sendEntityCommentNotification`, `sendEntityResponseNotification`, `sendCommentBasedNotification`, `sendGenericTemplateEmail`, `loadEntityWithAssociations`, `buildTemplateVariables`, `getRecipientEmail`, `getCommentNotificationConfig`. Inlinear las constantes (`'Tickets'`, `'TicketComments'`, etc.).
- **`src/Service/WhatsappService.php`**: `sendNewEntityNotification` sin `$entityType`; inlinear el config map (numberKey, renderer, table, contain). `testConnection` sin `$module`.

#### Utility / View
- **Borrar `src/Utility/EntityType.php`**. Reemplazar las 12 llamadas (`EntityType::TICKET`, `::from()`, `::fromSource()`, `->tableName()`, `->commentsTable()`, `->foreignKey()`, etc.) por strings/constantes literales en los call sites.
- **`src/View/Helper/StatusHelper.php`**: `statusColor`, `statusLabel`, `statusBadge` quitan parámetro `$entityType`. Eliminar `badge()` legacy si nadie lo usa (verificar).

#### Traits que sí siguen vivos
- **`src/Service/Traits/TicketSystemTrait.php`**: borrar `getEntityTypeFromSource`, `getCommentsTableName`, `getHistoryTableName`, `getForeignKeyName` y reemplazar sus usos internos por strings literales (`'TicketComments'`, `'ticket_id'`, etc.). El trait queda con solo la lógica genuinamente compartida (changeStatus, addComment, assign, changePriority, logHistory, sendResponseNotifications, buildResponseResult, decodeEmailRecipients).
- **`src/Service/Traits/GenericAttachmentTrait.php`**: borrar `match` en `buildLocalPath`, `buildAttachmentData` switch case (ya tiene un solo case). Quitar parámetro `$entityType` donde solo se usaba para resolver el tipo. Los métodos públicos consumidos por TicketService/EmailService pierden el parámetro.

### Riesgo

Bajo. Las firmas cambian pero los call sites son contados (TicketsController, TicketService, AppController, plantillas Tickets). Compila o no — sin lógica nueva.

### Verificación

1. `php -l` en todos los archivos modificados.
2. Carga `/`, abre un ticket, comenta, adjunta archivo, cambia estado, asigna.
3. Worker Gmail importa al menos un email sin error.

---

## Nivel 2 — Templates compartidos (riesgo medio)

**Objetivo:** mover `templates/element/shared/*` a `templates/element/tickets/*` y eliminar el pass-through de `$entityType` que cada element acepta "por si acaso".

### Movimientos

| De | A |
|---|---|
| `element/shared/attachment_item.php` | `element/tickets/attachment_item.php` |
| `element/shared/attachment_list.php` | `element/tickets/attachment_list.php` |
| `element/shared/bulk_actions_bar.php` | `element/tickets/bulk_actions_bar.php` |
| `element/shared/bulk_modals.php` | `element/tickets/bulk_modals.php` |
| `element/shared/comments_list.php` | `element/tickets/comments_list.php` |
| `element/shared/entity_header.php` | `element/tickets/header.php` |
| `element/shared/entity_styles_and_scripts.php` | `element/tickets/styles_and_scripts.php` |
| `element/shared/reply_editor.php` | `element/tickets/reply_editor.php` |
| `element/shared/search_bar.php` | `element/tickets/search_bar.php` |

### Cambios dentro de cada element

- Borrar variable `$entityType` y todos sus usos (controllers, labels, condicionales).
- Hardcodear `'Tickets'` donde se usaba para construir URLs/ controllers.
- Borrar maps de un solo elemento (`$entityLabels = ['ticket' => …]`, `$controllerMap`, etc.).
- En `comments_list.php`: borrar `$commentIdField` (ya hardcoded a `comment_id`); borrar parámetro `entityType` pasado a `attachment_list`.
- En `entity_styles_and_scripts.php`: borrar selector CSS extra (`.ticket-view-container` ya está como container principal del ticket).
- Renombrar `entityMetadata` → variables planas si conviene, o dejarlo si simplifica el header (juicio caso a caso).

### Call sites a actualizar

- `templates/Tickets/index.php`: 3 llamadas `element('shared/…')` → `element('tickets/…')`.
- `templates/Tickets/view.php`: 4 llamadas → idem.
- `templates/element/tickets/comments_list.php`: 2 llamadas internas a `attachment_list`.

### TicketSystemListingTrait y ViewTrait

Los traits que pasan `entityMetadata`/`entityType` a la vista pueden simplificar:
- `ViewDataNormalizerTrait::getEntityMetadata`: borrar parámetro `$entityType`. Los `numberField`, `containerClass`, etc., quedan literales.
- `TicketSystemViewTrait::viewEntity`: deja de pasar `entityType` al `set()`.

### Riesgo

Medio. Si olvido un call site la vista falla en runtime (no en compilación). Requiere navegar manualmente las 2 vistas principales (`/` y un ticket abierto) tras el cambio.

### Verificación

1. `/` → listado tickets carga sin errores; barra de búsqueda y filtros operativos.
2. Ver un ticket → header, comentarios, editor, sidebars renderizan; CSS/JS cargan.
3. Bulk actions funcionan (asignar masivo, cambiar prioridad masivo).
4. Adjuntar archivo desde el editor → listado lo muestra; descarga funciona.

---

## Nivel 3 — Traits del controlador (alto riesgo, opcional)

**Objetivo:** colapsar los 7 traits de controlador (`TicketSystemControllerTrait` + 6 sub-traits) y `TicketSystemTrait`/`NotificationDispatcherTrait`/`GenericAttachmentTrait` (lo que sigue vivo) directamente en `TicketsController` / `TicketService`. Resultado: dos archivos planos, ningún trait propio.

### Antes / después

**Antes:**
```
TicketsController.php (297) + 7 traits (884)  = 1181 LOC
TicketService.php     (608) + 3 traits (1390) = 1998 LOC
```

**Después:**
```
TicketsController.php  (~900 LOC, plano)
TicketService.php      (~1500 LOC, plano)
```

### Plan de migración

1. Para cada trait, mover sus métodos al consumidor (controller o service) preservando visibilidad y firma.
2. Resolver colisiones de nombre (no debería haber, los traits ya están scoped por uso).
3. Eliminar los `use TraitName;` del consumidor.
4. Borrar el archivo del trait.
5. Actualizar imports de namespace (los `use App\Service\Traits\…` desaparecen).

### Excepciones que NO se aplanan

- `Cake\ORM\Locator\LocatorAwareTrait` — viene de CakePHP, se mantiene.
- `Authentication\Controller\Component\AuthenticationComponent` — componentes, no traits propios.
- `App\Utility\SettingsEncryptionTrait` — usado por más de un servicio (EmailService, WhatsappService, …); se conserva como utilidad cross-cutting genuina.
- `App\Service\Traits\ConfigResolutionTrait` y `SecureHttpTrait` — utilidades reutilizadas, se conservan.

Los únicos traits a aplanar son los que tienen un único consumidor:
- `Controller/Traits/TicketSystem*Trait.php` (7 archivos) → `TicketsController`.
- `Controller/Traits/ServiceInitializerTrait.php` → `TicketsController` (es de 1 línea efectiva tras Nivel 1).
- `Controller/Traits/ViewDataNormalizerTrait.php` → `TicketsController`.
- `Service/Traits/TicketSystemTrait.php` → `TicketService`.
- `Service/Traits/NotificationDispatcherTrait.php` → `TicketService` (verificar consumidores).
- `Service/Traits/GenericAttachmentTrait.php` → si lo consume solo `TicketService`/`EmailService`, se aplana o se convierte en clase utilitaria estática. (`EmailService` lo usa para `getFullPath` — confirmar antes.)

### Riesgo

Alto. Diff masivo (~60-70% de los archivos del proyecto). Posibles regresiones por:
- Métodos `private` en trait que pasaron a `protected` en consumidor o viceversa.
- Orden de carga en `initialize()` vs propiedades inicializadas en traits.
- `use TraitName;` olvidado en algún consumidor secundario.
- Fragmentación de PHPDoc al colapsar.

### Verificación

Mucho más amplia. Idealmente humo manual completo:
1. Login con cada rol (admin, agent, requester, servicio_cliente).
2. Flujo completo de ticket: crear desde Gmail → asignar → comentar (público + interno) → cambiar prioridad → adjuntar → cambiar estado → resolver.
3. Bulk: seleccionar varios tickets → asignar masivo, cambiar prioridad masivo, agregar etiqueta masiva, eliminar masivo.
4. Historial lazy-load.
5. Notificaciones email + WhatsApp + n8n con un ticket nuevo.
6. `/admin/settings` y `/admin/email-templates` siguen accesibles.

### Recomendación

**No hacer Nivel 3 de inmediato.** El beneficio es estético; el riesgo es real. Mejor candidato: hacerlo solo si en los próximos meses se vuelve a plantear modificar la lógica de tickets y la indirección estorba activamente. Hasta entonces los traits son inocuos.

---

## Orden propuesto de ejecución

1. **Commit 1 (Nivel 1):** `refactor: aplanar abstracciones de un solo módulo`. ~10 archivos.
2. **Commit 2 (Nivel 2):** `refactor(templates): renombrar element/shared a element/tickets`. ~12 archivos.
3. **Commit 3 (Nivel 3):** opcional, futuro. `refactor: colapsar traits TicketSystem en controller/service`.

Cada commit independiente y revertible.

## Riesgos transversales

- No hay tests automatizados — la verificación es manual y vulnerable a olvidos.
- `EntityType` tiene 12 referencias; cualquiera olvidada lanza `Class not found` al cargar la página.
- Plantillas en `email_templates` no se ven afectadas (siguen siendo registros DB).
