# Fidelidad tipográfica del cuerpo de correos — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Permitir un subconjunto tipográfico básico de CSS inline (fuente, tamaño, color, negrita/cursiva/subrayado, alineación, espaciado) en el cuerpo HTML de correos y comentarios, idéntico en sanitización de entrada y de salida.

**Architecture:** Una clase nueva `App\Html\HtmlSanitizerPolicy` centraliza la configuración de HTMLPurifier (hoy duplicada). `HtmlSanitizerTrait` (entrada) y `SanitizeHelper` (salida) construyen su purifier desde ahí, así la allowlist no puede divergir.

**Tech Stack:** PHP 8.5, CakePHP 5.x, ezyang/htmlpurifier, PHPUnit 13.

## Global Constraints

- `declare(strict_types=1);` obligatorio en todo archivo PHP.
- Estilo CakePHP CodeSniffer (`composer cs-check` debe pasar; ≤120 chars/línea salvo strings de allowlist que ya superan ese límite — son una sola constante string, aceptado por el sniffer en línea única).
- No usar `finfo_close()` (deprecado en PHP 8.5) — no aplica aquí, solo recordatorio de estilo del repo.
- CSS allowlist (verbatim): `font-family,font-size,font-weight,font-style,text-decoration,color,text-align,line-height,margin,margin-top,margin-bottom,margin-left,margin-right,padding`.
- Resto de config HTMLPurifier sin cambios: `HTML.TargetBlank=true`, `URI.AllowedSchemes=[http,https,mailto]`, `Attr.AllowedFrameTargets=[_blank]`, `Cache.DefinitionImpl=null`.

---

### Task 1: `HtmlSanitizerPolicy` (fuente única de la política)

**Files:**
- Create: `src/Html/HtmlSanitizerPolicy.php`
- Test: `tests/TestCase/Html/HtmlSanitizerPolicyTest.php`

**Interfaces:**
- Consumes: nada.
- Produces: `App\Html\HtmlSanitizerPolicy::createPurifier(): \HTMLPurifier` — purifier configurado con la política del proyecto (HTML allowlist con `style` en elementos de texto + CSS allowlist tipográfica).

- [ ] **Step 1: Write the failing test**

`tests/TestCase/Html/HtmlSanitizerPolicyTest.php`:

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Html;

use App\Html\HtmlSanitizerPolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(HtmlSanitizerPolicy::class)]
final class HtmlSanitizerPolicyTest extends TestCase
{
    public function testPreservesBasicTypographyOnDiv(): void
    {
        $html = '<div style="font-family: Calibri, Helvetica, sans-serif; font-size: 12pt; color: rgb(34,34,34); text-align: center; margin: 1em 0px;">Hola</div>';
        $out = HtmlSanitizerPolicy::createPurifier()->purify($html);

        $this->assertStringContainsString('font-family:Calibri, Helvetica, sans-serif', $out);
        $this->assertStringContainsString('font-size:12pt', $out);
        $this->assertStringContainsString('color:rgb(34,34,34)', $out);
        $this->assertStringContainsString('text-align:center', $out);
        $this->assertStringContainsString('margin:1em 0px', $out);
    }

    public function testPreservesBoldItalicUnderline(): void
    {
        $out = HtmlSanitizerPolicy::createPurifier()
            ->purify('<p style="font-weight:bold;font-style:italic;text-decoration:underline">t</p>');

        $this->assertStringContainsString('font-weight:bold', $out);
        $this->assertStringContainsString('font-style:italic', $out);
        $this->assertStringContainsString('text-decoration:underline', $out);
    }

    public function testStripsLayoutAndPositioning(): void
    {
        $out = HtmlSanitizerPolicy::createPurifier()
            ->purify('<div style="position:fixed;top:0;left:0;width:100%;height:100%;z-index:9999;color:red">x</div>');

        $this->assertStringContainsString('color:red', $out);
        $this->assertStringNotContainsString('position', $out);
        $this->assertStringNotContainsString('z-index', $out);
        $this->assertStringNotContainsString('width', $out);
        $this->assertStringNotContainsString('height', $out);
    }

