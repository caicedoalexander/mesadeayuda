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

| Token          | Valor  | Uso                                                  |
| -------------- | ------ | ---------------------------------------------------- |
| `--radius-xs`  | `4px`  | Micro-chips, swatches, dividers, hits clickeables    |
| `--radius-sm`  | `6px`  | Tags, chips, dropdown items, botones de fila         |
| `--radius-md`  | `8px`  | Botones, inputs, cards pequeñas                      |
| `--radius-lg`  | `12px` | Cards, modales, contenedores grandes                 |
| _999px_        | —      | Pills (status, priority) — siempre full              |

Valores literales **NO permitidos** fuera de geometría obvia (`height/2`
para círculos perfectos). Si una propuesta de diseño exige un radio
intermedio (p. ej. `10px`), discutirlo antes de hardcodearlo — el
sistema sólo conoce los 4 tokens.

### 1.5 · Sombras

| Token         | Valor                                                                              |
| ------------- | ---------------------------------------------------------------------------------- |
| `--shadow-sm` | `0 1px 2px rgba(0,0,0,0.04)`                                                       |
| `--shadow-md` | `0 4px 6px -1px rgba(0,0,0,0.1), 0 2px 4px -1px rgba(0,0,0,0.06)`                  |
| `--shadow-lg` | `0 10px 15px -3px rgba(0,0,0,0.1), 0 4px 6px -2px rgba(0,0,0,0.05)`                |

### 1.6 · Transiciones

| Token                | Valor                                    | Uso                                                       |
| -------------------- | ---------------------------------------- | --------------------------------------------------------- |
| `--transition`       | `all 0.2s cubic-bezier(0.4, 0, 0.2, 1)`  | layout, transformaciones, hover sobre cards               |
| `--transition-fast`  | `0.15s ease`                             | micro-interacciones de color/background, hover de chips   |

Aplicar `--transition-fast` como `transition: <propiedad> var(--transition-fast)`
en lugar de `--transition` cuando sólo cambia una propiedad de color y se
quiere respuesta inmediata. No introducir duraciones intermedias
(`0.12s` / `0.14s` / `0.18s`) — usar uno de los dos tokens.

### 1.7 · Focus ring

| Token           | Valor                                            | Uso                                  |
| --------------- | ------------------------------------------------ | ------------------------------------ |
| `--focus-ring`  | `0 0 0 3px rgba(0, 168, 94, 0.18)`               | `box-shadow` de inputs y triggers    |

Aplicar como `box-shadow: var(--focus-ring)` cuando un elemento recibe
foco; combinar con `border-color: var(--admin-green)`. Es la única
opacidad/spread canónica — no introducir 0.12 / 0.16 ni cambiar la
extensión a 2 / 4 px sin justificación.

### 1.8 · Tipografía

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

**Estado deshabilitado** (`:disabled` o `.disabled`): `opacity: 0.5`,
`cursor: not-allowed`, sin cambios de hover. Aplica a `primary`,
`secondary` y `danger`. Para bloquear interacciones de un trigger
contextual (ej. acciones masivas sin selección) se usa el atributo
`disabled` del botón — nunca se oculta el botón con `d-none` para
evitar saltos de layout.

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

Ver sección **6 · Empty states** (componente reutilizable
`element('empty_state')`).

### 3.4 · Paginación

`.pagination-links` dentro del footer del card. Botones compactos
(`padding: 4px 9px`), página activa con fondo verde sólido.

---

## 4 · Overlays

> Estilos en `webroot/css/components.css` (sección _08 · OVERLAYS_).
> Bootstrap 5 sigue manejando la mecánica (`data-bs-toggle="modal"`),
> sólo se sobrescribe la skin.

### 4.1 · Modal centrado — `.modal-dialog-centered-small`

