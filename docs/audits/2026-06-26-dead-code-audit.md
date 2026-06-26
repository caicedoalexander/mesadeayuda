# Auditoría de código muerto — Informe final (CakePHP 5.x · mesa-de-ayuda)

## 1. Resumen ejecutivo

Se confirmaron **47 hallazgos únicos** de código muerto (48 verdicts brutos; `App\Utility\SettingsEncryptionTrait` aparece en dos slices y se cuenta una sola vez). El slice `controllers` no produjo hallazgos. Un candidato fue **refutado** (`templates/Pages/home.php`, vivo vía `fallbacks()`), lo que valida el rigor del proceso.

**Por confianza**
| Confianza | Items |
|---|---|
| Alta | 29 |
| Media | 14 |
| Baja | 4 |

**Por riesgo de eliminación**
| Riesgo | Items |
|---|---|
| safe | 26 |
| review | 21 |

**Por categoría**
| Categoría | Items |
|---|---|
| Archivos huérfanos (`orphan-file`) | 3 |
| Archivos duplicados (`duplicate-file`) | 2 |
| Clases/traits sin uso | 13 |
| Métodos públicos sin uso | 13 |
| Métodos protegidos sin uso | 1 |
| Métodos privados sin uso | 1 |
| Plantillas muertas | 12 |
| Assets sin uso | 2 |
| Constantes sin uso (standalone) | 0 |
| Imports sin uso (standalone) | 0 |

**Salud general:** buena. La mayor parte del peso es un único subgrafo de componentes de email HTML abandonado (10 clases reachables solo entre sí) y dos islas muertas de namespace (`App\Utility\*` y servicios `TicketService`/`NumberGenerationService` superseded). No hay rutas vivas que dependan de ninguno de estos símbolos; ~1.500+ LOC eliminables sin tocar producción.

---

## 2. Hallazgos por categoría

> Orden dentro de cada tabla: `safe` antes que `review`; dentro de cada riesgo, mayor confianza primero.

### 2.1 Archivos huérfanos / duplicados

| file:line | símbolo | categoría | conf. | riesgo | razón |
|---|---|---|---|---|---|
| `src/Utility/SettingsEncryptionTrait.php:21` | `App\Utility\SettingsEncryptionTrait` | duplicate-file | alta | safe | Duplicado del activo `App\Service\Traits\SettingsEncryptionTrait`. Ningún PHP importa el namespace `Utility` (solo docs/plans). Los traits no se cargan por convención. |
| `src/Service/TicketService.php:1` | `App\Service\TicketService` | orphan-file | alta | safe | 0 callers (codegraph + grep). No DI, no convención, sin test. Superseded por `TicketPipelineService`/`TicketIngestionService`/`TicketCommentService`/`TicketAttachmentService`. Ya roto: línea 109 llama `generateTicketNumber()` inexistente. |
| `src/Service/EmailTemplateRenderer.php:20` | `App\Service\EmailTemplateRenderer` | orphan-file | alta | review | Sin instanciación ni referencia en src/config; superseded por `TemplateRegistry` + clases Component. No es el último consumidor de `ConfigResolutionTrait`. Importa `Utility\SettingKeys` y `Utility\ValidationConstants` (ver cascada). |
| `src/Utility/SettingKeys.php:12` | `App\Utility\SettingKeys` | duplicate-file | media | review | Duplicado legacy del canónico `App\Constants\SettingKeys` (24 consumidores). Sus únicos consumidores (`EmailTemplateRenderer`, `Utility\SettingsEncryptionTrait`) están muertos. |
| `src/Utility/ValidationConstants.php:11` | `App\Utility\ValidationConstants` | orphan-file | media | review | Solo lo referencia `EmailTemplateRenderer` (muerto). Ningún Table lo usa (usan `App\Constants\*`). |

### 2.2 Clases y traits sin uso