    public function testStripsDangerousCssValues(): void
    {
        $expr = HtmlSanitizerPolicy::createPurifier()
            ->purify('<span style="color:expression(alert(1))">x</span>');
        $this->assertStringNotContainsString('expression', $expr);

        $url = HtmlSanitizerPolicy::createPurifier()
            ->purify('<span style="background:url(javascript:alert(1))">x</span>');
        $this->assertStringNotContainsString('javascript', $url);
        $this->assertStringNotContainsString('url(', $url);
    }

    public function testStripsScriptAndEventHandlers(): void
    {
        $script = HtmlSanitizerPolicy::createPurifier()
            ->purify('<script>alert(1)</script><p>ok</p>');
        $this->assertStringNotContainsString('<script', $script);
        $this->assertStringContainsString('<p>ok</p>', $script);

        $onclick = HtmlSanitizerPolicy::createPurifier()
            ->purify('<div onclick="alert(1)" style="color:blue">x</div>');
        $this->assertStringNotContainsString('onclick', $onclick);
        $this->assertStringContainsString('color:blue', $onclick);
    }

    public function testKeepsHttpsLinksWithTargetBlank(): void
    {
        $out = HtmlSanitizerPolicy::createPurifier()
            ->purify('<a href="https://forms.gle/x">link</a>');
        $this->assertStringContainsString('href="https://forms.gle/x"', $out);
        $this->assertStringContainsString('target="_blank"', $out);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/TestCase/Html/HtmlSanitizerPolicyTest.php`
Expected: FAIL — `Class "App\Html\HtmlSanitizerPolicy" not found`.

- [ ] **Step 3: Write the implementation**

`src/Html/HtmlSanitizerPolicy.php`:

```php
<?php
declare(strict_types=1);

namespace App\Html;

use HTMLPurifier;
use HTMLPurifier_Config;

/**
 * Single source of truth for the project's HTMLPurifier policy.
 *
 * Both the Service layer (HtmlSanitizerTrait, at persistence) and the View
 * layer (SanitizeHelper, at render) build their purifier from here so the
 * allowed HTML/CSS can never diverge between input and output sanitization.
 *
 * The CSS allowlist is deliberately limited to basic typography so the body
 * of ingested email keeps its original look (font, size, colour, alignment,
 * spacing) without opening a CSS/XSS surface. Dangerous properties and values
 * (position, expression(), url(javascript:), ...) are dropped by HTMLPurifier.
 */
final class HtmlSanitizerPolicy
{
    /**
     * Allowed elements. The `style` attribute is enabled only on the text
     * elements that legitimately carry typography in email bodies.
     */
    private const HTML_ALLOWED = 'p[style],br,b,i,u,strong,em,a[href|style],ul,ol,li[style],blockquote[style],h1[style],h2[style],h3[style],h4[style],h5[style],h6[style],img[src|alt|width|height],table[style],thead,tbody,tr[style],td[style],th[style],span[style],div[style],pre,code,hr';

    /**
     * Basic-typography CSS allowlist. Everything else (layout, positioning,
     * sizing, backgrounds, borders) is stripped by HTMLPurifier.
     */
    private const CSS_ALLOWED_PROPERTIES = 'font-family,font-size,font-weight,font-style,text-decoration,color,text-align,line-height,margin,margin-top,margin-bottom,margin-left,margin-right,padding';

    /**
     * Build a purifier configured with the project-wide policy.
     */
    public static function createPurifier(): HTMLPurifier
    {
        $config = HTMLPurifier_Config::createDefault();
        $config->set('HTML.Allowed', self::HTML_ALLOWED);
        $config->set('CSS.AllowedProperties', self::CSS_ALLOWED_PROPERTIES);
        $config->set('HTML.TargetBlank', true);
        $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true]);
        $config->set('Attr.AllowedFrameTargets', ['_blank']);
        $config->set('Cache.DefinitionImpl', null);

        return new HTMLPurifier($config);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/TestCase/Html/HtmlSanitizerPolicyTest.php`
Expected: PASS (6 tests). If an assertion fails on exact CSS output formatting, adjust the `assertStringContainsString` needle to the verbatim purifier output (no space after `:`), not the implementation.

- [ ] **Step 5: Style check**

Run: `vendor/bin/phpcs src/Html/HtmlSanitizerPolicy.php tests/TestCase/Html/HtmlSanitizerPolicyTest.php`
Expected: no errors.

- [ ] **Step 6: Commit**

```bash
git add src/Html/HtmlSanitizerPolicy.php tests/TestCase/Html/HtmlSanitizerPolicyTest.php
git commit -m "feat: add centralized HtmlSanitizerPolicy with basic-typography CSS allowlist"
```

---

### Task 2: `HtmlSanitizerTrait` delega en la Policy

**Files:**
- Modify: `src/Service/Traits/HtmlSanitizerTrait.php`
- Test: `tests/TestCase/Service/Traits/HtmlSanitizerTraitTest.php`

**Interfaces:**
- Consumes: `HtmlSanitizerPolicy::createPurifier()` (Task 1).
- Produces: `sanitizeHtml()` ahora conserva el CSS tipográfico de la allowlist. `truncateSanitizedHtml()` sin cambios (re-llama a `sanitizeHtml`, hereda la política).

- [ ] **Step 1: Add a `sanitize()` wrapper to the test harness and a failing test**

En `tests/TestCase/Service/Traits/HtmlSanitizerTraitTest.php`, dentro de la clase anónima de `makeHarness()`, añadir el wrapper junto al existente `truncate()`:

```php
            public function sanitize(string $html): string
            {
                return $this->sanitizeHtml($html);
            }
```

Y añadir este test a la clase:

```php
    public function testSanitizePreservesBasicTypography(): void
    {
        $out = $this->harness->sanitize('<p style="color:rgb(34,34,34);text-align:center">x</p>');

        $this->assertStringContainsString('color:rgb(34,34,34)', $out);
        $this->assertStringContainsString('text-align:center', $out);
    }
```

Nota: el harness usa `$this->harness` ya inicializado en `setUp()` del archivo (mismo patrón que `truncate`).

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter testSanitizePreservesBasicTypography tests/TestCase/Service/Traits/HtmlSanitizerTraitTest.php`
Expected: FAIL — el `style` se elimina con la config actual, así que `color`/`text-align` no aparecen.

- [ ] **Step 3: Delegate `sanitizeHtml` to the Policy**

En `src/Service/Traits/HtmlSanitizerTrait.php`:

Reemplazar el cuerpo de `sanitizeHtml()`:

```php
    private function sanitizeHtml(string $html): string
    {
        return HtmlSanitizerPolicy::createPurifier()->purify($html);
    }
```

Actualizar los `use` del archivo: eliminar `use HTMLPurifier;` y `use HTMLPurifier_Config;` (ya no se referencian en el trait), y añadir:

```php
use App\Html\HtmlSanitizerPolicy;
```

- [ ] **Step 4: Run the trait tests to verify pass + no regressions**

Run: `vendor/bin/phpunit tests/TestCase/Service/Traits/HtmlSanitizerTraitTest.php`
Expected: PASS (incluye el nuevo test y los de `truncateSanitizedHtml`).

- [ ] **Step 5: Style + static analysis**

Run: `vendor/bin/phpcs src/Service/Traits/HtmlSanitizerTrait.php tests/TestCase/Service/Traits/HtmlSanitizerTraitTest.php`
Run: `vendor/bin/phpstan analyse src/Service/Traits/HtmlSanitizerTrait.php`
Expected: sin errores.

- [ ] **Step 6: Commit**

```bash
git add src/Service/Traits/HtmlSanitizerTrait.php tests/TestCase/Service/Traits/HtmlSanitizerTraitTest.php
git commit -m "refactor: HtmlSanitizerTrait delegates to HtmlSanitizerPolicy"
```

---

### Task 3: `SanitizeHelper` (salida) usa la Policy

**Files:**
- Modify: `src/View/Helper/SanitizeHelper.php`
- Test: `tests/TestCase/View/Helper/SanitizeHelperTest.php` (nuevo)

**Interfaces:**
- Consumes: `HtmlSanitizerPolicy::createPurifier()` (Task 1).
- Produces: `SanitizeHelper::html(?string): string` con la misma política que la entrada.

- [ ] **Step 1: Write the failing test**

`tests/TestCase/View/Helper/SanitizeHelperTest.php`:

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\View\Helper;

use App\View\Helper\SanitizeHelper;
use Cake\View\View;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SanitizeHelper::class)]
final class SanitizeHelperTest extends TestCase
{
    private SanitizeHelper $helper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->helper = new SanitizeHelper(new View());
    }

    public function testReturnsEmptyStringForNullOrEmpty(): void
    {
        $this->assertSame('', $this->helper->html(null));
        $this->assertSame('', $this->helper->html(''));
    }

    public function testPreservesBasicTypography(): void
    {
        $out = $this->helper->html('<div style="font-size:12pt;text-align:center">x</div>');

        $this->assertStringContainsString('font-size:12pt', $out);
        $this->assertStringContainsString('text-align:center', $out);
    }

    public function testStripsScript(): void
    {
        $out = $this->helper->html('<script>alert(1)</script><p>ok</p>');

        $this->assertStringNotContainsString('<script', $out);
        $this->assertStringContainsString('<p>ok</p>', $out);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/TestCase/View/Helper/SanitizeHelperTest.php`
Expected: FAIL en `testPreservesBasicTypography` — el helper actual elimina `style`.

- [ ] **Step 3: Use the Policy in `SanitizeHelper::html`**

En `src/View/Helper/SanitizeHelper.php`, reemplazar la construcción inline del purifier por la Policy:

```php
    public function html(?string $html): string
    {
        if ($html === null || $html === '') {
            return '';
        }

        $this->purifier ??= HtmlSanitizerPolicy::createPurifier();

        return $this->purifier->purify($html);
    }
```

Actualizar los `use`: añadir `use App\Html\HtmlSanitizerPolicy;` y eliminar `use HTMLPurifier_Config;`. **Conservar** `use HTMLPurifier;` porque la propiedad `private ?HTMLPurifier $purifier` sigue usando ese tipo.

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/TestCase/View/Helper/SanitizeHelperTest.php`
Expected: PASS (3 tests).

- [ ] **Step 5: Style + static analysis**

Run: `vendor/bin/phpcs src/View/Helper/SanitizeHelper.php tests/TestCase/View/Helper/SanitizeHelperTest.php`
Run: `vendor/bin/phpstan analyse src/View/Helper/SanitizeHelper.php`
Expected: sin errores.

- [ ] **Step 6: Commit**

```bash
git add src/View/Helper/SanitizeHelper.php tests/TestCase/View/Helper/SanitizeHelperTest.php
git commit -m "refactor: SanitizeHelper uses HtmlSanitizerPolicy"
```

---

### Task 4: Verificación final de la suite

**Files:** ninguno (verificación).

- [ ] **Step 1: Run full suite**

Run: `vendor/bin/phpunit`
Expected: todo verde, sin regresiones (la única deprecation conocida es `Cake\I18n\FrozenTime`, ajena a este cambio).

- [ ] **Step 2: Full style check**

Run: `composer cs-check`
Expected: sin errores nuevos (el warning preexistente de `UserHelper.php` no es parte de este cambio).