Ancho máximo **420px**. Header blanco con título en `--gray-900` + icono
verde 16px. Body 16px de padding, label 11px uppercase. Inputs
(`.form-control` / `.form-select`) dentro del body usan
`--radius-sm`, borde `--gray-200`, padding `0.55rem 0.75rem`, foco con
anillo `rgba(0,168,94,.12)` y borde `--admin-green`. Footer en
`--gray-50` con dos botones (`.btn-brand-ghost btn-brand-sm` izquierda,
`.btn-brand-primary btn-brand-sm` derecha).

```html
<div class="modal fade" id="…">
  <div class="modal-dialog modal-dialog-centered modal-dialog-centered-small">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-…"></i> Título</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">…</div>
      <div class="modal-footer">
        <button class="btn-brand-ghost btn-brand-sm" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn-brand-primary btn-brand-sm">Confirmar</button>
      </div>
    </div>
  </div>
</div>
```

### 4.2 · Confirm dialog — `.modal.confirm-dialog`

Para acciones destructivas. Sin header tradicional: el body usa
`.confirm-icon` (círculo `--danger-soft`) + `.confirm-text` con
`.confirm-title` y `.confirm-message`. CTA destructiva en
`.btn-brand-danger`.

```html
<div class="modal fade confirm-dialog" id="…">
  <div class="modal-dialog modal-dialog-centered modal-dialog-centered-small">
    <div class="modal-content">
      <div class="modal-body">
        <div class="confirm-icon"><i class="bi bi-exclamation-triangle-fill"></i></div>
        <div class="confirm-text">
          <div class="confirm-title">¿Eliminar #1284?</div>
          <div class="confirm-message">Esta acción no se puede deshacer.</div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn-brand-ghost btn-brand-sm" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn-brand-danger btn-brand-sm"><i class="bi bi-trash"></i> Eliminar</button>
      </div>
    </div>
  </div>
</div>
```

### 4.3 · Popover / menú — `.app-popover`

Aparece sobre un trigger, ancho mínimo 200px, sombra `--shadow-md`.
Cada item `.app-popover-item`; variante destructiva `.danger`.
Separadores `.app-popover-divider`.

### 4.4 · Cuándo usar cada uno

| Overlay         | Cuándo                                                  |
| --------------- | ------------------------------------------------------- |
| `modal`         | Acciones que **bloquean** el flujo (crear, importar).   |
| `confirm-dialog`| Confirmaciones de **una sola decisión** destructiva.    |
| `app-popover`   | Acciones contextuales **rápidas** (menús de fila).      |

---

## 5 · Toasts / Notificaciones

> Estilos en `components.css` (sección _09 · TOASTS_). Markup vive en
> `templates/element/flash/{success,error,warning,info,default}.php`,
> rendereado por `FlashHelper` de CakePHP. Auto-hide en
> `webroot/js/flash-messages.js`.

Ancho fijo 360px, anclados arriba-derecha (top 55px). Barra lateral de
4px del color del tono + icono en pill cuadrado redondeado + título +
mensaje + cerrar. 3s de auto-ocultamiento (`AUTO_HIDE_DELAY`).

### 5.1 · Variantes

| Clase              | Tono     | Token barra/pill                       | Uso típico                   |
| ------------------ | -------- | -------------------------------------- | ---------------------------- |
| `.app-toast.success` | success  | `--admin-green` / `--admin-green-soft` | Operación completada         |
| `.app-toast.info`    | info     | `--admin-blue` / `--admin-blue-soft`   | Nueva asignación, novedad    |
| `.app-toast.warning` | warning  | `--admin-orange` / `--admin-orange-soft` | SLA por vencer, advertencias |
| `.app-toast.danger`  | error    | `--danger-color` / `--danger-soft`     | Error de operación           |
| `.app-toast.neutral` | default  | `--gray-400` / `--gray-100`            | Notificación genérica        |

### 5.2 · Toast con acción

Añadir `.app-toast-action` antes del `.app-toast-close` para una CTA
secundaria (Deshacer, Ver lista). En ese caso, prolongar el delay a
6 s o desactivar auto-hide.

### 5.3 · Inline banner — `.app-banner`