| file:line | símbolo | conf. | riesgo | razón |
|---|---|---|---|---|
| `src/Service/NumberGenerationService.php:29` | `NumberGenerationService` | alta | safe | 0 callers. Único punto de entrada histórico (`TicketsTable::generateTicketNumber()`) ya no existe. La identidad del ticket es `tickets.id` autoincremental (per CLAUDE.md). |
| `src/Notification/Email/Ticket/Component/TicketCard.php:16` | `TicketCard` | alta | safe | Raíz del subgrafo de email HTML huérfano. Ninguna de las 4 `EmailTemplate` registradas la usa (todas reescritas a texto plano + `EmailFrame`). |
| `src/Notification/Email/Ticket/Component/StatusTransition.php:12` | `StatusTransition` | alta | safe | Las plantillas de cambio de estado renderizan `<strong>old -> new</strong>` inline. 0 callers. |
| `src/Notification/Email/Ticket/Component/CommentBlock.php:13` | `CommentBlock` | alta | safe | Las plantillas con comentarios usan `renderQuote()` privado. 0 callers. |
| `src/Notification/Email/Component/Greeting.php:10` | `Greeting` | alta | safe | Las plantillas inlinen su propio `<p>Hola {name}</p>`. 0 callers. |
| `src/Notification/Email/Component/CtaButton.php:10` | `CtaButton` | alta | safe | Plantillas en texto plano no tienen botón CTA. 0 callers. |
| `src/Notification/Email/Component/InfoBox.php:14` | `InfoBox` | alta | safe | Sin caller. Constantes `VARIANT_DASHED/SOLID/SOFT` mueren con la clase. |
| `src/Notification/Email/Component/Card.php:10` | `Card` | alta | safe | Reachable solo desde `TicketCard` (muerto). Transitivamente muerta. |
| `src/Notification/Email/Component/Pill.php:10` | `Pill` | alta | safe | Reachable solo desde `TicketCard`/`StatusTransition` (muertos). `STATUS_THEME` muere con la clase. |
| `src/Notification/Email/Component/Avatar.php:10` | `Avatar` | alta | safe | Reachable solo desde `TicketCard`/`CommentBlock` (muertos). Transitivamente muerta. |
| `src/Notification/Email/Ticket/Component/PriorityArrow.php:10` | `PriorityArrow` | alta | safe | Reachable solo desde `TicketCard` (muerto). Transitivamente muerta. |
| `src/Service/Util/NotificationStamp.php:23` | `NotificationStamp` | alta | review | Stamp HMAC legacy eliminado por audit CRIT-1; `isSystemNotification` ahora compara `From == system_email`. Solo lo ejercita su propio test. |
| `src/View/Helper/TicketHelper.php:14` | `App\View\Helper\TicketHelper` | alta | review | `AppView::initialize()` solo registra `User` y `Sanitize`. Sin `$this->Ticket->` ni `addHelper('Ticket')`. Nunca se carga por convención. |

### 2.3 Métodos públicos sin uso

| file:line | símbolo | conf. | riesgo | razón |
|---|---|---|---|---|
| `src/Console/Installer.php:234` | `Installer::setAppNameInFile` | alta | safe | Boilerplate del skeleton CakePHP. `composer postInstall` nunca lo invoca; el placeholder `__APP_NAME__` no existe en ningún config. |
| `src/View/Helper/StatusHelper.php:40` | `StatusHelper::statusColor` | alta | safe | Ningún template lo llama (usan `statusBadge`/`statusLabel`). Helper sigue vivo por otros métodos. |
| `src/View/Helper/StatusHelper.php:22` | `StatusHelper::priorityColor` | alta | safe | Sin uso en templates/src. **Único consumidor de `TicketConstants::PRIORITY_COLORS`** (ver cascada). |
| `src/View/Helper/UserHelper.php:86` | `UserHelper::avatar` | alta | safe | `grep ->avatar(` = 0. Templates usan `profileImageTag`/`avatarColor`/`initials`. |
| `src/View/Helper/TimeHumanHelper.php:77` | `TimeHumanHelper::time` | alta | safe | `grep ->time(` = 0. Templates usan `short()`/`long()`. |
| `src/Service/Traits/GenericAttachmentTrait.php:364` | `deleteGenericAttachment` | media | review | 0 callers; el plan S3 anota explícitamente que "no sirve". API pública en trait compartido: confirmar que no hay wiring planeado. |
| `src/Service/Traits/GenericAttachmentTrait.php:85` | `setS3Storage` | media | review | Seam de inyección para tests que ningún test usa (a diferencia de los otros dos seams del trait). |
| `src/View/Helper/TicketHelper.php:22` | `TicketHelper::getViewUrl` | alta | review | Único método de `TicketHelper` (nunca cargado). Se elimina junto con la clase. |
| `src/Model/Entity/Ticket.php:97` | `Ticket::isOpen` | alta | review | Predicado de estado sin caller ni Policy que lo consuma. Parte del vocabulario intencional de predicados (hermanos `isResolved`/`isPending`/`isLocked` SÍ se usan). |
| `src/Model/Entity/Ticket.php:111` | `Ticket::isStatusNew` | alta | review | Sin caller. Nombrado para no colisionar con `EntityInterface::isNew()`; no satisface contrato. |
| `src/Model/Entity/Ticket.php:151` | `Ticket::belongsTo` | alta | review | Sin caller. El nombre colisiona con `Cake\ORM\Table::belongsTo` pero la entidad no override nada (no existe en `Entity`). |
| `src/Model/Entity/Ticket.php:160` | `Ticket::isAssignedTo` | alta | review | Sin caller; no existe capa Policy que lo use. |
| `src/Model/Entity/Ticket.php:168` | `Ticket::wasCreatedFromEmail` | alta | review | Sin caller ni dispatch dinámico. Parte de la API de predicados intencional. |

