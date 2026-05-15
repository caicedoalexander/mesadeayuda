# Tag chip — promoción a componente compartido (Implementation Plan)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Promover `.ticket-tag-chip` (definido solo en `tickets-view.css`) a un componente global `.tag-chip` en `components.css`, eliminar duplicaciones, sincronizar DESIGN.md y prevenir recurrencia con una regla en CLAUDE.md.

**Architecture:** Refactor puramente CSS + templates. Estrategia "add new before remove old" para evitar render roto durante los pasos intermedios. Sin lógica nueva ni cambios en PHP de servicio. Verificación manual visual (no hay test runner para CSS en este repo).

**Tech Stack:** CakePHP 5 templates (`.php`), CSS plano con tokens en `:root`, sin preprocesador.

**Spec de referencia:** `docs/superpowers/specs/2026-05-15-tag-chip-shared-component-design.md`

---

## File Structure

**Archivos a modificar:**

| Archivo | Responsabilidad después del cambio |
| --- | --- |
| `webroot/css/components.css` | Hospeda el bloque canónico `.tag-chip`, `.tag-chip .tag-remove` y su `:hover`. |
| `webroot/css/tickets-view.css` | Pierde `.ticket-tag-chip*` y el duplicado de `.thread-meta-sep`. Conserva `.btn-add-tag` (sigue siendo view-scoped). |
| `webroot/css/bulk-actions.css` | El modifier de fila se renombra a `.tag-chip--row`. |
| `templates/Tickets/index.php` | `class="ticket-tag-chip ticket-tag-chip--row"` → `class="tag-chip tag-chip--row"`. |
| `templates/element/tickets/comments_list.php` | `class="ticket-tag-chip"` → `class="tag-chip"`. |
| `docs/design/DESIGN.md` | §2.4 reescrita con el nombre real, markup canónico, modifier y sub-elemento. |
| `CLAUDE.md` | Nueva sub-sección "CSS y sistema de diseño" bajo Coding conventions. |

**Sin tests automáticos:** el proyecto no tiene visual regression / CSS test runner. La verificación es por `Grep` (consistencia) + render manual en navegador (index y detalle).

---

## Task 1 — Agregar `.tag-chip` canónico a `components.css`

Estrategia: agregar el nuevo bloque ANTES de tocar templates u otros archivos, así durante todo el proceso al menos un selector (viejo o nuevo) está cargado en cualquier vista.

**Files:**
- Modify: `webroot/css/components.css` (insertar después del bloque `.file-chip-list`, ~línea 1032)

- [ ] **Step 1: Insertar el bloque `.tag-chip` en `components.css`**

Abrir `webroot/css/components.css`. Justo después del cierre del bloque `.file-chip-list` (~línea 1032, antes del comentario `/* === 14 · RICH-TEXT TOOLBAR ===`), insertar:

```css


/* ===========================================================
   13.5 · TAG CHIP — pill compacto para etiquetas de ticket.
   Componente compartido: vive aquí (no en tickets-view.css)
   porque se usa en la lista (index) y en el detalle.
   El color de fondo/borde/texto se aplica inline desde tag->color
   (campo dinámico de BD), no por variantes de clase.
   =========================================================== */
.tag-chip {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 3px 9px;
    border-radius: 6px;
    background: var(--gray-100);
    color: var(--gray-700);
    border: 1px solid transparent;
    font-size: 11px;
    font-weight: 500;
    line-height: 1.4;
    white-space: nowrap;
}

.tag-chip .tag-remove {
    color: inherit;
    opacity: 0.55;
    font-size: 11px;
    margin-left: 2px;
    text-decoration: none;
}

.tag-chip .tag-remove:hover { opacity: 1; }
```

- [ ] **Step 2: Verificar que el archivo sigue siendo CSS válido**

Run: `composer cs-check`
Expected: PASS (CodeSniffer no lintea CSS, debe pasar igual que antes; basta con confirmar que no rompimos nada de PHP).

Visualmente abrir el index y el detalle: los chips siguen renderizándose (los selectores viejos `.ticket-tag-chip` todavía existen en `tickets-view.css`, así que detalle sigue funcionando; index ya empieza a ver el chip estilizado por el nuevo selector global, aunque el template aún usa la clase vieja — eso se arregla en Task 2).

Nota: en este punto el index aún se ve sin estilos hasta Task 2, porque el template usa `ticket-tag-chip`. Es esperado, sigue al siguiente paso.

- [ ] **Step 3: Commit**