Para alertas globales del workspace que **no** desaparecen.
Modificadores `.info`, `.danger`, `.success`. Estructura:
icono + `.app-banner-title` + `.app-banner-message` + `.app-banner-action`.

### 5.4 · Regla de uso

- Anchor: `top: 55px` (bajo la topbar). Stack hacia abajo, gap 8px.
- Máximo 5 visibles simultáneos. Los más viejos se cierran primero.
- No usar para validaciones de formulario — esas son inline.

---

## 6 · Empty states

> Estilos en `components.css` (sección _10 · EMPTY STATES_). Componente
> reutilizable en `templates/element/empty_state.php`.

### 6.1 · Variante centrada — `.app-empty`

Icono circular de 64px con tono semántico, título en 16px bold, mensaje
descriptivo y CTA opcional.

```php
<?= $this->element('empty_state', [
    'icon'    => 'ticket-detailed',
    'tone'    => 'neutral',          // success|neutral|danger|info|warning
    'title'   => 'Nada por aquí',
    'message' => 'No hay tickets en esta vista.',
    'action'  => $this->Html->link('+ Crear', […], ['class' => 'btn-brand-primary btn-brand-sm']),
]) ?>
```

### 6.2 · Variante inline — `.app-empty-inline`

Una sola línea, para tablas vacías sin romper el layout.

```php
<?= $this->element('empty_state', [
    'inline' => true,
    'icon'   => 'people',
    'title'  => 'No hay usuarios registrados.',
]) ?>
```

### 6.3 · Tonos

| Tono      | Cuándo                                                     |
| --------- | ---------------------------------------------------------- |
| `success` | Primer uso / vacío saludable (no hay nada porque todo está bien). |
| `neutral` | Sin datos por defecto.                                     |
| `info`    | Vista filtrada o secundaria sin resultados.                |
| `warning` | Algo necesita acción pero no es error.                     |
| `danger`  | Error de carga / fallo recuperable.                        |

---

## 7 · Skeleton loaders

> Estilos en `components.css` (sección _11 · SKELETON LOADERS_).
> Animación shimmer 1.4 s lineal infinita.

### 7.1 · Bloques base

| Clase                  | Tamaño por defecto         |
| ---------------------- | -------------------------- |
| `.skeleton-line`       | 12px alto, 100% ancho      |
| `.skeleton-line-sm`    | 9px alto                   |
| `.skeleton-line-lg`    | 18px alto, radio 6px       |
| `.skeleton-pill`       | 20×70px, radio 10px        |
| `.skeleton-avatar`     | 36×36, círculo             |
| `.skeleton-avatar-sm`  | 22×22, círculo             |
| `.skeleton-bar`        | 4px alto, radio 2px        |

Todas heredan de `.skeleton` (el `background` + `animation`). Para
tamaños personalizados, override por estilo inline (`style="width: 78%"`).

### 7.2 · Composiciones documentadas

- `.skeleton-ticket-row` — grid 6 columnas reemplazando una fila real.
- `.skeleton-detail` + `.skeleton-detail-pills` + `.skeleton-detail-author`.
- `.skeleton-activity` + `.skeleton-activity-item` — timeline del
  sidebar derecho (usado en `right_sidebar.php`).
- `.skeleton-stats` + `.skeleton-stat` — cards de KPIs.

### 7.3 · Regla de uso

- Sólo en la **primera carga > 300 ms**. Cargas más rápidas: ir directo
  a contenido — el parpadeo loading→contenido se siente más lento.
- Nunca usar skeleton en re-fetch silencioso o refresh de tabla.
- La forma del skeleton debe imitar la del contenido — nunca un
  rectángulo genérico.

---

## 8 · Tooltips

> Estilos en `components.css` (sección _12 · TOOLTIPS_).

Para iconos sin label visible, atajos o aclaraciones. Máximo **4 palabras**.
Aparece a los 250 ms de hover/focus, desaparece al salir.

### 8.1 · Variante simple — `data-tip` (CSS puro)