### 2.4 Métodos protegidos / privados sin uso

| file:line | símbolo | conf. | riesgo | razón |
|---|---|---|---|---|
| `src/Service/Traits/GenericAttachmentTrait.php:205` | `getAttachmentTableName` (protected) | alta | safe | Sin llamada interna ni en clases consumidoras (usan `self::ATTACHMENTS_TABLE` directo). La constante permanece. |
| `src/Service/Traits/SecureHttpTrait.php:125` | `validateExternalUrl` (private) | alta | safe | Wrapper backwards-compat sin referencias; los callers usan `resolveAndValidateUrl()`/`secureCurlPost()`. |

### 2.5 Constantes sin uso (standalone)

Ninguna como hallazgo independiente. Caen como **consecuencia** de remociones (ver cascadas):
- `TicketConstants::PRIORITY_COLORS` → huérfana tras quitar `StatusHelper::priorityColor`.
- `InfoBox::VARIANT_DASHED/SOLID/SOFT`, `Pill::STATUS_THEME`, `NumberGenerationService::SEQUENCE_TABLE` → mueren con sus clases.

### 2.6 Imports sin uso (standalone)

Ninguno. Las remociones confirmadas **no** dejan imports huérfanos (p. ej. `Composer\IO\IOInterface` sigue usado tras quitar `setAppNameInFile`). Quedan solo referencias en **comentarios/docblocks** no load-bearing (`GmailService.php:66` menciona `TicketService`; `TemplateContext.php:14` menciona `CommentBlock`; `GmailService.php:720` menciona `NotificationStamp`).

### 2.7 Plantillas muertas

| file:line | símbolo | conf. | riesgo | razón |
|---|---|---|---|---|
| `templates/element/ia.php:1` | element `ia` | media | safe | `grep element('ia')` = 0; no hay `element($var)` dinámico. |
| `templates/element/tickets/requester_stats.php:1` | requester_stats | media | safe | Consume `$topRequesters`, que ningún action vivo setea (dashboard de stats removido). |
| `templates/element/tickets/response_metrics.php:1` | response_metrics | media | safe | Consume `$responseRate`/`$resolutionRate`/`$avgResponseTime`; mismo dashboard removido. |
| `templates/element/tickets/attachment_item.php:1` | attachment_item | media | safe | El path vivo es `_thread_message.php` → `attachment_list.php` (que no lo incluye). |
| `templates/element/tickets/left_sidebar.php:1` | left_sidebar | media | review | Superseded por `right_sidebar` (incluido en `Tickets/view.php:42`); near-duplicate de panel vivo. |
| `templates/layout/agent.php:1` | layout agent | media | review | Sin `setLayout('agent')`. Rol `agent` colapsado en `asesor_tic` (migración 20260510153517). |
| `templates/layout/requester.php:1` | layout requester | media | review | Sin `setLayout('requester')`. Rol renombrado a `external`. |
| `templates/layout/servicio_cliente.php:1` | layout servicio_cliente | media | review | Sin `setLayout('servicio_cliente')`. Rol colapsado en `asesor_tic`. |
| `templates/email/html/default.php:1` | email html default | baja | review | Default de Cake Mailer; la app envía vía `EmailService` → Gmail API, nunca renderiza por Mailer. Latente. |
| `templates/email/text/default.php:1` | email text default | baja | review | Ídem; sin path de render. |
| `templates/layout/email/html/default.php:1` | email layout html default | baja | review | Ídem; sin instancia de Mailer en src. |
| `templates/layout/email/text/default.php:1` | email layout text default | baja | review | Ídem; latente. |

### 2.8 Assets sin uso

