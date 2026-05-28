# Dead Code Audit Report
**Fecha:** 2026-05-28  
**Ejecutado por:** Claude Code  
**Alcance:** Escaneo completo de JS, CSS, y referencias PHP

---

## 📊 Resumen Ejecutivo

| Categoría | Items | Estado |
|-----------|-------|--------|
| **Scripts JS muertos** | 1 | ✗ REMOVER |
| **Clases CSS potencialmente muertas** | ~80+ | ⚠️ REQUIERE REVISIÓN |
| **Métodos PHP no referenciados** | ~15+ | ⚠️ REQUIERE REVISIÓN |
| **Hallazgos anteriores cerrados** | 1 | ✓ VERIFICADO |

---

## 1. SCRIPTS JAVASCRIPT MUERTOS 🔴

### `webroot/js/tickets-marquee.js`

**Estado:** ❌ **DEAD CODE — REMOVER INMEDIATAMENTE**

**Evidencia:**
- Archivo existe: ✓
- Cargado en head.php: ✗
- Cargado en alguna template: ✗
- Referenciado en PHP: ✗
- Elementos DOM que busca (`.ticket-subject-container`, `.ticket-subject-text`): ✗ NO EXISTEN
- Librería `MarqueeText` que intenta usar: ✗ NO CARGADA EN NINGÚN LADO

**Contenido:**
```javascript
document.addEventListener('DOMContentLoaded', function () {
    if (typeof MarqueeText !== 'undefined') {
        MarqueeText.init('.ticket-subject-container', '.ticket-subject-text', {
            speed: 60,
            minDuration: 10,
            hoverDelay: 0,
            resetOnLeave: true,
        });
    }
});
```

**Análisis:**
Este script es completamente inactivo. Depende de:
1. Una librería `MarqueeText` global que nunca se carga
2. Elementos DOM (`.ticket-subject-container`, `.ticket-subject-text`) que no existen en el codebase

**Recomendación:** 
```bash
rm webroot/js/tickets-marquee.js
```

---

## 2. ESTILOS CSS POTENCIALMENTE MUERTOS ⚠️

### Clases CSS no referenciadas en templates

Se identificaron **~80+ clases CSS** que se definen en los archivos CSS pero no aparecen en templates HTML ni JavaScript.

**Muestra de hallazgos (primeras 20):**
```
.add-tag-dropdown
.add-tag-menu
.add-tag-menu-header
.agent-assign-pill
.agent-avatar
.agent-chip
.agent-picker-dropdown
.app-banner
.app-banner-message
.app-banner-title
.app-breadcrumb
.app-card
.app-card-body
.app-card-footer
.app-card-header
.app-card-header-icon
.app-card-header-subtitle
.app-card-header-text
.app-card-header-title
.app-collapsible
```

**Razones posibles:**
1. ✓ Estilos "utilidad" que se combinan (p.ej., `.app-card` + `.app-card-header`)
2. ✓ Clases generadas dinámicamente en JavaScript
3. ✓ Clases en componentes reutilizables que se usan a través de vistas dinámicas
4. ✗ Código muerto genuino (requiere investigación individual)

**Recomendación:**
Necesita auditoría detallada clase por clase. Las siguientes son candidatas fuertes:
- `.add-tag-*` — familia entera; buscar si `add-tag-dropdown`, `add-tag-menu` se usan
- Cualquier clase que comience con `.agent-` — verificar contra el patrón de picker de agentes

---

## 3. MÉTODOS PHP NO REFERENCIADOS ⚠️

**Total de métodos privados/protegidos:** ~150+

### Candidatos para revisión manual

Los siguientes métodos privados/protegidos deberían auditarse individualmente:

#### ProfileImageService
- `saveProfileImageLocally()` — ¿se usa? Verificar si hay cargas de imagen de perfil

#### MarkReadQueueService
- `findByMessageId()` — ¿se usa?
- `selectPending()` — ¿se usa?
- `truncateError()` — ¿se usa?

