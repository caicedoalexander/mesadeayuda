# Auditoría de Arquitectura — Mesa de Ayuda

**Referencia comparativa:** `C:/Users/sistema/Documents/sgi/arquitecture.md`
**Alcance:** `C:/Users/sistema/Documents/mesa-de-ayuda/src/`
**Nivel:** `deep` con foco estructural CakePHP-idiomático
**Fecha:** 2026-05-07

---

## 1. Resumen ejecutivo

| Métrica | Valor | Veredicto |
|---|---|---|
| Salud arquitectónica global | **4 / 10** | Necesita refactor estructural serio |
| Adherencia a convenciones CakePHP | **5 / 10** | Aceptable en core, problemática en organización |
| Adherencia al patrón "fat-service / thin-controller" descrito en su propio CLAUDE.md | **2 / 10** | El controlador es más pesado que el servicio |
| Modelo de dominio (DDD-lite estilo SGI) | **3 / 10** | Entidades anémicas, lógica acumulada en god-services |
| Documentación interna vs. realidad del código | **3 / 10** | `CLAUDE.md` describe traits que **no existen** |
| Issues críticos | **6** | Ver §3 |
| Issues altos | **8** | Ver §4 |

**Veredicto en una línea:** la arquitectura prometida en `CLAUDE.md` (controladores delgados con traits compuestos, capa de servicios cohesiva, anti-corruption en integraciones) **no es la que está en disco hoy**. El proyecto sufre de god-classes encubiertas con una capa de despacho muerto (`$entityType`), múltiples fuentes de verdad para los mismos valores de dominio, y una carpeta `src/Utility/` que CakePHP no documenta como convención.

---

## 2. Estructura de directorios — comparación SGI vs. Mesa de Ayuda

| Elemento | SGI (referencia) | Mesa de Ayuda | Estado |
|---|---|---|---|
| `src/Constants/` | ✅ Final classes con `public const` | ❌ **No existe** — sus constantes viven en `src/Utility/` | 🔴 Crítico |
| `src/Controller/` (flat) | ✅ Sin subcarpeta `Component` | ⚠️ Tiene `Component/` **vacía** | 🟡 Medio |
| `src/Controller/{Prefix}/` | (no aplicable en SGI) | ✅ `Admin/` correcto (prefix routing) | 🟢 OK |
| `src/Controller/Trait/` (compartido) | ✅ `ExcelCatalogTrait` | ❌ **No existe** pese a que `CLAUDE.md` afirma que sí | 🔴 Crítico (doc) |
| `src/Service/` | ✅ Servicios + sub-DTOs | ✅ `Dto/`, `Exception/`, `Renderer/`, `Traits/` | 🟢 OK |
| `src/Service/Traits/` | (no aparece en SGI; usa familias) | ✅ `ConfigResolutionTrait`, `GenericAttachmentTrait`, `SecureHttpTrait` | 🟢 OK |
| `src/Utility/` | ❌ No usado | ⚠️ **Carpeta inventada**: mezcla constantes (`SettingKeys`, `ValidationConstants`) + un trait (`SettingsEncryptionTrait`) | 🔴 Crítico |
| `src/Event/` | ✅ Existe | ❌ No existe | 🟡 Bajo (opcional) |
| `src/ViewModel/` | ✅ Existe | ❌ No existe | 🟢 OK (opcional) |
| `src/View/Helper/` | (templates con `h()`/elements) | ✅ `Sanitize`, `Status`, `Ticket`, `TimeHuman`, `User` | 🟡 Mixto (ver §4.4) |
| `src/View/Cell/` | – | ✅ `TicketsSidebarCell` | 🟢 OK |
| `src/Model/Behavior/` | – | ✅ `AuditBehavior` | 🟢 OK |
| `tests/` | – | ❌ No existe | 🟡 (declarado en CLAUDE.md) |

---

## 3. Issues críticos 🔴

### 3.1 `src/Utility/` no es una convención CakePHP — es un cajón de sastre

**Hallazgo.** La carpeta contiene tres archivos con responsabilidades distintas:

| Archivo | Lo que es realmente | Dónde debería vivir |
|---|---|---|
| `SettingKeys.php` | `final class` con 16 `public const` (claves de `system_settings`) | `src/Constants/SettingKeys.php` (estilo SGI) |
| `ValidationConstants.php` | `final class` con roles, prioridades, estados, tipos de comentario, claves de cache | `src/Constants/` separadas en `RoleConstants`, `PriorityConstants`, `TicketConstants`, `CacheConstants` |
| `SettingsEncryptionTrait.php` | `trait` consumido por `AppController` | `src/Service/Traits/` o `src/Model/Behavior/` (es comportamiento de persistencia) |