| file:line | símbolo | conf. | riesgo | razón |
|---|---|---|---|---|
| `webroot/js/marquee.js:1` | `marquee.js` | media | safe | Nunca cargado vía `Html->script` (inventario completo confirmado). Sin bundler. Define `window.MarqueeText`. |
| `webroot/js/tickets-marquee.js:1` | `tickets-marquee.js` | media | safe | Nunca cargado; llama `MarqueeText.init(...)` de `marquee.js` (también muerto). |

---

## 3. Archivos duplicados / huérfanos (alto valor)

Remociones de archivo completo, priorizadas por su valor:

**Duplicados de archivo (`Utility/*` vs copia canónica):**
- `src/Utility/SettingsEncryptionTrait.php` **duplica** `src/Service/Traits/SettingsEncryptionTrait.php` (el activo). Todos los consumidores vivos (`AppController`, `SettingsService`, `GmailService`, `GmailImportService` + su test) importan la copia `Service\Traits`. La copia `Utility` no la importa ningún PHP. **safe / alta.**
- `src/Utility/SettingKeys.php` **duplica** el canónico `src/Constants/SettingKeys.php` (24 consumidores). La copia `Utility` solo la usan dos clases muertas. **review / media** (acoplada, ver cascada).

**Archivos huérfanos (superseded, sin equivalente activo a comparar):**
- `src/Service/TicketService.php` (~1.042 LOC) — superseded por los servicios de pipeline. **safe / alta.** Mayor remoción individual del informe.
- `src/Service/NumberGenerationService.php` — punto de entrada histórico eliminado. **safe / alta.**
- `src/Service/EmailTemplateRenderer.php` — superseded por `TemplateRegistry`. **review / alta.**
- `src/Utility/ValidationConstants.php` — solo consumido por `EmailTemplateRenderer` (muerto). **review / media.**

> Observación: todo el namespace `App\Utility\*` (`SettingsEncryptionTrait` + `SettingKeys` + `ValidationConstants`) es una **isla muerta**.

---

## 4. Cascadas (orden seguro de remoción)

**C1 — Subgrafo de componentes de email HTML (10 clases).** Todas están confirmadas muertas independientemente (sin entry point vivo), pero hay aristas internas:
- `TicketCard` → llama `Card`, `Pill`, `Avatar`, `PriorityArrow` (es su único caller).
- `StatusTransition` → llama `Pill::forStatus`.
- `CommentBlock` → llama `Avatar`.

Orden seguro: eliminar primero los "padres" (`TicketCard`, `StatusTransition`, `CommentBlock`, `Greeting`, `CtaButton`, `InfoBox`), luego las hojas (`Card`, `Pill`, `Avatar`, `PriorityArrow`). En la práctica pueden borrarse en un solo commit. **Borrar también los tests compañeros** de cada clase bajo `tests/TestCase/Notification/Email/...`.

**C2 — Isla `App\Utility\*` + `EmailTemplateRenderer`.** `EmailTemplateRenderer` importa `Utility\SettingKeys` (:138) y `Utility\ValidationConstants` (:138); `Utility\SettingsEncryptionTrait` resuelve `Utility\SettingKeys` por mismo namespace. **No se pueden quitar `SettingKeys`/`ValidationConstants` en aislamiento** (romperían el import de `EmailTemplateRenderer`). Orden: (1) `EmailTemplateRenderer.php`, (2) `Utility/SettingKeys.php`, (3) `Utility/ValidationConstants.php`, (4) `Utility/SettingsEncryptionTrait.php` — todo en el mismo cambio.

**C3 — `StatusHelper::priorityColor` → `TicketConstants::PRIORITY_COLORS`.** `priorityColor` es el único consumidor de la constante. Tras quitar el método, `PRIORITY_COLORS` queda huérfana y puede eliminarse como follow-on (`STATUS_COLORS` permanece viva).

**C4 — `marquee.js` ↔ `tickets-marquee.js`.** `tickets-marquee.js` llama `MarqueeText.init` definido en `marquee.js`. Ambos muertos; remover juntos.

**C5 — `TicketHelper` + `getViewUrl`.** `getViewUrl` es el único método de la clase nunca cargada; un solo borrado de archivo.

**C6 — `NotificationStamp` + su test.** Remover la clase exige borrar `tests/TestCase/Service/Util/NotificationStampTest.php` (su único ejercitador).

**C7 — Métodos `Ticket::*` + tests.** Cada predicado removido debe arrastrar su test dedicado en `tests/.../TicketTest.php` (`testIsOpen`, `testIsStatusNew`, `testBelongsTo`, `testIsAssignedTo`, `testWasCreatedFromEmail`).

---