```bash
git add webroot/css/components.css
git commit -m "feat(css): add canonical .tag-chip to components.css

Promotes the tag chip to a globally-loaded shared component as the
first step of migrating off the view-scoped .ticket-tag-chip rule.
Old selectors stay in tickets-view.css until templates are migrated."
```

---

## Task 2 — Renombrar la clase en los templates

Una vez que `.tag-chip` global existe, mover los consumidores hacia el nuevo nombre. Esto arregla el bug visual en el index.

**Files:**
- Modify: `templates/Tickets/index.php:210` (y otras líneas dentro del bloque del foreach si las hay)
- Modify: `templates/element/tickets/comments_list.php:74`

- [ ] **Step 1: Actualizar `templates/Tickets/index.php`**

Reemplazar línea 210:

```php
                                                        <span class="ticket-tag-chip ticket-tag-chip--row"
```

por:

```php
                                                        <span class="tag-chip tag-chip--row"
```

Verificar con `Grep "ticket-tag-chip" templates/Tickets/index.php` que no queda ninguna ocurrencia.

- [ ] **Step 2: Actualizar `templates/element/tickets/comments_list.php`**

Reemplazar línea 74:

```php
                    <span class="ticket-tag-chip"
```

por:

```php
                    <span class="tag-chip"
```

Verificar con `Grep "ticket-tag-chip" templates/element/tickets/comments_list.php` que no queda ninguna ocurrencia.

- [ ] **Step 3: Verificación visual**

1. `bin/cake server` (o el server ya corriendo).
2. Abrir `http://localhost:8765/` (index de tickets). En un ticket que tenga tags, los chips deben verse con fondo tintado, padding 3px 9px, border-radius 6px y truncado a 140px (el modifier `--row` aún existe en `bulk-actions.css` con el nombre viejo — eso se renombra en Task 3, pero los tokens base ya aplican por `.tag-chip`).
3. Abrir un ticket en detalle. Los chips dentro de la card de comentario deben verse idénticos a antes.

Si el truncado en index no aplica todavía (porque `bulk-actions.css` aún tiene `.ticket-tag-chip--row`), es esperado — se arregla en Task 3.

- [ ] **Step 4: Commit**

```bash
git add templates/Tickets/index.php templates/element/tickets/comments_list.php
git commit -m "fix(tickets): rename ticket-tag-chip to .tag-chip in templates

Fixes missing chip styles on the index view: .ticket-tag-chip lived in
view-scoped tickets-view.css which never loads on /. Templates now
reference the global .tag-chip introduced in the previous commit."
```

---

## Task 3 — Renombrar el modifier `--row` en `bulk-actions.css`

**Files:**
- Modify: `webroot/css/bulk-actions.css:127-135`

- [ ] **Step 1: Actualizar el bloque del modifier y su comentario**

Reemplazar el bloque actual (líneas 127-135):

```css
/* Row-context modifier of .ticket-tag-chip: truncate long names inside
   the dense table row. All other tokens (padding, radius, color tinting)
   are inherited from the canonical chip in tickets-view.css. */
.cell-subject .meta-row .ticket-tag-chip--row {
    max-width: 140px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
```

por:

```css
/* Row-context modifier of .tag-chip: truncate long names inside the
   dense table row. All other tokens (padding, radius, color tinting)
   are inherited from the canonical chip in components.css. */
.cell-subject .meta-row .tag-chip--row {
    max-width: 140px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
```

- [ ] **Step 2: Verificación visual**

Refrescar `http://localhost:8765/` con un ticket cuyo nombre de tag sea largo. Confirmar que ahora se trunca con `…` y respeta `max-width: 140px`.

- [ ] **Step 3: Commit**

```bash
git add webroot/css/bulk-actions.css
git commit -m "refactor(css): rename .ticket-tag-chip--row to .tag-chip--row

Aligns the row-context modifier with the canonical .tag-chip in
components.css. Pure rename; styling unchanged."
```

---

## Task 4 — Eliminar las reglas obsoletas de `tickets-view.css`

Esto remueve la fuente del bug y la duplicación de `.thread-meta-sep`.

**Files:**
- Modify: `webroot/css/tickets-view.css` (eliminar líneas 145-150 y 873-896)

- [ ] **Step 1: Eliminar el duplicado de `.thread-meta-sep`**

En `webroot/css/tickets-view.css`, eliminar el bloque (~líneas 145-150):

```css
.thread-meta-sep {
    width: 3px;
    height: 3px;
    border-radius: 2px;
    background: var(--gray-300);
}
```