```html
<button data-tip="Resolver ticket" data-tip-side="top">
    <i class="bi bi-check-lg"></i>
</button>
```

Direcciones soportadas: `top` (default), `bottom`, `left`, `right`.
Pegada al borde del trigger con 6 px de gap. Pill oscuro `--gray-900` /
texto blanco / 11 px / shadow.

### 8.2 · Con keycap — `.app-tip-bubble`

Para casos que necesitan combinar texto con una tecla de atajo, usar
markup explícito en lugar de `data-tip` (CSS attr no acepta HTML):

```html
<span class="app-tip-bubble">
    Crear ticket <span class="app-tip-keycap">N</span>
</span>
```

### 8.3 · Regla de uso

- El tooltip **nunca sustituye** un label visible.
- Sólo para iconos puros, atajos, o aclaraciones que no caben en la UI.
- Máximo 4 palabras. Si necesitas más, repiensa la UI.

---

## 9 · File upload

> Estilos en `components.css` (sección _13 · FILE UPLOAD_). Aplicado al
> composer en `templates/element/tickets/reply_editor.php` + lógica de
> drag-and-drop en `webroot/js/tickets-view.js (bindComposerDropzone)`.

### 9.1 · Dropzone completo — `.app-dropzone`

Idle: borde `2px dashed --gray-300` sobre `--gray-50`. Hover: borde
verde sólido. Active (con archivo arrastrándose encima): clase
`.is-active` aplica fondo `--admin-green-soft` y borde verde sólido.

```html
<label class="app-dropzone">
    <span class="app-dropzone-icon"><i class="bi bi-upload"></i></span>
    <div class="app-dropzone-title">
        Arrastra archivos o <span class="app-dropzone-link">selecciona del equipo</span>
    </div>
    <div class="app-dropzone-hint">PNG, JPG, PDF hasta 10 MB</div>
    <input type="file" multiple hidden />
</label>
```

### 9.2 · Overlay drag — `.app-drop-overlay`

Para containers que ya tienen contenido (como el composer) y sólo
quieren mostrar feedback al arrastrar. El padre necesita
`position: relative`. JS añade `.is-active` durante el drag.

```html
<div class="composer-body" style="position: relative">
    <div class="app-drop-overlay" id="composer-drop-overlay">
        <span><i class="bi bi-arrow-up-circle"></i> Suelta los archivos aquí</span>
    </div>
    <!-- textarea, etc. -->
</div>
```

### 9.3 · File item — `.file-item`

Card horizontal: icono cuadrado coloreado por estado + nombre + tamaño
(o progreso) + botón eliminar.

| Estado          | Clase        | Color icono                                       |
| --------------- | ------------ | ------------------------------------------------- |
| Pendiente       | `.file-item` | gris (`--gray-100` / `--gray-700`)                |
| Subiendo        | `.uploading` | azul (`--admin-blue-soft` / `--admin-blue-ink`)   |
| Subido          | `.done`      | verde (`--admin-green-soft` / `--admin-green-ink`)|
| Error           | `.error`     | rojo (`--danger-soft` / `--danger-color`)         |

Para mostrar progreso, dentro de `.file-item-info` reemplazar
`.file-item-size` por `.file-item-progress` con `.file-item-progress-bar`
y `.file-item-progress-pct`.

### 9.4 · Chip compacto — `.file-chip`

Variante reducida sólo-lectura, para mostrar adjuntos en un mensaje
existente. Click descarga el archivo.

```html
<a href="…" class="file-chip">
    <span class="file-chip-icon"><i class="bi bi-paperclip"></i></span>
    <span class="file-chip-name">cafetera-14.jpg</span>
    <span class="file-chip-size">1.2 MB</span>
</a>
```

---

## 10 · Rich-text toolbar

> Estilos en `components.css` (sección _14 · RICH-TEXT TOOLBAR_). **Sólo
> CSS** — listo para cuando se integre un editor real (Quill, Trix,
> TipTap, etc.). Hoy el composer usa textarea plana.

### 10.1 · Estructura