## 5. Plan de eliminación sugerido

**Fase 1 — Whole-file safe + alta confianza (impacto máximo, riesgo mínimo)**
1. `src/Utility/SettingsEncryptionTrait.php` (duplicado).
2. `src/Service/TicketService.php` (+ recortar opcionalmente el docblock en `GmailService.php:66`).
3. `src/Service/NumberGenerationService.php`.

**Fase 2 — Subgrafo de email muerto (C1, safe + alta)**
4. Las 10 clases de `src/Notification/Email/**` (padres → hojas) + sus tests compañeros.

**Fase 3 — Métodos safe + alta confianza (sin acoplamiento más allá de su test/constante)**
5. `Installer::setAppNameInFile`.
6. `GenericAttachmentTrait::getAttachmentTableName` (protected).
7. `SecureHttpTrait::validateExternalUrl` (private).
8. `StatusHelper::statusColor`.
9. `StatusHelper::priorityColor` → seguido de `TicketConstants::PRIORITY_COLORS` (C3).
10. `UserHelper::avatar`.
11. `TimeHumanHelper::time`.

**Fase 4 — Templates/assets safe (confianza media, sin efectos secundarios)**
12. `templates/element/ia.php`.
13. `templates/element/tickets/requester_stats.php`, `response_metrics.php`, `attachment_item.php`.
14. `webroot/js/marquee.js` + `webroot/js/tickets-marquee.js` (C4).

**Fase 5 — Items `review` (requieren confirmación del owner antes de borrar)**
15. Isla `App\Utility\*` + `EmailTemplateRenderer` en un solo cambio (C2). Verificar que la dirección "email-templates-in-code" está consolidada.
16. `NotificationStamp` + su test (C6).
17. `GenericAttachmentTrait::deleteGenericAttachment` y `setS3Storage` (confirmar que no hay wiring S3 planeado).
18. `TicketHelper` + `getViewUrl` (C5).
19. Predicados `Ticket::*` + tests (C7) — decisión de diseño: son parte del vocabulario intencional de predicados; un futuro `TicketPolicy` podría usarlos.
20. `templates/element/tickets/left_sidebar.php`; layouts de rol `agent.php`/`requester.php`/`servicio_cliente.php`.

**Fase 6 — Baja confianza (mínimo valor, dejar para último)**
21. Los 4 defaults de Cake Mailer (`templates/email/**` y `templates/layout/email/**`). Latentes si algún día se adopta render por Cake Mailer; el riesgo es `MissingTemplateException` diferido.

---

## 6. Caveats

- **Nada fue modificado.** Este informe es solo lectura; ningún archivo se eliminó ni editó.
- **Templates y assets son de menor confianza** (`media`/`baja`): se basan en inventario estático de `$this->element()` / `Html->script` y ausencia de bundler. Confirmar que ninguna rama draft reintroduzca `left_sidebar.php` o un `setLayout($role)` dinámico antes de borrar layouts de rol.
- **Los 4 defaults de email son framework-convention** (CakePHP los espera si se usa Cake Mailer con render de vista). Borrarlos es seguro hoy pero rompería un futuro uso de Cake Mailer; bajo valor de remoción.
- **Tras cualquier remoción** debe pasar `composer cs-fix && composer cs-check` y `composer test`. Recordar que varios borrados arrastran tests compañeros (notification components, `NotificationStamp`, predicados `Ticket::*`); ejecutar la suite tras cada fase.
- **No tocar migraciones** salvo petición explícita: `ticket_number_sequences` y la migración `20260515120000_SwitchTicketNumberToGlobalCounter` quedan como infraestructura de datos huérfana tras remover `NumberGenerationService`, pero su limpieza es un cambio separado.
- **Items `review` de la entidad `Ticket`** son técnicamente safe (0 acoplamiento de producción) pero constituyen una API de predicados deliberada; confirmar con el owner del refactor antes de removerlos.
- **Un candidato fue refutado:** `templates/Pages/home.php` está **vivo** vía `$builder->fallbacks()` (`routes.php:102` → `PagesController::display` → `render('home')`). No incluido en ningún plan de remoción.

---

## 7. Roadmap de eliminación (checklist de progreso)

> **Cómo usar:** marca `[x]` al completar cada ítem y actualiza el contador de **Progreso**. Tras **cada fase**, corre `composer cs-fix && composer cs-check && composer test` y haz commit antes de seguir. Trabaja en una rama (p. ej. `chore/dead-code-cleanup`). Nada aquí toca migraciones ni la BD.