**Por qué importa.**
- CakePHP **sí tiene** un namespace `Cake\Utility` con utilidades primitivas (`Hash`, `Inflector`, `Text`, `Security`...). Ver una `App\Utility\ValidationConstants` invita a confundir constantes de dominio con utilidades genéricas tipo `StringSanitizer`.
- El trait `SettingsEncryptionTrait` está **mezclado con constantes** en la misma carpeta: dos categorías ortogonales.
- El propio CLAUDE.md de SGI lo dice explícitamente: *"Never hardcode domain strings or IDs in PHP. Constants are defined in `src/Constants/` as `final` classes."* Mesa de Ayuda lo viola.

**Severidad:** 🔴 Crítico (organización del código + confusión semántica).

**Recomendación.**
```
src/Utility/  →  ELIMINAR
src/Constants/
   SettingKeys.php
   RoleConstants.php          ← extraer ROLE_* y STAFF_ROLES de ValidationConstants
   TicketConstants.php        ← extraer STATUS_*, TICKET_STATUSES, COMMENT_*, PRIORITIES
   CacheConstants.php         ← extraer CACHE_*, DEFAULT_SYSTEM_TITLE
src/Service/Traits/
   SettingsEncryptionTrait.php  ← mover aquí (junto a otros traits de servicio)
```
Ejecutar luego un buscar-reemplazar de `App\Utility\` → namespaces nuevos.

---

### 3.2 `TicketsController` es un god-controller (1.102 líneas)

**Hallazgo.** El controlador, según `CLAUDE.md`, debería componer su comportamiento de **siete traits** (`TicketSystemControllerTrait`, `TicketSystemListingTrait`, `TicketSystemViewTrait`, `TicketSystemActionsTrait`, `TicketSystemBulkTrait`, `TicketSystemHistoryTrait`, `ServiceInitializerTrait`, `ViewDataNormalizerTrait`).

**Realidad.**
- La carpeta `src/Controller/Traits/` **no existe**.
- Esos métodos están **inlineados** dentro de `TicketsController.php` con marcadores `// region: Listing`, `// region: View`, `// region: TicketSystemController helpers`, etc.
- 53 métodos en un solo archivo, 1.102 LOC.

**Por qué importa.**
1. **El documento miente.** Cualquier desarrollador que lea CLAUDE.md y vaya a `src/Controller/Traits/` no encontrará nada.
2. La regla de "thin controller" del propio CLAUDE.md queda violada por su controlador estrella.
3. El testing manual descrito en CLAUDE.md ("verify by exercising flows") se vuelve carísimo cuando la lógica de listing/filtrado/paginado vive en el controlador.

**Severidad:** 🔴 Crítico.

**Recomendación.**
- Si la intención original era extraer traits, **hágalo** y mueva los `// region:` actuales a archivos individuales en `src/Controller/Trait/` (singular, igual que SGI).
- O **acepte** que es un controlador grande y elimine las regiones de la documentación.
- No queden a medio camino: o existe la abstracción o no.

---

### 3.3 Abstracción muerta `$entityType = 'ticket'`

**Hallazgo.** Todo el "framework interno" de `TicketsController` (≈600 líneas en métodos `indexEntity`, `viewEntity`, `assignEntity`, `bulkAssignEntity`, `getEntityComponents`, `getDefaultContain`, `getValidSortFields`, `getEntityVariable`, `getStatusesForEntity`, `getDefaultUsersRoleFilter`, `getUsersVariableName`, `getDefaultViewContain`, `getDefaultAgentsRoleFilter`, `getSingleEntityVariable`, `getTagsTableName`, `getHistoryTable`) recibe un parámetro `string $entityType`, lo evalúa con `match` y siempre lo resuelve al mismo case:

```php
return match ($entityType) {
    'ticket' => ['Requesters', 'Assignees'],
    default => [],
};
```

`CLAUDE.md` lo confirma: *"Estos traits aún exponen un parámetro `$entityType`... hoy solo se soporta `'ticket'`"*.

**Por qué importa.**
- Es **dead abstraction**: el costo de mantener el switching no se paga porque nunca hay un segundo `case`.
- Cada método trivial pasa por `match` + lanza `InvalidArgumentException` para casos que nunca ocurrirán.
- Lectores futuros pierden tiempo entendiendo una abstracción que no aporta.