(la versión canónica permanece en `webroot/css/styles.css:818`, que es global).

- [ ] **Step 2: Eliminar los bloques `.ticket-tag-chip*`**

En el mismo archivo, eliminar los bloques (~líneas 873-896):

```css
.ticket-tag-chip {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 3px 9px;
    border-radius: 6px;
    background: var(--gray-100);
    color: var(--gray-700);
    border: 1px solid transparent;
    font-size: 11px;
    font-weight: 500;
    line-height: 1.4;
    white-space: nowrap;
}

.ticket-tag-chip .tag-remove {
    color: inherit;
    opacity: 0.55;
    font-size: 11px;
    margin-left: 2px;
    text-decoration: none;
}

.ticket-tag-chip .tag-remove:hover { opacity: 1; }
```

Conservar el bloque siguiente `.btn-add-tag { ... }` intacto (sigue siendo view-scoped, solo se usa en detalle).

- [ ] **Step 3: Verificar que no queda ninguna referencia**

Run:

```bash
Grep "ticket-tag-chip" --glob "**/*"
```

Expected: 0 matches en todo el repo (excepto en `docs/superpowers/specs/2026-05-15-tag-chip-shared-component-design.md` que describe la historia del refactor — esas referencias son intencionales).

Run:

```bash
Grep "thread-meta-sep" --glob "webroot/css/*"
```

Expected: exactamente 1 match, en `webroot/css/styles.css`.

- [ ] **Step 4: Verificación visual final**

Refrescar tanto `/` como `/tickets/view/<id>` con un ticket que tenga tags y separadores meta. Ambos deben renderizar igual que antes del refactor.

- [ ] **Step 5: Commit**

```bash
git add webroot/css/tickets-view.css
git commit -m "refactor(css): remove obsolete .ticket-tag-chip and duplicate .thread-meta-sep

The chip rule is now in components.css under .tag-chip. The
.thread-meta-sep duplicate is unnecessary; styles.css already defines
it globally."
```

---

## Task 5 — Sincronizar `docs/design/DESIGN.md` §2.4

**Files:**
- Modify: `docs/design/DESIGN.md:161-170`

- [ ] **Step 1: Reescribir la sección 2.4**

Reemplazar el bloque actual:

```markdown
### 2.4 · Tag chip — `.tag-chip`

Categorías de ticket. Cuatro tonos:

| Tono     | Clase            | Uso sugerido                          |
| -------- | ---------------- | ------------------------------------- |
| `gray`   | _(default)_      | Genérico                              |
| `green`  | `.tag-chip.green`| RRHH, accesos                         |
| `orange` | `.tag-chip.orange`| Mantenimiento, PSI                    |
| `blue`   | `.tag-chip.blue` | IT, sucursal                          |
```

por:

```markdown
### 2.4 · Tag chip — `.tag-chip`

Pill compacto para mostrar etiquetas de un ticket. Componente
**compartido y global**, vive en `webroot/css/components.css` y se
carga desde `templates/element/head.php`. Se usa tanto en la lista
(`templates/Tickets/index.php`) como en el detalle
(`templates/element/tickets/comments_list.php`).

**Markup canónico:**

```html
<span class="tag-chip"
      style="background:<?= h($tag->color) ?>20; color:<?= h($tag->color) ?>; border-color:<?= h($tag->color) ?>40">
    <?= h($tag->name) ?>
    <a href="#" class="tag-remove">&times;</a>
</span>
```

El color **no** se aplica con variantes de clase (`.green`, `.orange`)
sino inline desde `tag->color` (campo dinámico de la tabla `tags`).
El sufijo hexadecimal `20`/`40` aplica alpha al fondo/borde.

**Modifier — `.tag-chip--row`:** trunca el nombre a `max-width: 140px`
dentro de la fila densa de la tabla de tickets. Vive en
`webroot/css/bulk-actions.css` (view-scoped a index) porque solo aplica
en ese contexto.

**Sub-elemento — `.tag-remove`:** ancla con `&times;` para quitar la
etiqueta. Hereda color del padre, `opacity: 0.55` en reposo y `1` en
hover.
```

- [ ] **Step 2: Confirmar que §14 (Mapa de archivos) sigue correcto**

Leer `docs/design/DESIGN.md` líneas 748-770. La entrada
"Componentes globales (08–15)" ya apunta a `components.css`, no requiere
cambios. No agregar entrada nueva (el chip queda cubierto).