```html
<div class="rt-toolbar">
    <button class="rt-btn"><strong>B</strong></button>
    <button class="rt-btn active" aria-pressed="true"><em>I</em></button>
    <button class="rt-btn"><u>U</u></button>
    <span class="rt-divider"></span>
    <button class="rt-btn"><i class="bi bi-list-ul"></i></button>
    <button class="rt-btn"><i class="bi bi-list-ol"></i></button>
    <button class="rt-btn"><i class="bi bi-quote"></i></button>
    <span class="rt-divider"></span>
    <button class="rt-btn"><i class="bi bi-link-45deg"></i></button>
    <button class="rt-btn"><i class="bi bi-code"></i></button>
</div>
```

### 10.2 · Variantes

- `.rt-toolbar` — toolbar suelto (inline o agrupado por sección).
- `.rt-toolbar.full` — pegado al header de un `.rt-editor`, fondo
  `--gray-50` y radio sólo arriba.
- `.rt-btn.active` o `[aria-pressed="true"]` — estilo presionado.
- `.rt-btn-text` — botón con texto en vez de glifo (p. ej. _Plantilla_).

### 10.3 · Editor completo — `.rt-editor`

Container con toolbar arriba (`.rt-toolbar.full`) + `.rt-editor-body`
de mínimo 120 px + `.rt-editor-footer` con acciones (`adjuntar`,
contador, keycap `⌘⏎`, botón `Enviar`).

### 10.4 · Regla de uso

- Toolbar liviano — sólo lo esencial. Markdown shortcuts
  (`**negrita**`, `*it*`, `> cita`, `- lista`) son más descubribles
  que un toolbar enorme.
- Si se integra un editor real, pasar el HTML resultante por
  `HtmlSanitizerTrait` antes de persistir (CLAUDE.md).

---

## 11 · Paginación

> Estilos en `components.css` (sección _15 · PAGINACIÓN_). Markup en
> `templates/element/pagination.php`.

### 11.1 · Clásica con números — `.app-pagination`

Lista de `<li>` con botones tnum (mono), página activa en
`--admin-green`. Soporta elipsis `…` para condensar rangos.

CakePHP `Paginator::numbers([…])` con `modulus: 2, first: 1, last: 1`
ya produce la estructura correcta. El parámetro `ellipsis` genera
`<li class="ellipsis"><span>…</span></li>`.

### 11.2 · Simple — `.app-pagination-simple`

Para listas pequeñas: sólo _Anterior_ / `5 / 24` / _Siguiente_.

### 11.3 · Resumen + page-size — `.app-pagination-summary`

Para footer de tabla. Texto _Mostrando X–Y de N_ + selector
_Por página_ + flechas prev/next.

### 11.4 · Jump-to-page — `.app-pagination-jump`

Para datasets de cientos de páginas: input numérico inline.
Focus ring verde `--admin-green` automático.

---

## 12 · Iconografía

> El proyecto usa **Bootstrap Icons** (no Lucide). El sistema de diseño
> de claude.ai/design referencia Lucide; aquí está el mapeo y las
> reglas que aplican a cualquier set con stroke configurable.

### 12.1 · Reglas

- **Stroke** 1.6 (default) o 1.8 (más visibles). Nunca menos de 1.4 ni
  más de 2.0. Bootstrap Icons usa filled o lineal según el ítem; cuando
  hay variantes (`bi-check-lg` vs `bi-check-circle`), preferir la lineal
  fuera de pills coloridas.
- **Tamaños recomendados** según contexto:

  | Contexto                         | Tamaño |
  | -------------------------------- | ------ |
  | Rows densas / chips              | 12 px  |
  | Inputs, badges, botones sm       | 14 px  |
  | Botones default                  | 16 px  |
  | Hero / dropzone / empty state    | 24–32 px |

- **Color** vía `currentColor`. Cambia el color del padre y el icono
  lo sigue. Nunca hardcodear `fill="…"` en el SVG.

### 12.2 · Set en uso