**Severidad:** 🔴 Crítico (debt + ruido cognitivo).

**Recomendación.**
- Eliminar el parámetro `$entityType` y los `match`, simplificando los métodos a operar directamente sobre Tickets.
- `getEntityComponents`, `getHistoryTable`, `getDefaultContain`, etc. desaparecen o quedan en una línea.
- Estimado: −400 LOC sin pérdida funcional.

---

### 3.4 Múltiples fuentes de verdad para estados y prioridades (con divergencias)

**Hallazgo.** Los estados de ticket están definidos en al menos **cinco** lugares, con valores **distintos**:

| Fuente | Estados |
|---|---|
| `ValidationConstants::TICKET_STATUSES` | `nuevo, abierto, en_progreso, pendiente, resuelto, cerrado` |
| `StatusHelper::TICKET_STATUS_LABELS` | `nuevo, abierto, pendiente, resuelto, convertido` |
| `TicketsController::getStatusConfig()` | `nuevo, abierto, pendiente, resuelto, convertido` |
| `TicketsController::getStatusesForEntity('ticket')` | `nuevo, abierto, pendiente, resuelto, cerrado` |
| `TicketsController::getResolvedStatuses()` | `resuelto, convertido` |
| `TicketsSidebarCell` (query directo) | `nuevo, abierto, pendiente` |

Hay tres conjuntos **incompatibles**: `en_progreso` (sólo en ValidationConstants), `convertido` (en helper/controller), `cerrado` (en ValidationConstants y `getStatusesForEntity` pero no en colores). El usuario ve un dropdown distinto al filtro lateral.

Lo mismo para prioridades:

| Fuente | Definición |
|---|---|
| `ValidationConstants::PRIORITIES` | array de strings |
| `StatusHelper::PRIORITY_LABELS` + `PRIORITY_COLORS` | constantes privadas |
| `TicketsController::getPriorityConfig()` | array literal |
| `TicketsController::getFilterDataForView()` | array literal duplicado |

**Por qué importa.**
- Cualquier cambio de estado obliga a editar 5 archivos.
- Hay desincronización **hoy** (no es teórico): el código está roto en los bordes (filtros vs. badges).
- SGI lo resuelve con un único punto: `InvoiceConstants::PIPELINE_STATUSES` consumido en `inList()` validators, en `getVisibleStatuses()`, etc.

**Severidad:** 🔴 Crítico (bug latente + violación DRY).

**Recomendación.**
1. Crear `src/Constants/TicketConstants.php` con: `STATUS_*`, `STATUSES`, `RESOLVED_STATUSES`, `STATUS_LABELS`, `STATUS_COLORS`, `PRIORITY_*`, `PRIORITY_LABELS`, `PRIORITY_COLORS`.
2. Auditar y unificar el conjunto real de estados (¿`cerrado` o `convertido`? ¿`en_progreso` existe?).
3. Reescribir `StatusHelper`, `TicketsController` y `TicketsTable::validationDefault()` para leer de `TicketConstants`.

---

### 3.5 `Ticket` es una entidad anémica

**Hallazgo.** `src/Model/Entity/Ticket.php` (74 líneas) sólo tiene `$_accessible` y un `use EmailRecipientsTrait`. Cero métodos de dominio.

Toda la lógica del ticket vive en `TicketService` (1.022 líneas, 25+ métodos, mezcla creación-desde-email, comentarios, archivos adjuntos, tags, followers, cambios de estado, notificaciones).

**Por qué importa.**
- La regla de SGI es explícita: *"Domain helper methods that inspect entity state (e.g., `isRejected()`, `isPaid()`)"* viven en la entidad.
- Mesa de Ayuda no tiene `$ticket->isResolved()`, `$ticket->isLocked()`, `$ticket->canBeAssigned()`, `$ticket->isOverdue()`. En vez de eso, `TicketsController::isEntityLocked()` codifica la regla.
- `TicketService::changeStatus(...)` orquesta todo en lugar de delegar la transición al modelo.

**Severidad:** 🔴 Crítico para mantenibilidad de reglas de negocio.

**Recomendación.**
- Mover predicados puros a `Ticket`: `isResolved()`, `isClosed()`, `isLocked()`, `belongsTo($userId)`, `hasAssignee()`, `wasCreatedFromEmail()`.
- Mover transiciones legales (sin I/O) a un método tipo `Ticket::canTransitionTo(string $newStatus): bool`.
- Mantener en `TicketService` sólo orquestación (transacción + notificaciones + audit + persist).