**Progreso global: 3 / 25**

### Fase 1 — Archivos completos · `safe` · alta confianza (máximo impacto, mínimo riesgo) ✅ _completada (rama `chore/dead-code-fase1`, 394 tests OK)_
- [x] Eliminar `src/Utility/SettingsEncryptionTrait.php` (duplicado del activo `Service/Traits/`)
- [x] Eliminar `src/Service/TicketService.php` (~1.042 LOC) y recortar el docblock obsoleto en `src/Service/GmailService.php:66`
- [x] Eliminar `src/Service/NumberGenerationService.php`

### Fase 2 — Subgrafo de email HTML muerto · `safe` · alta confianza
- [ ] Eliminar las 10 clases de `src/Notification/Email/**`: `TicketCard`, `StatusTransition`, `CommentBlock`, `Greeting`, `CtaButton`, `InfoBox`, `Card`, `Pill`, `Avatar`, `PriorityArrow`
- [ ] Eliminar sus tests compañeros bajo `tests/TestCase/Notification/Email/...` y reescribir el docblock obsoleto de `TemplateContext.php:14`

### Fase 3 — Métodos sin uso · `safe` · alta confianza
- [ ] `Installer::setAppNameInFile` — `src/Console/Installer.php:234`
- [ ] `GenericAttachmentTrait::getAttachmentTableName` (protected) — `src/Service/Traits/GenericAttachmentTrait.php:205`
- [ ] `SecureHttpTrait::validateExternalUrl` (private) — `src/Service/Traits/SecureHttpTrait.php:125`
- [ ] `StatusHelper::statusColor` — `src/View/Helper/StatusHelper.php:40`
- [ ] `StatusHelper::priorityColor` **+ cascada** `TicketConstants::PRIORITY_COLORS` (queda huérfana) — `src/View/Helper/StatusHelper.php:22`
- [ ] `UserHelper::avatar` — `src/View/Helper/UserHelper.php:86`
- [ ] `TimeHumanHelper::time` — `src/View/Helper/TimeHumanHelper.php:77`

### Fase 4 — Plantillas y assets · `safe` · confianza media
- [ ] `templates/element/ia.php`
- [ ] `templates/element/tickets/requester_stats.php`
- [ ] `templates/element/tickets/response_metrics.php`
- [ ] `templates/element/tickets/attachment_item.php`
- [ ] `webroot/js/marquee.js` **+** `webroot/js/tickets-marquee.js` (par acoplado)

### Fase 5 — Requieren confirmación del owner antes de borrar (`review`)
- [ ] Isla `App\Utility\*` **en un solo cambio** (cascada C2): `EmailTemplateRenderer.php` → `Utility/SettingKeys.php` → `Utility/ValidationConstants.php`. _Verificar que la dirección "email-templates-in-code" está consolidada._
- [ ] `NotificationStamp` (`src/Service/Util/NotificationStamp.php`) **+** su test `tests/TestCase/Service/Util/NotificationStampTest.php`; actualizar docstring en `GmailService.php:720`
- [ ] `GenericAttachmentTrait::deleteGenericAttachment` — confirmar que no hay wiring S3 planeado
- [ ] `GenericAttachmentTrait::setS3Storage` — confirmar que no se planean tests que mockeen S3
- [ ] `TicketHelper` (clase completa) **+** `getViewUrl` — `src/View/Helper/TicketHelper.php`
- [ ] Predicados de `Ticket` **+** sus tests (decisión de diseño): `isOpen`, `isStatusNew`, `belongsTo`, `isAssignedTo`, `wasCreatedFromEmail`
- [ ] `templates/element/tickets/left_sidebar.php` **+** layouts de roles eliminados: `agent.php`, `requester.php`, `servicio_cliente.php`

### Fase 6 — Baja confianza · opcional (mínimo valor, dejar para el final)
- [ ] Los 4 defaults de Cake Mailer: `templates/email/html/default.php`, `templates/email/text/default.php`, `templates/layout/email/html/default.php`, `templates/layout/email/text/default.php`. _Latentes si algún día se adopta render por Cake Mailer; el riesgo es `MissingTemplateException` diferido._

### No tocar (fuera de alcance de este roadmap)
- Migraciones huérfanas (`ticket_number_sequences`, `20260515120000_SwitchTicketNumberToGlobalCounter`) — limpieza de datos separada, solo bajo petición explícita.
- `templates/Pages/home.php` — **vivo** vía `$builder->fallbacks()` (refutado por el verificador).
