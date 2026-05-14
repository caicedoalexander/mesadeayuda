# CSS Variables Unification

Refactorización para unificar el sistema de variables CSS, eliminando valores hardcodeados y duplicaciones.

## Objetivo

Consistencia visual y mantenibilidad: cambiar una variable debe actualizar todos los colores relacionados en todo el sistema.

## Sistema de Variables Expandido

Añadir a `:root` en `styles.css`:

```css
:root {
    /* ===== BRAND COLORS ===== */
    --admin-green: #00A85E;
    --admin-green-light: #00D477;
    --admin-green-hover: #008f50;
    --admin-green-rgb: 0, 168, 94;
    --admin-orange: #CD6A15;
    --admin-orange-light: #F07D2D;
    --admin-orange-rgb: 205, 106, 21;
    --admin-blue: #0066cc;

    /* ===== GRAYSCALE ===== */
    --gray-50: #F9FAFB;
    --gray-100: #F3F4F6;
    --gray-200: #E5E7EB;
    --gray-300: #D1D5DB;
    --gray-400: #9CA3AF;
    --gray-500: #6B7280;
    --gray-600: #4B5563;
    --gray-700: #374151;
    --gray-800: #1F2937;
    --gray-900: #111827;

    /* ===== BOOTSTRAP-COMPATIBLE SEMANTIC ===== */
    --primary-color: #0d6efd;
    --success-color: #198754;
    --danger-color: #dc3545;
    --warning-color: #ffc107;
    --info-color: #0dcaf0;

    /* ===== SURFACES (Bootstrap grays) ===== */
    --surface-light: #f8f9fa;
    --surface-hover: #e9ecef;
    --border-color: #dee2e6;
    --border-hover: #adb5bd;
    --text-muted: #6c757d;
}
```

## Orden de Migración

1. `styles.css` — archivo principal, ~20 valores hardcodeados
2. `bulk-actions.css` — 4 valores hardcodeados
3. `tickets-view.css` — ~15 valores Bootstrap hardcodeados
4. `login.css` — valores RGBA usando variables RGB
5. `admin/edit-user.css` — eliminar variables locales duplicadas
6. `admin/preview-template.css` — eliminar redefinición de variables
7. Archivos restantes — `badges.css`, `admin/tags.css`, etc.

Cada archivo será un commit separado.

## Patrones de Reemplazo

| Valor Hardcodeado | Variable |
|-------------------|----------|
| `#00A85E` | `var(--admin-green)` |
| `#008f50` | `var(--admin-green-hover)` |
| `#CD6A15` | `var(--admin-orange)` |
| `#f8f9fa` | `var(--surface-light)` |
| `#e9ecef` | `var(--surface-hover)` |
| `#dee2e6` | `var(--border-color)` |
| `#adb5bd` | `var(--border-hover)` |
| `#6c757d` | `var(--text-muted)` |
| `#dc3545` | `var(--danger-color)` |
| `#495057` | `var(--gray-700)` |
| `#212529` | `var(--gray-900)` |

Para gradientes: `rgba(var(--admin-green-rgb), 0.5)`

## Criterios de Éxito

1. Cero instancias de valores hardcodeados del mapeo fuera de `:root`
2. `edit-user.css` y `preview-template.css` sin redefinición de variables
3. Sin regresiones visuales en: login, lista de tickets, vista de ticket, panel admin

## Exclusiones

- `vendor/` — código de terceros
- CSS inline en templates `.php` — fuera de alcance