---

### 3.6 Documentación interna desincronizada con el código

**Hallazgo.** `CLAUDE.md` afirma cosas falsas en este momento:

| Afirmación en CLAUDE.md | Realidad |
|---|---|
| "controladores delgados … traits en `src/Controller/Traits/`" | La carpeta no existe |
| Lista 7 traits (`TicketSystemControllerTrait`, `TicketSystemListingTrait`, ...) | Ninguno existe como archivo |
| "Reusable mixin logic lives in `src/Service/Traits/` (e.g. … `NotificationDispatcherTrait`, … `TicketSystemTrait`)" | Esos dos traits **no existen**; sólo hay `ConfigResolutionTrait`, `GenericAttachmentTrait`, `SecureHttpTrait` |

**Severidad:** 🔴 Crítico (onboarding y confianza en el documento canónico).

**Recomendación.** Sincronizar `CLAUDE.md` **hoy** con el estado real, y luego decidir si extraer los traits o no.

---

## 4. Issues altos 🟠

### 4.1 `TicketService` es un god-service (1.022 líneas)

Contiene seis dominios mezclados (creación-desde-email, comentarios desde email, attachments, tags/followers, transiciones de estado, despacho de notificaciones). En SGI esto serían 4–5 servicios:
- `TicketIngestionService` (entrada desde Gmail/WhatsApp)
- `TicketPipelineService` (cambios de estado, asignación, prioridad)
- `TicketCommentService`
- `TicketAttachmentService` (ya hay un trait reusable, falta el servicio)
- `TicketNotificationService` (extraer `dispatchCreationNotifications`, `dispatchUpdateNotifications`, `sendResponseNotifications`)

🟠 Alto.

### 4.2 `Service/Renderer/NotificationRenderer` y `Service/EmailTemplateRenderer` se solapan

Hay dos clases dedicadas a "renderizar notificaciones": `EmailTemplateRenderer` (Service/) y `NotificationRenderer` (Service/Renderer/). Inspección rápida muestra que comparten propósito (template engine para correos). Decidir cuál es canónica y eliminar la otra, o re-organizar como capas (template loader vs. notification composer).

🟠 Alto (DRY + coherencia de nombres).

### 4.3 Inyección de dependencias por `new` en constructores

`TicketService::__construct(?array $systemConfig = null)` instancia `EmailService` y `WhatsappService` con `new`, no por inyección. Esto:
- Imposibilita sustituir un mock en tests (CLAUDE.md dice que no hay tests; se entiende).
- Acopla `TicketService` directamente a `EmailService`+`WhatsappService` sin interfaces.

SGI hace lo mismo (`?? new EmailService()`) **pero acepta el parámetro inyectable** en el constructor. Mesa de Ayuda **no lo acepta** — fuerza siempre la creación interna. Diferencia importante.

🟠 Alto.

### 4.4 Helpers que mezclan datos de dominio + HTML embebido

`StatusHelper`:
- Define **datos de dominio** (PRIORITY_LABELS, TICKET_STATUS_LABELS) que son responsabilidad de `Constants/`.
- Genera HTML con estilos inline (`style="background-color: ...; color: white; border-radius: ...; padding: ..."`). Eso pertenece a `templates/element/badge.php` con clases CSS, no a un Helper.

`TicketHelper` se reduce a:
```php
public function getViewUrl($ticket): array {
    return ['action' => 'view', $ticket->id];
}
```
Wrapper trivial, candidato a eliminación (use `['action' => 'view', $ticket->id]` directo en templates).

🟠 Alto (separación de responsabilidades view-side).

### 4.5 `Cell/TicketsSidebarCell` ignora a `SidebarCountsService` parcialmente

El Cell llama a `SidebarCountsService::getSidebarCounts()` **y además** ejecuta su propia query inline (`$ticketsTable->find()->select(...)->where(...)->groupBy(...)`). Eso bypassa la capa que el propio CLAUDE.md mandata reusar. Mover el cálculo de `agentStatusCounts` al servicio.

🟠 Alto (consistencia de capa).

### 4.6 `src/Controller/Component/` vacío

Carpeta vacía heredada de bake. Eliminar.

🟠 Alto (housekeeping; señala falta de revisión estructural).

### 4.7 Acoplamiento estructural en `TicketsController::beforeFilter`