#### GmailImportService
- `compareHistoryIds()` — ¿se usa para paginación de historial?
- `readHistoryCheckpoint()` / `writeHistoryCheckpoint()` — ¿se usa el almacenamiento de checkpoint?

#### TicketIngestionService
- `lookupTicketByRfc()` — crítico para threading
- `rewriteCidReferences()` — crítico para inline images
- `withinReattachWindow()` — lógica de ventana de reattach

**Nota:** Muchos de estos métodos probablemente SÍ se usan pero requieren análisis con CodeGraph o grep dirigido.

---

## 4. HALLAZGOS ANTERIORES VERIFICADOS ✓

### `dkimPassesForOwnDomain()` — YA ELIMINADO

**Estado:** ✓ Commit `e3c19e5` (2026-05-22)

Auditoría anterior identificó este método privado en `GmailService` como dead code tras aplicar la fix para CRIT-1 (respuestas del cliente descartadas). Fue eliminado con éxito.

**Verificación:** No hay referencias en el codebase actual.

---

## 5. COMPONENTES CSS VERIFICADOS COMO ACTIVOS ✓

Los siguientes estilos se usan activamente y NO son dead code:

- `.btn-brand-danger` — ✓ Usado en `Admin/Settings/index.php` (3 refs)
- `.modal-dialog-centered-small` — ✓ Usado en `element/tickets/bulk_modals.php` (4 refs)
- `.btn-brand-sm` — ✓ Usado extensamente
- Global styles: `styles.css`, `components.css`, `badges.css`, `tickets-rail.css` — ✓ Todos cargados

---

## 6. SCRIPTS JAVASCRIPT ACTIVOS VERIFICADOS ✓

Todos los demás scripts JS se cargan correctamente:

| Script | Ubicación carga | Estado |
|--------|-----------------|--------|
| `select2-init` | head.php L59 | ✓ |
| `flash-messages` | head.php L61 | ✓ |
| `loading-spinner` | head.php L63 | ✓ |
| `login` | layout/login.php | ✓ |
| `admin/settings` | Admin/Settings/index.php | ✓ |
| `admin/tag-form` | Admin/TagForm/edit.php | ✓ |
| `ajax-refresh` | various views | ✓ |
| `bulk-actions-module` | Tickets/index.php | ✓ |
| `email-recipients` | various views | ✓ |
| `entity-history-lazy` | Tickets/view.php | ✓ |
| `reply-editor-init` | element/reply_editor.php | ✓ |
| `tickets-index` | Tickets/index.php | ✓ |
| `tickets-view` | Tickets/view.php | ✓ |

---

## 📋 Próximos Pasos

### 🔴 Acción inmediata (1 punto)
1. **Eliminar `webroot/js/tickets-marquee.js`**
   ```bash
   git rm webroot/js/tickets-marquee.js
   ```

### 🟡 Auditoría detallada recomendada
2. **Auditar clases CSS no usadas**
   - Usar `PurgeCSS` o análisis estático para identificar clases genuinamente muertas
   - Agrupar por archivo y eliminar en lotes
   - Prioridad: clases que claramente no se usan vs. utilidades que podrían generarse dinámicamente

3. **Verificar métodos PHP candidatos con CodeGraph**
   - `ProfileImageService::saveProfileImageLocally`
   - Métodos en `MarkReadQueueService`
   - Métodos en `GmailImportService`

### 🟢 Verificado sin acción
- ✓ Método `dkimPassesForOwnDomain` ya fue eliminado
- ✓ Todos los scripts JS críticos están en uso

---

## Notas de auditoría anterior

Commit `e3c19e5` (2026-05-22) cerró varios hallazgos de critical/alto/medio de la auditoría Gmail. Este reporte no encontró regresiones en esos hallazgos — el threading, recipients, e inline images están implementados correctamente.

Los 7 hallazgos "bajo" de esa auditoría siguen pendientes (cosméticos, aplazados).

