# Fidelidad tipográfica del cuerpo de correos (CSS inline seguro)

**Fecha:** 2026-06-27
**Estado:** Diseño aprobado — pendiente plan de implementación
**Alcance:** Conservar el formato tipográfico básico del cuerpo de los correos
ingeridos (y de los comentarios HTML) tanto al persistir como al renderizar,
para que un mensaje de cualquier proveedor (Gmail, Outlook/Exchange, etc.) se
vea fiel a como se ve en el cliente de correo original.

## Contexto y problema

Hoy el cuerpo HTML de los correos se sanitiza con HTMLPurifier en dos puntos,
con **configuración duplicada e idéntica**:

- `App\Service\Traits\HtmlSanitizerTrait::sanitizeHtml()` — entrada (al persistir
  ingesta de correo y comentarios).
- `App\View\Helper\SanitizeHelper::html()` — salida (al renderizar en la vista),
  como defensa en profundidad.

Ninguno permite el atributo `style`, por lo que toda la tipografía del correo
original (fuente, tamaño, color, alineación, espaciado de párrafos) se descarta.
El contenido se conserva, pero el texto se ve distinto a como llegó en el cliente
de correo. Este es un problema de **fidelidad**, separado del bug de la firma
inline (imágenes `cid:`), que se corrige aparte.

## Objetivo

Permitir un subconjunto **tipográfico básico** de CSS inline, idéntico en las
capas de entrada y salida, validado por HTMLPurifier para que no abra superficie
XSS.

Nivel de fidelidad elegido: **tipográfica básica** (no incluye tablas con
bordes/anchos, colores de fondo ni imágenes redimensionadas).

## Diseño

### Componente nuevo: `App\Html\HtmlSanitizerPolicy`

Archivo: `src/Html/HtmlSanitizerPolicy.php`.

Única fuente de verdad de la configuración de HTMLPurifier. Se ubica en un
namespace neutral (`App\Html\`) porque la consumen tanto la capa Service (el
trait) como la View (el helper); ninguna debe depender de la otra.

API:

```php
final class HtmlSanitizerPolicy
{
    /** Construye un HTMLPurifier con la política única del proyecto. */
    public static function createPurifier(): \HTMLPurifier;
}
```

Configuración encapsulada (constantes privadas de la clase):

- `HTML.Allowed`: el conjunto actual de elementos, añadiendo el atributo `style`
  a los elementos de texto que lo necesitan (`div`, `p`, `span`, `h1`–`h6`,
  `li`, `blockquote`, `a`, `td`, `th`, `table`, `tr`). El atributo `style` se
  declara por-elemento según la sintaxis de `HTML.Allowed` de HTMLPurifier
  (ej. `div[style]`, `span[style]`, …).
- `CSS.AllowedProperties` (allowlist tipográfica):
  `font-family, font-size, font-weight, font-style, text-decoration, color,
  text-align, line-height, margin, margin-top, margin-bottom, margin-left,
  margin-right, padding`.
- Resto de la config existente sin cambios: `HTML.TargetBlank = true`,
  `URI.AllowedSchemes = [http, https, mailto]`, `Attr.AllowedFrameTargets =
  [_blank]`, `Cache.DefinitionImpl = null`.

Excluido a propósito de la allowlist CSS (verificado en pruebas en vivo que
HTMLPurifier los elimina): `position`, `top/left/right/bottom`, `z-index`,
`display`, `float`, `width`, `height`, `background*`, `border*`, `transform`,
`animation`, y cualquier valor con `expression(...)` o `url(...)`.

### Cambios en los consumidores

- `HtmlSanitizerTrait::sanitizeHtml()` → delega en
  `HtmlSanitizerPolicy::createPurifier()`. `truncateSanitizedHtml()` no cambia:
  re-llama internamente a `sanitizeHtml()`, así que hereda la nueva política
  automáticamente.
- `SanitizeHelper::html()` → usa `HtmlSanitizerPolicy::createPurifier()`,
  manteniendo el cacheo del purifier en la instancia del helper.

Tras el cambio, ambas capas comparten **exactamente** la misma allowlist; no
puede haber divergencia entre entrada y salida.

### Flujo de datos (sin cambio estructural)

```
ingesta de correo / comentarios → sanitizeHtml (política única) → BD
render de la vista              → SanitizeHelper::html (misma política) → HTML
```

La única diferencia respecto al estado actual: los estilos inline tipográficos
sobreviven a ambas capas en lugar de eliminarse.

## Seguridad

- HTMLPurifier valida cada declaración CSS contra `CSS.AllowedProperties` y
  contra sus reglas de valores; las propiedades fuera de la allowlist y los
  valores peligrosos (`expression()`, `url(javascript:)`, `position`) se
  eliminan. Verificado con prueba en vivo durante el diseño.
- Se mantiene la doble sanitización (defense-in-depth) con política idéntica.
- **Riesgo aceptado (no de seguridad):** permitir `color` sin
  `background-color` puede producir bajo contraste si un correo usa texto muy
  claro. Es cosmético, poco frecuente y reversible; no se mitiga en este
  alcance.

## Testing

- Nuevo `tests/TestCase/Html/HtmlSanitizerPolicyTest.php`:
  - Conserva propiedades permitidas (`font-family`, `font-size`, `color`,
    `text-align`, `font-weight`, `text-decoration`, `margin`).
  - Elimina propiedades fuera de la allowlist (`position`, `width`, `z-index`).
  - Elimina valores peligrosos (`expression(...)`, `url(javascript:...)`).
  - Sigue eliminando elementos/atributos no permitidos (`<script>`, `onclick`).
- No existen tests que asuman que el atributo `style` se elimina (verificado),
  por lo que el cambio no rompe la suite actual.

## Fuera de alcance

- Niveles de fidelidad "Alta"/"Máxima": tablas con bordes/anchos, colores de
  fondo, imágenes redimensionadas.
- Reparación de tickets históricos ya persistidos sin estilos.
- El bug de la firma inline (`cid:`), corregido por separado en
  `GenericAttachmentTrait::reconcileFilenameToContent()`.

## Archivos afectados

- NUEVO `src/Html/HtmlSanitizerPolicy.php`
- MOD `src/Service/Traits/HtmlSanitizerTrait.php`
- MOD `src/View/Helper/SanitizeHelper.php`
- NUEVO `tests/TestCase/Html/HtmlSanitizerPolicyTest.php`