```php
'specialRedirects' => function($request, $user, $userRole) {
    $code = $request->getQuery('code');
    if ($code) {
        $this->redirect([
            'controller' => 'Settings', 'action' => 'gmailAuth', 'prefix' => 'Admin', '?' => ['code' => $code]
        ]);
        return true;
    }
    ...
}
```
La acción `index` de Tickets sabe que existe un OAuth callback en Admin/Settings. Esto debería resolverse en `routes.php` con una ruta dedicada, o con un middleware específico para callbacks OAuth, no inyectado vía closure dentro de `indexEntity`.

🟠 Alto (acoplamiento entre módulos no relacionados).

### 4.8 Cache de settings con TTL "infinito" + invalidación manual

En `AppController::beforeFilter`:
```php
$systemConfig = \Cake\Cache\Cache::remember(ValidationConstants::CACHE_SETTINGS, function () { ... }, ValidationConstants::CACHE_CONFIG);
```
Usa el cache config `_cake_core_` (CLI/bootstrap) para una key de runtime (`system_settings`). Eso mezcla TTLs. Cualquier cambio en `/admin/settings` no invalida automáticamente. Hay que verificar que `SettingsService::set()` haga el `Cache::delete`. Si no lo hace, hay bug latente: cambios de SMTP/n8n no se propagan hasta reinicio.

🟠 Alto (bug operacional latente).

---

## 5. Issues medios 🟡

| # | Issue | Recomendación |
|---|---|---|
| 5.1 | Sin `src/Event/` ni domain events | Considerar emitir `TicketCreated`, `TicketAssigned`, `TicketResolved` y desacoplar notificaciones del servicio |
| 5.2 | Sin `tests/` directorio | Establecer al menos una prueba humo de `TicketService::createFromEmail` |
| 5.3 | `Service/Exception/` con un único custom exception | OK por ahora; mantenerlo creciendo en lugar de lanzar `\RuntimeException` literal en otros servicios |
| 5.4 | `assignee_id` mass-assignable en `Ticket::$_accessible` | Verificar autorización antes del `patchEntity` (ya hay `AuthorizationService::isAssignmentDisabled` — confirmar que se usa **antes** de `patchEntity` en cada flujo, no sólo en la UI) |
| 5.5 | `TicketsController::initializeServices()` lee cache global y la pasa a constructores como array de strings | Frágil — preferir `SettingsService::loadConfig()` con tipos |
| 5.6 | `SidebarCountsService` (54 LOC) sólo es llamado desde el Cell | Considerar inlinearlo en el Cell o expandirlo cuando haya más vistas |
| 5.7 | Foreign keys revisar tipos | SGI advierte explícitamente: "Foreign key columns must have identical types" — auditar migraciones existentes |

---

## 6. Lo que sí está bien hecho 🟢

- **`src/Controller/Admin/`** con prefix routing es **correcto** en CakePHP. La preocupación del usuario sobre esta carpeta es infundada — sí es la convención (ver SGI no la usa porque no tiene panel de admin separado, pero CakePHP lo soporta vía `prefix('Admin', ...)` que es exactamente lo que hace `routes.php:68`).
- **`src/Model/Behavior/AuditBehavior`** centraliza la lógica de auditoría — patrón limpio.
- **`src/Service/Traits/GenericAttachmentTrait`** (523 LOC) está aceptablemente cohesivo.
- **Webhooks endpoint** (`POST /webhooks/gmail/import`) con shared secret en `system_settings` cifrados — buen diseño.
- **CSP middleware inline + SecurityHeaders** en `Application.php`.
- **`declare(strict_types=1)`** en todos los archivos auditados.

---

## 7. Matriz de detección de patrones

| Patrón | Detectado | Cumplimiento | Estado |
|---|---|---|---|
| Layered Architecture (CakePHP) | Sí | Parcial (lógica fugada al controlador) | 🟡 |
| Fat-service / thin-controller (declarado) | No | Inverso: controlador 1102 LOC, servicio 1022 LOC | 🔴 |
| Anti-Corruption Layer (`GmailService`) | Sí | Bien encapsulado | 🟢 |
| Decorator (CSP / SecurityHeaders middleware) | Sí | Correcto | 🟢 |
| Repository (CakePHP Tables) | Sí | Por convención | 🟢 |
| Audit Behavior (Memento-lite) | Sí | Vía `AuditBehavior` | 🟢 |
| Constants centralizadas | Parcial | En `Utility/` (lugar incorrecto) + duplicaciones | 🔴 |
| Domain entities (DDD-lite) | No | Anémicas | 🔴 |
| Strategy (estados de ticket) | No | Lógica dispersa en service+controller+helper | 🟠 |
| State machine | Implícito | Sin formalización | 🟠 |
| Outbox / Saga | No requerido | n8n actúa como integration broker externo | 🟢 |

