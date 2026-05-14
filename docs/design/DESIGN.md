# Sistema de Diseño · Mesa de Ayuda

Documentación viva del sistema de diseño aplicado a la **Mesa de Ayuda**.
Origen: handoff de [claude.ai/design](https://claude.ai/design) (mayo 2026),
dirección **Workspace A** (rail oscuro + área principal sobre `--gray-50`).

> Este archivo es la fuente única para tokens, componentes y reglas. Cuando
> un componente nuevo se agregue al producto, su especificación va aquí
> antes de implementarse.

---

## 1 · Tokens

Todos los tokens viven como CSS variables en `webroot/css/styles.css :root`.
**Nunca** se debe hardcodear un hex en un componente — todo viene de aquí.

### 1.1 · Marca

| Token                  | Valor      | Uso                                              |
| ---------------------- | ---------- | ------------------------------------------------ |
| `--admin-green`        | `#00A85E`  | Acción primaria, éxito, marca                    |
| `--admin-green-light`  | `#00D477`  | Hover sobre fondo oscuro, gradientes             |
| `--admin-green-hover`  | `#008f50`  | Hover sobre fondo claro                          |
| `--admin-green-soft`   | `#E6F7EE`  | Fondo de pill / superficie suave                 |
| `--admin-green-ink`    | `#00432a`  | Texto sobre verde suave                          |
| `--admin-orange`       | `#CD6A15`  | Advertencia, prioridad media, etiqueta secundaria |
| `--admin-orange-light` | `#F07D2D`  | Hover/acento                                     |
| `--admin-orange-soft`  | `#FCEFE0`  | Fondo nota interna / pill nuevo                  |
| `--admin-orange-ink`   | `#6b3306`  | Texto sobre naranja suave                        |
| `--admin-blue`         | `#0066cc`  | Información, estado pendiente, enlace            |
| `--admin-blue-soft`    | `#E3EFFC`  | Fondo info / pill pendiente                      |
| `--admin-blue-ink`     | `#0a3a78`  | Texto sobre azul suave                           |

### 1.2 · Semánticos

| Token             | Valor     | Uso                                          |
| ----------------- | --------- | -------------------------------------------- |
| `--danger-color`  | `#dc3545` | Error, prioridad alta, SLA vencido           |
| `--danger-soft`   | `#FCE4E6` | Fondo crítico / pill abierto                 |
| `--danger-ink`    | `#7a1a25` | Texto sobre rojo suave                       |
| `--success-color` | `#198754` | Confirmaciones (alineado con Bootstrap 5)    |
| `--warning-color` | `#ffc107` | Avisos                                       |
| `--info-color`    | `#0dcaf0` | Info auxiliar                                |

### 1.3 · Escala de grises

| Token        | Valor     | Uso                                  |
| ------------ | --------- | ------------------------------------ |
| `--gray-900` | `#111827` | Texto principal, **rail oscuro**     |
| `--gray-800` | `#1F2937` | Texto destacado                      |
| `--gray-700` | `#374151` | Texto cuerpo                         |
| `--gray-600` | `#4B5563` | Texto secundario                     |
| `--gray-500` | `#6B7280` | Texto muted, placeholders            |
| `--gray-400` | `#9CA3AF` | Iconos sutiles, separadores          |
| `--gray-300` | `#D1D5DB` | Bordes activos                       |
| `--gray-200` | `#E5E7EB` | Bordes default                       |
| `--gray-100` | `#F3F4F6` | Backgrounds, tags neutros            |
| `--gray-50`  | `#F9FAFB` | Surface, fondos de página            |

### 1.4 · Radios

| Token          | Valor  | Uso                                       |
| -------------- | ------ | ----------------------------------------- |
| `--radius-sm`  | `6px`  | Tags, chips, dropdown items               |
| `--radius-md`  | `8px`  | Botones, inputs, cards pequeñas           |
| `--radius-lg`  | `12px` | Cards, modales, contenedores grandes      |
| _999px_        | —      | Pills (status, priority) — siempre full   |

### 1.5 · Sombras

| Token         | Valor                                                                              |
| ------------- | ---------------------------------------------------------------------------------- |
| `--shadow-sm` | `0 1px 2px rgba(0,0,0,0.04)`                                                       |
| `--shadow-md` | `0 4px 6px -1px rgba(0,0,0,0.1), 0 2px 4px -1px rgba(0,0,0,0.06)`                  |
| `--shadow-lg` | `0 10px 15px -3px rgba(0,0,0,0.1), 0 4px 6px -2px rgba(0,0,0,0.05)`                |

### 1.6 · Transiciones

| Token           | Valor                                  |
| --------------- | -------------------------------------- |
| `--transition`  | `all 0.2s cubic-bezier(0.4, 0, 0.2, 1)` |

### 1.7 · Tipografía

- **Familia primaria:** `'Google Sans'` (declarado en `styles.css :root`).
- **Datos tabulares** (IDs, timestamps, métricas): clase `.mono` →
  `'Geist Mono', ui-monospace, 'SF Mono', monospace` con
  `font-variant-numeric: tabular-nums`.
- **Cifras alineadas en tablas:** clase `.tnum`.

| Estilo            | Tamaño | Peso | Uso                                       |
| ----------------- | ------ | ---- | ----------------------------------------- |
| H1 página         | 26 px  | 700  | Título principal de la vista              |
| H2 sección        | 18 px  | 700  | Sub-secciones dentro de un card           |
| Cuerpo            | 13 px  | 400  | Texto regular                             |
| Cuerpo destacado  | 14 px  | 600  | Asuntos de ticket, nombres de usuario     |
| Label / meta      | 10 px  | 600  | Eyebrow uppercase con `letter-spacing`    |

---

## 2 · Componentes

> **Regla de oro:** una sola fuente de verdad por componente. Si un botón
> necesita una variante nueva, se agrega a `.btn-brand-*`; no se re-estila
> un `<button>` ad-hoc en una vista.

### 2.1 · Botones — `.btn-brand-*`

Implementados en `webroot/css/styles.css` (sección `DESIGN SYSTEM`).

| Variante           | Clase                  | Fondo                | Borde                   | Texto              |
| ------------------ | ---------------------- | -------------------- | ----------------------- | ------------------ |
| Primario           | `.btn-brand-primary`   | `--admin-green`      | sin borde               | `#fff`             |
| Secundario         | `.btn-brand-secondary` | `#fff`               | `1px var(--gray-200)`   | `--gray-700`       |
| Ghost              | `.btn-brand-ghost`     | transparente         | sin borde               | `--gray-700`       |
| Dashed (asignable) | `.btn-brand-dashed`    | `--admin-orange-soft`| `1px dashed orange`     | `--admin-orange-ink` |

**Tamaños:** default es 36 px de alto. Modificadores `.btn-brand-sm` (30 px) y
`.btn-brand-lg` (44 px).

**Reglas:**
- Botones de acción **destructiva** se construyen reemplazando `--admin-green`
  por `--danger-color` y la sombra correspondiente; nunca se inventa un color.
- Iconos a la izquierda del texto, gap 6 px, mismo color que el texto.

### 2.2 · Pills de estado — `.status-pill` / `.badge-status-*`

Pill redondeada (`border-radius: 999px`) con dot a la izquierda. El dot usa
el color "fuerte"; el fondo y texto usan el par `--*-soft` / `--*-ink`.

| Estado     | Fondo                  | Texto                  | Dot                |
| ---------- | ---------------------- | ---------------------- | ------------------ |
| `nuevo`    | `--admin-orange-soft`  | `--admin-orange-ink`   | `--admin-orange`   |
| `abierto`  | `--danger-soft`        | `--danger-ink`         | `--danger-color`   |
| `pendiente`| `--admin-blue-soft`    | `--admin-blue-ink`     | `--admin-blue`     |
| `resuelto` | `--admin-green-soft`   | `--admin-green-ink`    | `--admin-green`    |

**Tamaños:**
- Default: `padding: 5px 11px; font-size: 12px`.
- Compacto (`.sm`): `padding: 3px 9px; font-size: 11px`.

**Implementación de servidor:** `StatusHelper::statusBadge()` →
`templates/element/tickets/badge.php` → clases `.badge-status .badge-status-{key}`
(estilos en `webroot/css/badges.css`).

### 2.3 · Flag de prioridad — `.priority-flag`

Texto inline (no pill), color sólido + glifo unicode a la izquierda.

| Prioridad  | Color              | Glifo |
| ---------- | ------------------ | ----- |
| `baja`     | `--gray-500`       | ↓     |
| `media`    | `--admin-orange`   | →     |
| `alta`     | `--danger-color`   | ↑     |
| `urgente`  | `--danger-color`   | ↑     |

Por convención **solo se muestra cuando es alta o urgente** en la lista.
En la vista de detalle se muestra siempre.

### 2.4 · Tag chip — `.tag-chip`

Categorías de ticket. Cuatro tonos:

| Tono     | Clase            | Uso sugerido                          |
| -------- | ---------------- | ------------------------------------- |
| `gray`   | _(default)_      | Genérico                              |
| `green`  | `.tag-chip.green`| RRHH, accesos                         |
| `orange` | `.tag-chip.orange`| Mantenimiento, PSI                    |
| `blue`   | `.tag-chip.blue` | IT, sucursal                          |

### 2.5 · Stat inline — `.stat-inline`

Cifra contextual en el header (`8 activos · 3 críticos · 2 sin asignar`).
Estructura: `<span class="dot">` + `<span class="value">` + `<span class="label">`.
La variante `.emphasis` resalta el número en `--gray-900`.

### 2.6 · Input / Search

- **Search:** `.search-wrapper` + `.search-input-clean`. Fondo `--gray-50`
  por defecto; al focus pasa a `#fff` y muestra el focus ring verde.
- **Focus ring estándar:** clase `.focus-ring` —
  `outline: 2px solid rgba(0,168,94,0.18); outline-offset: 1px;
  border-color: var(--admin-green)`.
- **Inputs Bootstrap (`.form-control`, `.form-select`):** alineados al
  focus ring de marca en `bulk-actions.css`.

### 2.7 · Tabla — `.tickets-table`

Vive dentro de `.tickets-table-card` (card con borde + radius + shadow).

- **Header:** `font-size: 10px; text-transform: uppercase; color: --gray-500`.
- **Filas:** padding vertical 14 px (cómoda), borde inferior 1 px `--gray-100`.
- **Hover de fila:** fondo `#fafafb`.
- **Fila crítica** (prioridad alta/urgente): clase `.row-critical` agrega
  barra vertical roja a la izquierda del primer `<td>`.
- **Footer del card:** count summary + paginador compacto en `--gray-50`.

### 2.8 · Agent picker — Select2 adaptado

Edición inline de asignación en la tabla. Comparte el HTML original del
`<select>` (CakePHP `Form->select`) pero Select2 lo reemplaza visualmente.

**Trigger asignado** — chip inline limpio:

```text
[AB] Agent Name      <-- avatar circular 22px + nombre
```

- Sin borde por defecto, borde gris en hover, focus ring verde al abrir.
- Avatar: color determinístico por nombre (paleta de 7 colores).
- Iniciales: primera letra del primer y último nombre.

**Trigger sin asignar** — pill dashed naranja:

```text
[+] Asignar          <-- chip dashed sobre fondo orange-soft
```

- Mismo estilo que `.btn-brand-dashed`.
- La flecha de Select2 se oculta vía `:has()` para no estorbar el pill.

**Dropdown:**

- Opciones renderizadas con avatar 26 px + nombre.
- Highlight pasa por `--admin-green-soft`; opción seleccionada en verde sólido.
- La opción "Sin asignar" (empty value) queda en gris cursiva al inicio del listado.

**Implementación:**

| Pieza               | Archivo                                                 |
| ------------------- | ------------------------------------------------------- |
| Templates + paleta  | `webroot/js/select2-init.js` — `agentSelectionTemplate`, `agentOptionTemplate`, `AGENT_AVATAR_PALETTE` |
| Estilos             | `webroot/css/bulk-actions.css` — sección _Agent picker_ |
| Inicialización      | Detecta clase `.table-agent-select` en el loop principal |

El `<select>` debe llevar la clase `.table-agent-select` para que Select2
aplique este tratamiento.

### 2.9 · Rail oscuro — `.tickets-rail`

Sidebar de navegación con fondo `--gray-900`. Estilos dedicados en
`webroot/css/tickets-rail.css`.

**Anatomía:**
1. **Brand block** — mark cuadrado con gradiente verde + título + subtítulo.
2. **Vistas** — `.rail-nav-item` con icono Bootstrap, texto, contador.
3. **Estados** — items con dot de color en lugar de icono.
4. **Workspace** — solo visible para `role = admin` (Usuarios, Etiquetas,
   Plantillas, Configuración).
5. **Spacer** + **User footer** — avatar circular con halo verde, nombre,
   email truncado, botón logout.

**Item activo:** fondo `rgba(0,168,94,0.16)`, borde izquierdo 2 px verde,
texto en `--admin-green-light`.

---

## 3 · Patrones

### 3.1 · Header de página (lista)

Estructura definida por `.tickets-header` → breadcrumb → `tickets-header-row`
(título + stats inline a la izquierda, acciones a la derecha).

```text
🎫 Tickets  ›  Todos sin resolver
Tickets sin resolver
● 8 en esta vista   ● 3 alta prioridad   ● 2 sin asignar       [↻ Actualizar]
```

### 3.2 · Toolbar consolidada

Una sola fila con: search expandido + botón **Filtros** con badge de conteo
+ bulk-actions bar.

- Si hay filtros activos, el botón Filtros toma fondo `--admin-green-soft`
  y el badge cambia a fondo sólido verde.

### 3.3 · Empty state

`.tickets-empty-state` — icono grande gris claro + mensaje en `--gray-500`.
Centrado vertical y horizontal, sobre fondo blanco con borde sutil.

### 3.4 · Paginación

`.pagination-links` dentro del footer del card. Botones compactos
(`padding: 4px 9px`), página activa con fondo verde sólido.

---

## 4 · Reglas no-negociables

1. **Tokens en CSS variables.** Nunca hardcodear hex en componentes — todo
   viene del `:root` de `styles.css`.
2. **Una sola fuente de verdad para botones.** Usar `.btn-brand-*`; nada
   de re-stylear botones por contexto.
3. **Status, Priority, Tag son componentes separados.** Nunca uno mismo
   para los tres.
4. **Tipografía mono solo para datos** (IDs, timestamps, métricas). Cuerpo
   en Google Sans.
5. **Focus ring verde de marca.** `rgba(0,168,94,0.18)` outline + border
   `--admin-green`. Aplicar vía clase `.focus-ring` o heredado en inputs.
6. **Iconos Bootstrap Icons.** Stroke implícito del set; no mezclar con
   SVGs custom salvo el `BrandMark` del rail.
7. **Sidebar oscuro es la única superficie `--gray-900`.** El resto del
   producto vive sobre `#fff` / `--gray-50`.
8. **Filas críticas** llevan la barra roja a la izquierda; nunca cambiar
   el fondo completo de la fila — eso ya lo hace el hover.

---

## 5 · Mapa de archivos

| Aspecto                     | Archivo                                                          |
| --------------------------- | ---------------------------------------------------------------- |
| Tokens + componentes globales | `webroot/css/styles.css` (sección _DESIGN SYSTEM_)             |
| Pills de status/priority    | `webroot/css/badges.css`                                         |
| Layout lista + tabla        | `webroot/css/bulk-actions.css`                                   |
| Rail oscuro                 | `webroot/css/tickets-rail.css`                                   |
| Plantilla lista             | `templates/Tickets/index.php`                                    |
| Cell del rail               | `templates/cell/TicketsSidebar/display.php`                      |
| Helper de badges            | `src/View/Helper/StatusHelper.php`                               |
| Render badge                | `templates/element/tickets/badge.php`                            |
| Constantes (status/priority)| `src/Constants/TicketConstants.php`                              |
| Búsqueda                    | `templates/element/tickets/search_bar.php`                       |
| Paginador                   | `templates/element/pagination.php`                               |

---

## 6 · Próximos componentes a documentar

Cuando se construyan, su spec va aquí antes del código:

- Detalle del ticket (Workspace A.2) — hilo conversacional, composer con
  tabs Responder / Nota interna / Reasignar, sidebar de metadatos.
- Modal / Drawer / Dialog.
- Toast / Notification.
- Skeleton loaders.
- Date / Time pickers.
- Avatar con halo + color personal por usuario.

---

## 7 · Procedencia

- Handoff bundle generado en claude.ai/design, mayo 2026 (chat
  "Proyecto completo", dirección Workspace A).
- Iteraciones del usuario: lista (A.1) → ticket individual (A.2) →
  refinamientos → sistema de diseño → handoff.
- Implementación inicial: commit `8a4711d` —
  `feat(tickets): redesign list view with dark rail, design system tokens`.