- [ ] **Step 3: Commit**

```bash
git add docs/design/DESIGN.md
git commit -m "docs(design): sync §2.4 with the real .tag-chip implementation

Documents the dynamic inline-color pattern, the .tag-chip--row modifier
location, and the .tag-remove sub-element. Drops the obsolete
.green/.orange/.blue variants table that never matched the code."
```

---

## Task 6 — Agregar regla preventiva a `CLAUDE.md`

**Files:**
- Modify: `CLAUDE.md` (insertar nueva sub-sección al final de "Coding conventions")

- [ ] **Step 1: Insertar la sub-sección "CSS y sistema de diseño"**

En `CLAUDE.md`, localizar la sección `## Coding conventions`. Después del último bullet de esa sección (el que termina con `HtmlSanitizerTrait` para emails), agregar:

```markdown

### CSS y sistema de diseño

- Antes de usar una clase CSS en un template, verifica que el archivo
  que la define esté cargado por esa vista. Archivos cargados
  globalmente desde `templates/element/head.php`: `styles`, `components`,
  `badges`, `tickets-rail`. View-scoped: `tickets-view.css` (solo en la
  vista de detalle de ticket vía `element/tickets/styles_and_scripts.php`)
  y `bulk-actions.css` (solo en la vista index de tickets).
- Si una clase se usa en más de una vista (o podría usarse), su CSS debe
  vivir en `webroot/css/components.css` y estar documentada en
  `docs/design/DESIGN.md` antes del merge. CSS view-scoped es solo para
  clases que viven exclusivamente dentro de esa ruta.
- No dupliques una regla CSS en dos archivos. Si necesitas extender un
  componente compartido en un contexto específico, usa un modifier
  (`--row`, `--compact`, etc.) en el CSS view-scoped y deja el bloque
  base en `components.css`.
- `docs/design/DESIGN.md` es la fuente única de los componentes del
  sistema de diseño. Cuando crees, renombres o muevas un componente
  compartido, actualiza DESIGN.md en el mismo commit que el CSS.
```

- [ ] **Step 2: Commit**

```bash
git add CLAUDE.md
git commit -m "docs(claude): add CSS scope rule to prevent view-scoped chip regressions

Captures the lesson from the .ticket-tag-chip incident: shared
components belong in components.css, not in tickets-view.css. New rule
also prohibits CSS duplication and requires DESIGN.md updates in the
same commit as shared-component changes."
```

---

## Verificación final

- [ ] **Step 1: Sanity grep**

```bash
Grep "ticket-tag-chip" --glob "webroot/**"
Grep "ticket-tag-chip" --glob "templates/**"
Grep "ticket-tag-chip" --glob "src/**"
```

Las tres búsquedas deben devolver 0 matches.

```bash
Grep "thread-meta-sep" --glob "webroot/css/**"
```

Debe devolver exactamente 1 match (en `styles.css`).

- [ ] **Step 2: PHP CS**

Run: `composer cs-check`
Expected: PASS (no tocamos PHP en este refactor más allá de cambios de string en HTML; cs-check debe pasar igual que antes).

- [ ] **Step 3: Verificación visual end-to-end**

1. `/` con un ticket que tenga tags → chips tintados, padding correcto, truncado en filas con tags largos.
2. `/tickets/view/<id>` → chips idénticos al estado pre-refactor, separadores `thread-meta-sep` visibles.
3. DevTools: confirmar que `.tag-chip` se resuelve a la regla en `components.css` (no en `tickets-view.css`).

- [ ] **Step 4: Log de commits**

Run: `git log --oneline -7`
Expected: los 6 commits del plan en orden, sobre `main`.

---

## Self-review notes

- **Spec coverage:** §3.2 (ubicación CSS) → Tasks 1, 3, 4. §3.4 (cambios por archivo) → Tasks 1-6. §3.5 (DESIGN.md §2.4) → Task 5. §3.6 (CLAUDE.md) → Task 6. §4 (verificación) → sección final. §5 (fuera de alcance: `.btn-add-tag`) → respetado en Task 4 Step 2.
- **No placeholders:** Cada Step tiene el código exacto a pegar/eliminar.
- **Type consistency:** Nombre único `.tag-chip` (sin prefijo) en CSS, templates y docs. Modifier siempre `.tag-chip--row`. Sub-elemento siempre `.tag-remove`.
- **Sin tests automáticos:** Documentado en el header — verificación es manual visual + grep. El proyecto no tiene visual regression infra.
