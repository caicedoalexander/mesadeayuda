# Code Review Report — Módulo Admin

**Fecha:** 2026-05-04
**Modo:** PATH · **Nivel:** HIGH · **Branch:** `dev`
**Alcance:** `src/Controller/Admin/`, `templates/Admin/`, tablas y entidades relacionadas (Users, Tags, EmailTemplates, SystemSettings, ConfigFiles)
**Archivos revisados:** 4 controllers · 4 tables · 5 entities · 16 templates (~7.286 LOC de templates)

---

## Resumen ejecutivo

Refactor a medias: `TagsController` y `EmailTemplatesController` ya fueron extraídos del `SettingsController`, pero quedaron stubs de redirección y ~2.750 líneas de templates duplicados en `templates/Admin/Settings/`. Además existe un bug funcional con implicaciones de seguridad en el formulario de usuarios: los campos `role` e `is_active` se descartan silenciosamente al hacer `patchEntity`.

### Layout del módulo

- `Admin/SettingsController` (435 LOC) — settings, Gmail OAuth, **users CRUD**, más 6 acciones de redirect heredadas a Tags/EmailTemplates.
- `Admin/EmailTemplatesController` (99 LOC) y `Admin/TagsController` (125 LOC) — extraídos "for SRP compliance" según docblocks.
- `Admin/ConfigFilesController` (249 LOC) — uploads de archivos (Gmail `client_secret.json`).
- Templates duplicados 1:1 entre `templates/Admin/Settings/*` y los nuevos `templates/Admin/{Tags,EmailTemplates}/*`.

---

## 🔴 Críticos (3)

| ID | Categoría | Ubicación | Issue | Recomendación |
|----|-----------|-----------|-------|---------------|
| CR-001 | Security / Bug | `src/Model/Entity/User.php:38-50` + `src/Controller/Admin/SettingsController.php:276,329` | `_accessible` marca `'role' => false` y `'is_active' => false`, pero `addUser()` y `editUser()` hacen `patchEntity($user, $data)` con input crudo. CakePHP **descarta silenciosamente** esos campos. Resultado: el selector `role` y el checkbox "activo" del formulario admin son no-ops; los nuevos usuarios obtienen rol null/default; `editUser` no puede modificar rol ni estado — pero `activateUser`/`deactivateUser` sí funcionan porque mutan la entidad directamente. El privilegio/estado no puede establecerse como sugiere la UI. | Marcar `'role' => true, 'is_active' => true` en `User` (la exposición admin-only es aceptable) **o** pasar `['accessibleFields' => ['role' => true, 'is_active' => true]]` en los `patchEntity` de las acciones admin. Auditar la BD actual para ver cuántos usuarios tienen rol asignado. |
| CR-002 | Security (XSS) | `templates/Admin/EmailTemplates/preview.php:112` y `templates/Admin/Settings/preview_template.php:112` | `<?= $previewBody ?>` emite HTML construido desde `template->body_html` tras sustituir variables de muestra controladas por usuario. `body_html` es admin-authored (intent: renderizar HTML), pero `available_variables` viene de un TEXT sin validación. Un admin malicioso podría inyectar JS que se ejecuta en cualquier admin que abra el preview. | Renderizar el preview en un `<iframe sandbox="allow-same-origin">` desde una ruta separada, o limpiar `<script>` con `strip_tags`/HTMLPurifier antes del output. Como mínimo, documentar la frontera de confianza. |
| CR-003 | Architecture / Dead code | `src/Controller/Admin/SettingsController.php:198-211, 289-307` + `templates/Admin/Settings/{add_tag,edit_tag,tags,email_templates,edit_template,preview_template}.php` | Tras extraer `TagsController`/`EmailTemplatesController`, los endpoints viejos en `SettingsController` quedaron como stubs de redirect y los **templates antiguos quedaron intactos** (los preview son byte-idénticos; los demás solo difieren en el form-action). ~2.750 LOC de templates duplicados. Los formularios dentro de los duplicados de Settings apuntan al **controlador equivocado** (`Settings::*Tag/*Template` redirige), creando flujos confusos de redirect que pierden el POST. | Eliminar las 6 acciones de redirect en `SettingsController` y los 6 templates duplicados en `templates/Admin/Settings/`. El refactor está a medio terminar. |

---

## 🟠 Mayores (7)

