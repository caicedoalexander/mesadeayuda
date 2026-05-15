# Tag chip — promoción a componente compartido

**Fecha:** 2026-05-15
**Estado:** Aprobado (pendiente plan de implementación)
**Origen:** Bug visual en `/` (tickets index): la clase `ticket-tag-chip` no renderiza con estilos.

---

## 1 · Problema

`templates/Tickets/index.php:210` usa `.ticket-tag-chip` para renderizar las
etiquetas de cada fila, pero la regla CSS de esa clase vive en
`webroot/css/tickets-view.css:873`, que solo se carga en la vista de detalle
de un ticket (vía `templates/element/tickets/styles_and_scripts.php`). La
vista index no carga `tickets-view.css`, así que los chips salen sin
estilos.

El bug se introdujo en el commit `0d626ef` (refactor que unificó el chip de
fila con el de la vista de detalle): se renombró `.tag-chip` →
`.ticket-tag-chip` y se borró el bloque viejo en `8a08863`, pero la regla
unificada se quedó en CSS view-scoped.

### Síntomas relacionados detectados durante el diagnóstico

- **Duplicación.** `.thread-meta-sep` está definido en `styles.css:818`
  (global) y también en `tickets-view.css:145`. Funciona en index solo
  porque el global lo cubre, pero viola la fuente única.
- **DESIGN.md desincronizado.** La sección §2.4 documenta el componente
  como `.tag-chip` (nombre que ya no existe en el CSS real).
- **Falta regla preventiva.** Ni `CLAUDE.md` ni `DESIGN.md` definen dónde
  vive el CSS de un componente compartido vs. uno view-scoped, así que
  nada bloquea repetir el patrón.

---

## 2 · Objetivo

1. Hacer que `.tag-chip` sea un componente global compartido del sistema
   de diseño, cargado en toda la app.
2. Eliminar las duplicaciones detectadas.
3. Re-alinear `DESIGN.md` con el código real.
4. Documentar en `CLAUDE.md` la regla de ubicación de CSS para prevenir
   recurrencia.

---

## 3 · Diseño

### 3.1 · Nombre del componente

Se adopta `.tag-chip` (sin prefijo `ticket-`):

- Alinea con `docs/design/DESIGN.md §2.4`, que ya lo documenta así.
- Consistente con la convención de otros chips compartidos (`.file-chip`,
  `.email-var-chip`).
- El prefijo de dominio (`ticket-`) era redundante: el chip es un
  primitivo del sistema de diseño, no específico de tickets.

### 3.2 · Ubicación del CSS

| Regla                                 | Archivo destino           | Razón                                                  |
| ------------------------------------- | ------------------------- | ------------------------------------------------------ |
| `.tag-chip` (base)                    | `webroot/css/components.css` | Componente compartido, cargado globalmente vía `head.php`. |
| `.tag-chip .tag-remove` + `:hover`    | `webroot/css/components.css` | Sub-elemento del mismo componente.                     |
| `.tag-chip--row`                      | `webroot/css/bulk-actions.css` | Modifier contextual: trunca a `max-width: 140px` dentro de filas densas. View-scoped a index. |
| `.btn-add-tag`                        | `webroot/css/tickets-view.css` (sin cambio) | Solo se usa en la vista de detalle. Si en el futuro se usa en index, migrar entonces. |
| `.thread-meta-sep`                    | `webroot/css/styles.css` (sin cambio; eliminar duplicado de `tickets-view.css`) | Primitivo de layout ya global.                         |

### 3.3 · Markup esperado

**Vista de detalle (`templates/element/tickets/comments_list.php`):**

```html
<span class="tag-chip"
      style="background:<?= h($tag->color) ?>20; color:<?= h($tag->color) ?>; border-color:<?= h($tag->color) ?>40">
    <?= h($tag->name) ?>
    <?php if (!$isLocked): ?>
        <a href="#" class="tag-remove">&times;</a>
    <?php endif; ?>
</span>
```

**Fila de index (`templates/Tickets/index.php`):**

```html
<span class="tag-chip tag-chip--row"
      style="background:<?= h($tag->color) ?>20; color:<?= h($tag->color) ?>; border-color:<?= h($tag->color) ?>40;"
      title="<?= h($tag->name) ?>">
    <?= h($tag->name) ?>
</span>
```

El coloreado se aplica con `style` inline porque los tags son entidades
dinámicas de base de datos (`tags` table con campo `color`). No se usan
variantes de clase (`.green`, `.orange`, etc.).

### 3.4 · Cambios por archivo

**`webroot/css/components.css`** — agregar nueva sección (sugerido: cerca
de `.file-chip`, sección 9.x existente):