| Semántica            | Bootstrap Icon                  |
| -------------------- | ------------------------------- |
| Ticket / soporte     | `bi-ticket-detailed`            |
| Buscar               | `bi-search`                     |
| Crear, añadir        | `bi-plus-lg`                    |
| Resolver, confirmar  | `bi-check-lg`                   |
| Cerrar, quitar       | `bi-x-lg`                       |
| Expandir, dropdown   | `bi-chevron-down`               |
| Avanzar              | `bi-chevron-right`              |
| Retroceder           | `bi-chevron-left`               |
| Filtros              | `bi-funnel`                     |
| Eliminar             | `bi-trash`                      |
| Información          | `bi-info-circle`                |
| Advertencia          | `bi-exclamation-triangle-fill`  |
| SLA, hora            | `bi-clock`                      |
| Fecha                | `bi-calendar`                   |
| Bandeja              | `bi-inbox`                      |
| Adjuntos             | `bi-paperclip`                  |
| Subir archivo        | `bi-upload`                     |
| Plantillas           | `bi-file-earmark-text`          |
| Enlace               | `bi-link-45deg`                 |
| Código inline        | `bi-code`                       |
| Cita                 | `bi-quote`                      |
| Lista viñetas        | `bi-list-ul`                    |
| Lista numerada       | `bi-list-ol`                    |

---

## 13 · Reglas no-negociables

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

## 14 · Mapa de archivos

| Aspecto                     | Archivo                                                          |
| --------------------------- | ---------------------------------------------------------------- |
| Tokens + componentes globales | `webroot/css/styles.css` (sección _DESIGN SYSTEM_)             |
| Componentes globales (08–15) | `webroot/css/components.css` (toasts, modales, empty, skeleton, tooltip, upload, rt-toolbar, pagination) |
| Drag-and-drop composer      | `webroot/js/tickets-view.js` (`bindComposerDropzone`)            |
| Pills de status/priority    | `webroot/css/badges.css`                                         |
| Layout lista + tabla        | `webroot/css/bulk-actions.css`                                   |
| Detalle de ticket           | `webroot/css/tickets-view.css`                                   |
| Rail oscuro                 | `webroot/css/tickets-rail.css`                                   |
| Plantilla lista             | `templates/Tickets/index.php`                                    |
| Plantilla detalle           | `templates/Tickets/view.php`                                     |
| Cell del rail               | `templates/cell/TicketsSidebar/display.php`                      |
| Flash messages              | `templates/element/flash/*.php`                                  |
| Empty state reutilizable    | `templates/element/empty_state.php`                              |
| Modales bulk                | `templates/element/tickets/bulk_modals.php`                      |
| Helper de badges            | `src/View/Helper/StatusHelper.php`                               |
| Render badge                | `templates/element/tickets/badge.php`                            |
| Constantes (status/priority)| `src/Constants/TicketConstants.php`                              |
| Búsqueda                    | `templates/element/tickets/search_bar.php`                       |
| Paginador                   | `templates/element/pagination.php`                               |

---

## 15 · Próximos componentes a documentar

Cuando se construyan, su spec va aquí antes del código:

- Drawer lateral (variante de overlay, edición profunda sin perder contexto).
- Date / Time pickers — tokens y comportamiento de calendar popover.
- Rich-text editor real (integración con Quill o Trix; sanitizar HTML
  con `HtmlSanitizerTrait`).
- Combobox / autocomplete con fetch remoto.
- Multi-select con chips.
- Stepper / wizard.
- Mention picker (@usuario en composer).
- Cheat-sheet de atajos.

---

## 16 · Procedencia

- Handoff bundle generado en claude.ai/design, mayo 2026 (chat
  "Proyecto completo", dirección Workspace A).
- Iteraciones del usuario: lista (A.1) → ticket individual (A.2) →
  refinamientos → sistema de diseño → handoff.
- Implementación inicial: commit `8a4711d` —
  `feat(tickets): redesign list view with dark rail, design system tokens`.