| ID | Categoría | Ubicación | Issue | Recomendación |
|----|-----------|-----------|-------|---------------|
| MJ-001 | Architecture (DRY) | `beforeFilter` en los 4 controllers | Guard admin duplicado idéntico en `SettingsController:38-52`, `EmailTemplatesController:17-26`, `TagsController:17-26`, `ConfigFilesController:28-40`. El proyecto lista `AuthorizationService` como helper canónico. | Extraer a un `RequireAdminTrait` o moverlo a `AppController` condicionado por `$this->request->getParam('prefix') === 'Admin'`. |
| MJ-002 | Architecture (fat-service / thin-controller) | `SettingsController::editUser` (235-287), `addUser` (314-341), `users` (218-227), `EmailTemplatesController::edit/index/preview`, `TagsController::*` | Las acciones inlinen lógica de negocio (matching de password, profile-image, render de variables de muestra, validación JSON en ConfigFiles). Saltean los servicios existentes (`SettingsService`/`EmailTemplateRenderer`/`ProfileImageService`). `EmailTemplatesController::preview` reimplementa la sustitución `{{var}}` cuando ya existe `EmailTemplateRenderer`. | Empujar a `UserAdminService`, reusar `EmailTemplateRenderer::renderPreview($template, $sampleData)`. Controllers deberían ser dispatchers de ≤30 líneas. |
| MJ-003 | Security (CSRF surface) | `SettingsController::beforeFilter:43-45` y `ConfigFilesController:33` | `unlockedActions` incluye `index`, `gmailAuth`, `testWhatsapp` y `upload` — desactiva FormProtection en POSTs que cambian estado. El allowlist de líneas 76-82 es la única defensa en `index`. | Documentar por qué FormProtection está desbloqueado. Mantener allowlist en `index`. Evaluar re-habilitar FormProtection con `$this->Form->unlockField()` selectivo por input dinámico. |
| MJ-004 | Bug | `src/Controller/Admin/ConfigFilesController.php:184` | `in_array($file->getClientMediaType(), $allowedTypes)` sin flag estricto. Más importante: `getClientMediaType()` es **client-supplied** y trivialmente spoofeable. Un `.php` renombrado a `.json` con `Content-Type: application/json` y JSON válido pasaría. Mitigado porque el archivo es `client_secret.json` fuera de webroot, pero la validación es débil. | Agregar `in_array(..., true)`, validar extensión, y verificar que el contenido empieza con `{`. |
| MJ-005 | Bug | `src/Controller/Admin/ConfigFilesController.php:188` | `file_get_contents($file->getStream()->getMetadata('uri'))` lee el upload dos veces; tras `moveTo` en línea 212 el stream es inválido. Funciona solo porque la validación ocurre antes de `moveTo`. Además `getMetadata('uri')` puede ser `null` en streams no-PSR-7. | Usar `$file->getStream()->getContents()` y rebobinar, o `__toString()`. |
| MJ-006 | Bug / DDD | `src/Controller/Admin/SettingsController.php:152` | `@file_put_contents(TMP . GmailWorkerCommand::TRIGGER_FILE, (string)time())` — el operador `@` esconde fallos de escritura (ej. permisos). El worker no se dispara y el admin ve un Flash de éxito. Además, un controller escribiendo archivos es preocupación de infraestructura. | Mover a `GmailService::triggerWorker()` (o `WorkerTrigger`), chequear retorno, loguear fallo. No usar `@`. |
| MJ-007 | Performance | `src/Controller/Admin/TagsController.php:46-58` | `find()->select([... 'ticket_count' => count(TicketTags.ticket_id)])->leftJoinWith('TicketTags')->groupBy(['Tags.id'])` — construcción confusa que puede generar subqueries por fila. Con `ONLY_FULL_GROUP_BY` en MySQL otras columnas seleccionadas pueden ser rechazadas. | Reescribir como `->select(['ticket_count' => $query->func()->count('TicketTags.id')])->leftJoinWith('TicketTags')->groupBy('Tags.id')`. |

---

## 🟡 Menores (8)

| ID | Categoría | Ubicación | Issue | Recomendación |
|----|-----------|-----------|-------|---------------|
| MN-001 | Architecture | `SettingsController:24-30` | `new SettingsService()` en `initialize()` — service-locator antipattern, dificulta testabilidad. Igual con `new GmailService(...)`, `new WhatsappService(null)`, `new N8nService()`, `new ProfileImageService()`. | Inyectar por constructor o `loadComponent`/contenedor. |
| MN-002 | SRP | `SettingsController` (435 LOC, 16 acciones públicas) | Mezcla settings, OAuth, users CRUD, tests de integraciones, redirects legacy. Debería partirse en `SettingsController`, `UsersController`, `IntegrationsController`. | Extraer `Admin\UsersController` (análogo a Tags/EmailTemplates). |
| MN-003 | Validation | `src/Model/Table/UsersTable.php:72-90` | Validación de complejidad de password aplica solo cuando hay valor; falta `requirePresence('password', 'create')`. Admin podría crear usuario sin password. | Agregar `requirePresence('password', 'create')` y `notEmptyString('password', null, 'create')`. |
| MN-004 | Validation | `src/Model/Table/UsersTable.php:92-102` | `email` permite modo no-RFC (ok); `name` virtual bypass de validación; first/last accept HTML/control chars. | Agregar regex whitelist o strip de control chars. |
| MN-005 | Bug (UX) | `SettingsController::editUser:259` | En password mismatch retorna la vista pero `$user` no fue patcheado, perdiendo input del form. | Hacer `patchEntity` primero (preservar input), luego re-set sin guardar. |
| MN-006 | Encapsulación | `SettingsController::editUser:246` | `(int) $user->id` — cast redundante (entity ya declara int). Verificar firma de `ProfileImageService::saveProfileImage`. | Quitar cast si no se requiere. |
| MN-007 | Readability/DRY | `templates/Admin/Settings/users.php:9-393` (385 líneas de `<style>` inline) | Cada template admin inlinea 300-400 líneas de CSS, duplicadas entre `users.php`, `edit_user.php`, `add_user.php`, `tags.php`, etc. Variables CSS redefinidas por archivo. | Extraer a `webroot/css/admin.css` y cargar con `$this->Html->css('admin', ['block' => true])`. Reduce ~5.000 líneas duplicadas. |
| MN-008 | Readability | `SettingsController::index:64-73` | Tres `if (!isset($data[...]))` consecutivos para defaults de checkboxes. | `$data = array_replace(['whatsapp_enabled' => '0', ...], $data)`. |