- Bloque `.tag-chip { ... }` migrado desde `tickets-view.css:873-886`
  (renombrado).
- Bloque `.tag-chip .tag-remove { ... }` migrado desde 888-894.
- Regla `.tag-chip .tag-remove:hover { opacity: 1; }` migrada desde 896.

**`webroot/css/tickets-view.css`** — eliminar:

- Líneas 873-896 (bloques `.ticket-tag-chip` + `.tag-remove`).
- Líneas ~145-… del bloque `.thread-meta-sep` (duplicado de `styles.css`).

**`webroot/css/bulk-actions.css`** — renombrar:

- Línea 130: `.cell-subject .meta-row .ticket-tag-chip--row` →
  `.cell-subject .meta-row .tag-chip--row`.
- Actualizar el comentario de líneas 127-129 para referirse a `.tag-chip`.

**`templates/Tickets/index.php`**:

- Línea 210: `class="ticket-tag-chip ticket-tag-chip--row"` →
  `class="tag-chip tag-chip--row"`.

**`templates/element/tickets/comments_list.php`**:

- Línea 74: `class="ticket-tag-chip"` → `class="tag-chip"`.

### 3.5 · DESIGN.md §2.4

Reescribir con:

- Nombre real (`.tag-chip`) y archivo donde vive (`components.css`).
- Markup canónico (ejemplo de la sección 3.3 de este spec).
- Modifier `.tag-chip--row` documentado como variante de fila densa.
- Sub-elemento `.tag-remove` documentado.
- Aclaración: el color viene dinámicamente vía `style` inline desde
  `tag->color` (campo de DB), no por clases de tono.
- Actualizar §14 ("Mapa de archivos") si lista el archivo donde vive el
  componente.

### 3.6 · CLAUDE.md — nueva sub-sección

Agregar bajo "Coding conventions" (o crear sub-sección "Frontend / CSS"):

```
### CSS y sistema de diseño

- Antes de usar una clase CSS en un template, verifica que el archivo
  que la define esté cargado por esa vista. Archivos cargados
  globalmente desde `templates/element/head.php`: `styles`, `components`,
  `badges`, `tickets-rail`. View-scoped: `tickets-view.css` (solo en la
  vista de detalle de ticket vía `element/tickets/styles_and_scripts.php`)
  y `bulk-actions.css` (solo en la vista index de tickets).
- Si una clase se usa en más de una vista (o podría usarse), su CSS debe
  vivir en `components.css` y estar documentada en `docs/design/DESIGN.md`
  antes del merge. CSS view-scoped es solo para clases que viven dentro
  de esa ruta.
- No dupliques una regla CSS en dos archivos. Si necesitas extender un
  componente compartido en un contexto específico, usa un modifier
  (`--row`, `--compact`, etc.) en el CSS view-scoped y deja el bloque
  base en `components.css`.
- `docs/design/DESIGN.md` es la fuente única de los componentes del
  sistema de diseño. Cuando crees, renombres o muevas un componente
  compartido, actualiza DESIGN.md en el mismo commit que el CSS.
```

---

## 4 · Verificación

1. `composer cs-check` pasa sin nuevas violaciones.
2. **Render index (`/`):** un ticket con tags muestra chips con fondo
   tintado, borde sutil y truncado a 140px en la fila densa.
3. **Render detalle (`/tickets/view/...`):** chips idénticos visualmente
   al estado previo al cambio; botón `tag-remove` (×) sigue visible y
   funcional.
4. `Grep -r "ticket-tag-chip"` no devuelve coincidencias en `webroot/`,
   `templates/`, `src/`.
5. `Grep -r "thread-meta-sep"` aparece exactamente una vez en
   `webroot/css/` (en `styles.css`).
6. DESIGN.md §2.4 refleja el markup real.

---

## 5 · Fuera de alcance

- `.btn-add-tag` no se migra: actualmente solo se usa en la vista de
  detalle. Si en el futuro se requiere en index, se migra entonces.
- Barrido completo de otras duplicaciones `styles.css` ↔ `tickets-view.css`
  más allá de `.thread-meta-sep`. Es trabajo aparte (auditoría CSS).
- Refactor del coloreado inline a custom properties (`--tag-color`).
  Posible mejora futura, fuera del alcance del bug actual.

---

## 6 · Riesgos

- **Bajo:** el renombre toca 3 templates y 3 archivos CSS. Verificación
  visual cubre ambas vistas afectadas.
- Cualquier integración externa (snippet copy-paste, email templates con
  HTML inline) que use `ticket-tag-chip` quedaría sin estilos. Búsqueda
  en el repo no encontró otros usos, pero vale la verificación final
  con `Grep` antes del merge.