---

## 8. Hoja de ruta priorizada

### Críticos (hacer primero, alto ROI)

1. **🔴 Sincronizar `CLAUDE.md` con la realidad** (1–2 h). Eliminar referencias a traits inexistentes o crearlos.
2. **🔴 Renombrar `src/Utility/` → `src/Constants/` + mover trait** (2 h). Romper duplicaciones. Skill: ninguna específica; refactor manual + `composer dump-autoload`.
3. **🔴 Unificar estados/prioridades en `src/Constants/TicketConstants.php`** (4 h). Eliminar `getStatusConfig`, `getStatusesForEntity`, `StatusHelper::TICKET_STATUS_*`. Una única fuente.
4. **🔴 Eliminar abstracción muerta `$entityType`** (4–6 h). Estimado: −400 LOC en `TicketsController`. Skill aplicable: `acc:refactor`.
5. **🔴 Enriquecer `Ticket` (entidad)** con predicados de dominio (`isResolved()`, `isLocked()`, `canBeAssignedTo($userId)`). Skill: `acc:create-entity` (referencia) + edición manual.

### Altos

6. **🟠 Trocear `TicketsController`** en traits reales en `src/Controller/Trait/` (estilo SGI singular) o aceptar que es monolítico y borrar las regiones.
7. **🟠 Trocear `TicketService`** en `TicketIngestionService`, `TicketPipelineService`, `TicketCommentService`, `TicketNotificationService`.
8. **🟠 Decidir entre `EmailTemplateRenderer` vs. `NotificationRenderer`** y eliminar el otro o explicitar capas.
9. **🟠 Inyectar dependencias en `TicketService`** (parámetros opcionales en constructor estilo SGI) en vez de `new` interno forzado.
10. **🟠 Consolidar `StatusHelper`**: mover datos a `TicketConstants`, mover HTML a `templates/element/badge.php`.
11. **🟠 Mover query inline de `TicketsSidebarCell` a `SidebarCountsService`**.
12. **🟠 Verificar/forzar `Cache::delete('system_settings')`** en `SettingsService::set()`.
13. **🟠 Eliminar `src/Controller/Component/` vacía**.
14. **🟠 Mover redirección OAuth fuera de `TicketsController::index`** a `routes.php` o middleware.

### Medios

15. **🟡 Considerar `src/Event/` + Domain Events** para desacoplar notificaciones.
16. **🟡 Establecer suite mínima de tests** para `TicketService::createFromEmail` y `WebhooksController::gmailImport`.
17. **🟡 Auditar mass-assignment de `assignee_id`** vs autorización.

---

## 9. Respuesta directa a las preocupaciones del usuario

| Pregunta | Respuesta |
|---|---|
| ¿Los archivos en `/Utility/` deberían ser servicios? | **No, deberían ser constantes** (`SettingKeys`, `ValidationConstants`) movidas a `src/Constants/`, y el trait (`SettingsEncryptionTrait`) movido a `src/Service/Traits/`. La carpeta `Utility/` debe **desaparecer**. |
| ¿El nombre `/Utility/` es incorrecto? | **Sí.** No es convención CakePHP del usuario y colisiona conceptualmente con `Cake\Utility\*`. Además mezcla dos categorías distintas (constantes y un trait). |
| ¿`/Admin` subfolder en controllers/ está bien? | **Sí, está correcto.** Es prefix routing estándar de CakePHP — declarado en `routes.php:68` con `prefix('Admin', ...)`. No tocar. |
| ¿Los Helpers están bien? | **Parcialmente.** `TimeHumanHelper`, `SanitizeHelper`, `UserHelper` están bien. `StatusHelper` mezcla constantes de dominio + HTML inline (anti-patrón). `TicketHelper` está reducido a un wrapper trivial — eliminable. |
| ¿Los Cells están bien? | **Casi.** `TicketsSidebarCell` está bien estructurado pero ejecuta una query inline que debería estar en `SidebarCountsService`. |

---

**Próximo paso recomendado.** Ejecutar primero los críticos 1–3 (3 horas, riesgo bajo, mucho beneficio en consistencia y claridad), luego decidir si seguir con los críticos 4–5 (mayor riesgo de regresión, requieren testing manual cuidadoso).