---

## 🟢 Sugerencias (5)

| ID | Categoría | Sugerencia |
|----|-----------|------------|
| SG-001 | Architecture | Mover OAuth callback (`gmailAuth`) a `OAuth/GmailController` — distinto ciclo de vida que los settings. |
| SG-002 | Testability | `ConfigFilesController:215-221` hardcodea `posix_getpwnam('www-data')`; en macOS dev / imágenes con `nginx` se omite silenciosamente. Parametrizar vía env. |
| SG-003 | DDD | `SystemSettings.setting_value` valida como `scalar` pero almacena strings cifrados, JSON, paths. Considerar columna `setting_type` para deserialización en `SettingsService`. |
| SG-004 | Performance | `SettingsController::users:222-224` no eager-loadea conteos relacionados; agregar `contain` si la vista crece. |
| SG-005 | i18n | Flash messages hardcodeados en español. Si hay multilang en roadmap, envolver en `__()`. |

---

## Resumen por categoría

| Categoría | 🔴 | 🟠 | 🟡 | 🟢 | Total |
|-----------|----|----|----|----|-------|
| Security | 1 | 2 | 0 | 0 | 3 |
| Bug | 1 | 3 | 2 | 0 | 6 |
| Architecture / SRP | 1 | 2 | 2 | 2 | 7 |
| Performance | 0 | 1 | 0 | 1 | 2 |
| Validation | 0 | 0 | 2 | 0 | 2 |
| Readability/DRY | 0 | 0 | 2 | 0 | 2 |
| Testability/DDD | 0 | 0 | 0 | 2 | 2 |
| **Total** | **3** | **8** | **8** | **5** | **24** |

---

## Task Match Analysis

**Task esperada:** Analizar controllers, tablas y vistas del Admin — estructura, abstracciones, SRP, duplicación, seguridad, consistencia con fat-service.

**Match Score: 100%**

| Esperado | Encontrado | Estado |
|----------|------------|--------|
| Estructural / abstracciones | 4 controllers revisados; extracción parcial detectada | OK |
| SRP / responsabilidades | God-controller `SettingsController` flagged (MN-002) | OK |
| Duplicación | 6 templates duplicados flagged (CR-003) | OK |
| Autorización admin | `beforeFilter` duplicado flagged (MJ-001) | OK |
| CSRF / FormProtection | `unlockedActions` revisado (MJ-003) | OK |
| XSS | `<?= $previewBody ?>` flagged (CR-002) | OK |
| Consistencia fat-service / thin-controller | Múltiples violaciones (MJ-002, MN-001) | OK |

---

## Veredicto: ❌ REQUEST CHANGES

**Resumen:** El módulo Admin está mid-refactor: Tags/EmailTemplates fueron extraídos pero quedan 2.750 LOC de duplicados (CR-003); las reglas `_accessible` del entity `User` rompen silenciosamente el form admin de creación/edición para `role` e `is_active` (CR-001 — regresión funcional con implicaciones de seguridad); y la ruta de preview de templates renderiza HTML sin sanitizar (CR-002).

### Acciones requeridas antes del merge

1. **CR-001** — Hacer `role` e `is_active` mass-assignable (en `User::_accessible` o per-call `accessibleFields`). Verificar manualmente: crear usuario con role=agent, editar a admin.
2. **CR-002** — Sandbox o sanitizar el output del preview de templates; documentar frontera de confianza si es intencional.
3. **CR-003** — Eliminar las 6 acciones de redirect en `SettingsController` (líneas 198-211, 289-307) y los 6 templates duplicados en `templates/Admin/Settings/`. Confirmar que ninguna vista enlace `Settings::editTag`/etc.
4. **MJ-001** — Extraer el `beforeFilter` de admin-role duplicado a un trait o `AppController`.
5. **MJ-005, MJ-006** — Arreglar el doble-read del stream de upload y quitar `@` de `file_put_contents` (fallo silencioso del trigger del worker).

### Recomendado (post-merge)

- MJ-002, MJ-007, MN-001, MN-002, MN-007 — extraer `UsersController`, fix de la query de conteo de Tags, inyectar servicios, mover CSS a assets.

### Archivos de interés

- `src/Controller/Admin/SettingsController.php`
- `src/Controller/Admin/ConfigFilesController.php`
- `src/Model/Entity/User.php`
- `templates/Admin/Settings/` (duplicados a eliminar)
- `templates/Admin/EmailTemplates/preview.php`
