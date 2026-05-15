# Plantillas de correo en código — Plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Spec:** `docs/superpowers/specs/2026-05-15-email-templates-in-code-design.md`

**Goal:** Migrar plantillas de correo de la tabla `email_templates` a clases PHP con sistema de componentes reutilizables, e implementar el rediseño visual nuevo de 4 plantillas (creación, estado, comentario, actualización).

**Architecture:** Componentes genéricos (`EmailFrame`, `Greeting`, `Card`, `CtaButton`, `InfoBox`, `Avatar`, `Pill`) en `src/Notification/Email/Component/`. Componentes específicos de ticket (`TicketCard`, `StatusTransition`, `CommentBlock`, `PriorityArrow`) en `src/Notification/Email/Ticket/Component/`. Las 4 plantillas componen estos bloques y devuelven `RenderedEmail { subject, bodyHtml }` vía `TemplateRegistry`. `EmailService` deja de cargar de BD y consume el registry. La UI admin queda como previsualizador (sin edición). Migración elimina la tabla.

**Tech Stack:** PHP 8.5, CakePHP 5.x, PHPUnit, MySQL/MariaDB, phpcs (CakePHP ruleset), phpstan.

**Convenciones del proyecto:**
- `declare(strict_types=1);` en cada archivo.
- Antes de cada commit: `composer cs-fix && composer cs-check`.
- Tests son **pure unit** (no DB, no fixtures) — extienden `PHPUnit\Framework\TestCase`, no `Cake\TestSuite\TestCase`. Las entidades CakePHP se construyen con `new Ticket(); $ticket->set([...], ['guard' => false]);`.
- Tests viven en `tests/TestCase/...` reflejando la estructura de `src/`.
- Mensajes de commit en español, formato `tipo(scope): descripción` (e.g. `feat(notification): ...`, `chore(email): ...`).

---

## Mapa de archivos

### Creados

```
src/Notification/Email/
├── EmailBrand.php
├── EmailTemplate.php              (interfaz)
├── EmailTheme.php
├── PreviewFixture.php
├── RenderedEmail.php
├── TemplateContext.php
├── TemplateRegistry.php
├── Admin/
│   └── TemplateDescriptor.php
├── Component/
│   ├── Avatar.php
│   ├── Card.php
│   ├── CtaButton.php
│   ├── EmailFrame.php             (incluye EmailFooter como método privado)
│   ├── Greeting.php
│   ├── InfoBox.php
│   └── Pill.php
└── Ticket/
    ├── Component/
    │   ├── CommentBlock.php
    │   ├── PriorityArrow.php
    │   ├── StatusTransition.php
    │   └── TicketCard.php
    └── Template/
        ├── TicketCommentAddedTemplate.php
        ├── TicketCreatedTemplate.php
        ├── TicketStatusChangedTemplate.php
        └── TicketUpdatedTemplate.php

config/Migrations/
└── YYYYMMDDHHMMSS_DropEmailTemplatesTable.php

webroot/img/
└── logo-mesa-ayuda.svg

tests/TestCase/Notification/Email/
├── EmailBrandTest.php
├── EmailThemeTest.php
├── RenderedEmailTest.php
├── TemplateContextTest.php
├── TemplateRegistryTest.php
├── Component/
│   ├── AvatarTest.php
│   ├── CardTest.php
│   ├── CtaButtonTest.php
│   ├── EmailFrameTest.php
│   ├── GreetingTest.php
│   ├── InfoBoxTest.php
│   └── PillTest.php
└── Ticket/
    ├── Component/
    │   ├── CommentBlockTest.php
    │   ├── PriorityArrowTest.php
    │   ├── StatusTransitionTest.php
    │   └── TicketCardTest.php
    └── Template/
        ├── TicketCommentAddedTemplateTest.php
        ├── TicketCreatedTemplateTest.php
        ├── TicketStatusChangedTemplateTest.php
        └── TicketUpdatedTemplateTest.php
```

### Modificados

```
src/Service/EmailService.php                                   (rewire completo)
src/Service/Renderer/NotificationRenderer.php                  (drop 2 métodos)
src/Service/TicketNotificationService.php                      (drop case 'assignment')
src/Listener/TicketNotificationListener.php                    (drop onAssigned)
src/Controller/Admin/EmailTemplatesController.php              (rewrite a 2 acciones)
templates/Admin/EmailTemplates/index.php                       (rewrite)
templates/Admin/EmailTemplates/preview.php                     (rewrite)
src/Domain/Event/TicketAssigned.php                            (PHPDoc nota)
```

### Eliminados

```
src/Service/EmailTemplateRenderer.php
src/Model/Entity/EmailTemplate.php
src/Model/Table/EmailTemplatesTable.php
templates/Admin/EmailTemplates/edit.php
```

---

## Task 1: Asset — logo SVG en webroot

**Files:**
- Create: `webroot/img/logo-mesa-ayuda.svg`

El SVG está en el bundle de handoff que se borró tras el brainstorming. Lo regeneramos con el contenido del diseño original.

- [ ] **Step 1: Verificar si el bundle aún existe**

```bash
ls webroot/img/ 2>/dev/null
```

Si `logo-mesa-ayuda.svg` ya existe, saltar a Step 3.

- [ ] **Step 2: Crear el SVG**

Pedir al usuario que copie `mesa-de-ayuda/project/assets/logo-mesa-ayuda.svg` del bundle original a `webroot/img/logo-mesa-ayuda.svg`. Si no lo tiene a mano, usar este placeholder mínimo (verde de marca, círculo con M) y pedir que lo reemplace antes del merge:

```svg
<?xml version="1.0" encoding="UTF-8"?>
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" width="64" height="64">
  <circle cx="32" cy="32" r="30" fill="#00A85E"/>
  <text x="32" y="42" font-family="system-ui, sans-serif" font-size="32" font-weight="700"
        text-anchor="middle" fill="#ffffff">M</text>
</svg>
```

- [ ] **Step 3: Commit**

```bash
git add webroot/img/logo-mesa-ayuda.svg
git commit -m "chore(email): add Mesa de Ayuda logo SVG for email templates"
```

---

## Task 2: `RenderedEmail` VO

**Files:**
- Create: `src/Notification/Email/RenderedEmail.php`
- Test: `tests/TestCase/Notification/Email/RenderedEmailTest.php`

- [ ] **Step 1: Escribir test fallido**

`tests/TestCase/Notification/Email/RenderedEmailTest.php`:

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Notification\Email;

use App\Notification\Email\RenderedEmail;
use PHPUnit\Framework\TestCase;

final class RenderedEmailTest extends TestCase
{
    public function testExposesSubjectAndBodyHtml(): void
    {
        $email = new RenderedEmail('Subject line', '<p>Body</p>');

        self::assertSame('Subject line', $email->subject);
        self::assertSame('<p>Body</p>', $email->bodyHtml);
    }
}
```

- [ ] **Step 2: Correr el test y verificar que falla**

```bash
vendor/bin/phpunit tests/TestCase/Notification/Email/RenderedEmailTest.php
```

Esperado: FAIL — `Class "App\Notification\Email\RenderedEmail" not found`.

- [ ] **Step 3: Implementar el VO**

`src/Notification/Email/RenderedEmail.php`:

```php
<?php
declare(strict_types=1);

namespace App\Notification\Email;

/**
 * Result of rendering an EmailTemplate. Immutable subject + html body pair.
 */
final readonly class RenderedEmail
{
    public function __construct(
        public string $subject,
        public string $bodyHtml,
    ) {
    }
}
```

- [ ] **Step 4: Correr el test y verificar que pasa**

```bash
vendor/bin/phpunit tests/TestCase/Notification/Email/RenderedEmailTest.php
```

Esperado: PASS.

- [ ] **Step 5: cs-fix y commit**

```bash
composer cs-fix && composer cs-check
git add src/Notification/Email/RenderedEmail.php tests/TestCase/Notification/Email/RenderedEmailTest.php
git commit -m "feat(notification): add RenderedEmail value object"
```

---

## Task 3: `EmailTheme` VO con 4 factories

**Files:**
- Create: `src/Notification/Email/EmailTheme.php`
- Test: `tests/TestCase/Notification/Email/EmailThemeTest.php`

- [ ] **Step 1: Escribir test fallido**

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Notification\Email;

use App\Notification\Email\EmailTheme;
use PHPUnit\Framework\TestCase;

final class EmailThemeTest extends TestCase
{
    public function testCreacionFactoryReturnsOrangePalette(): void
    {
        $theme = EmailTheme::creacion();
        self::assertSame('#CD6A15', $theme->accent);
        self::assertSame('#FCEFE0', $theme->accentSoft);
        self::assertSame('#6b3306', $theme->accentInk);
        self::assertSame('Nuevo ticket', $theme->tag);
    }

    public function testEstadoFactoryReturnsBluePalette(): void
    {
        $theme = EmailTheme::estado();
        self::assertSame('#0066cc', $theme->accent);
        self::assertSame('#E3EFFC', $theme->accentSoft);
        self::assertSame('#0a3a78', $theme->accentInk);
        self::assertSame('Cambio de estado', $theme->tag);
    }

    public function testComentarioFactoryReturnsGreenPalette(): void
    {
        $theme = EmailTheme::comentario();
        self::assertSame('#00A85E', $theme->accent);
        self::assertSame('#E6F7EE', $theme->accentSoft);
        self::assertSame('#00432a', $theme->accentInk);
        self::assertSame('Nuevo comentario', $theme->tag);
    }

    public function testActualizacionFactoryReturnsPurplePalette(): void
    {
        $theme = EmailTheme::actualizacion();
        self::assertSame('#7c3aed', $theme->accent);
        self::assertSame('#F0EBFE', $theme->accentSoft);
        self::assertSame('#3c1d8a', $theme->accentInk);
        self::assertSame('Actualización', $theme->tag);
    }
}
```

- [ ] **Step 2: Correr el test, verificar FAIL** (clase no existe)

```bash
vendor/bin/phpunit tests/TestCase/Notification/Email/EmailThemeTest.php
```

- [ ] **Step 3: Implementar el VO**

`src/Notification/Email/EmailTheme.php`:

```php
<?php
declare(strict_types=1);

namespace App\Notification\Email;

/**
 * Color palette + tag for a notification type.
 * Pure data, immutable; build via the named factories.
 */
final readonly class EmailTheme
{
    public function __construct(
        public string $accent,
        public string $accentSoft,
        public string $accentInk,
        public string $tag,
    ) {
    }

    public static function creacion(): self
    {
        return new self('#CD6A15', '#FCEFE0', '#6b3306', 'Nuevo ticket');
    }

    public static function estado(): self
    {
        return new self('#0066cc', '#E3EFFC', '#0a3a78', 'Cambio de estado');
    }

    public static function comentario(): self
    {
        return new self('#00A85E', '#E6F7EE', '#00432a', 'Nuevo comentario');
    }

    public static function actualizacion(): self
    {
        return new self('#7c3aed', '#F0EBFE', '#3c1d8a', 'Actualización');
    }
}
```

- [ ] **Step 4: Test pasa**

```bash
vendor/bin/phpunit tests/TestCase/Notification/Email/EmailThemeTest.php
```

- [ ] **Step 5: cs-fix y commit**

```bash
composer cs-fix && composer cs-check
git add src/Notification/Email/EmailTheme.php tests/TestCase/Notification/Email/EmailThemeTest.php
git commit -m "feat(notification): add EmailTheme with 4 brand palettes"
```

---

## Task 4: `EmailBrand` (constantes + logoUrl)

**Files:**
- Create: `src/Notification/Email/EmailBrand.php`
- Test: `tests/TestCase/Notification/Email/EmailBrandTest.php`

- [ ] **Step 1: Test fallido**

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Notification\Email;

use App\Notification\Email\EmailBrand;
use Cake\Core\Configure;
use PHPUnit\Framework\TestCase;

final class EmailBrandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Configure::write('App.fullBaseUrl', 'https://mesa.example.com');
    }

    public function testConstantsHaveExpectedValues(): void
    {
        self::assertSame('Operadora Cafetera S.A.S.', EmailBrand::ORG_NAME);
        self::assertSame('MESA DE AYUDA · OPERADORA CAFETERA', EmailBrand::ORG_TAG_LINE);
        self::assertSame('Carrera 43A #1-50, Medellín', EmailBrand::ORG_ADDRESS);
        self::assertSame('901.234.567-8', EmailBrand::ORG_NIT);
        self::assertSame('soporte@operadoracafetera.com', EmailBrand::SUPPORT_EMAIL);
        self::assertSame('Mesa de Ayuda', EmailBrand::HEADER_TITLE);
        self::assertSame('Soporte Interno', EmailBrand::HEADER_SUBTITLE);
    }

    public function testLogoUrlReturnsAbsoluteUrlFromFullBaseUrl(): void
    {
        $url = EmailBrand::logoUrl();
        self::assertStringStartsWith('https://mesa.example.com', $url);
        self::assertStringEndsWith('/img/logo-mesa-ayuda.svg', $url);
    }
}
```

- [ ] **Step 2: FAIL**

```bash
vendor/bin/phpunit tests/TestCase/Notification/Email/EmailBrandTest.php
```

- [ ] **Step 3: Implementar**

`src/Notification/Email/EmailBrand.php`:

```php
<?php
declare(strict_types=1);

namespace App\Notification\Email;

use Cake\Core\Configure;

/**
 * Static branding constants used by email templates' header and footer.
 *
 * Intentionally a code-side configuration: changing these requires a deploy,
 * which is fine for a single-organization installation.
 */
final class EmailBrand
{
    public const ORG_NAME = 'Operadora Cafetera S.A.S.';
    public const ORG_TAG_LINE = 'MESA DE AYUDA · OPERADORA CAFETERA';
    public const ORG_ADDRESS = 'Carrera 43A #1-50, Medellín';
    public const ORG_NIT = '901.234.567-8';
    public const SUPPORT_EMAIL = 'soporte@operadoracafetera.com';
    public const HEADER_TITLE = 'Mesa de Ayuda';
    public const HEADER_SUBTITLE = 'Soporte Interno';

    /**
     * Absolute URL to the logo asset. Reads `App.fullBaseUrl` from Configure
     * so email clients can load it regardless of the recipient's network.
     */
    public static function logoUrl(): string
    {
        $base = rtrim((string)Configure::read('App.fullBaseUrl', ''), '/');

        return $base . '/img/logo-mesa-ayuda.svg';
    }
}
```

- [ ] **Step 4: Test pasa**

- [ ] **Step 5: cs-fix y commit**

```bash
composer cs-fix && composer cs-check
git add src/Notification/Email/EmailBrand.php tests/TestCase/Notification/Email/EmailBrandTest.php
git commit -m "feat(notification): add EmailBrand constants and logoUrl helper"
```

---

## Task 5: `TemplateContext` VO

**Files:**
- Create: `src/Notification/Email/TemplateContext.php`
- Test: `tests/TestCase/Notification/Email/TemplateContextTest.php`

- [ ] **Step 1: Test fallido**

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Notification\Email;

use App\Model\Entity\Ticket;
use App\Notification\Email\TemplateContext;
use PHPUnit\Framework\TestCase;

final class TemplateContextTest extends TestCase
{
    private function ticket(): Ticket
    {
        $t = new Ticket();
        $t->set(['id' => 1, 'ticket_number' => 'TKT-1'], ['guard' => false]);

        return $t;
    }

    public function testRequiredFieldsExposed(): void
    {
        $ctx = new TemplateContext(
            ticket: $this->ticket(),
            ticketUrl: 'https://example.com/t/1',
            recipientName: 'Alex',
        );

        self::assertSame('TKT-1', $ctx->ticket->ticket_number);
        self::assertSame('https://example.com/t/1', $ctx->ticketUrl);
        self::assertSame('Alex', $ctx->recipientName);
        self::assertNull($ctx->comment);
        self::assertNull($ctx->oldStatus);
        self::assertNull($ctx->newStatus);
        self::assertNull($ctx->actor);
        self::assertSame([], $ctx->commentAttachments);
    }

    public function testOptionalFieldsAccepted(): void
    {
        $ctx = new TemplateContext(
            ticket: $this->ticket(),
            ticketUrl: 'u',
            recipientName: 'r',
            oldStatus: 'open',
            newStatus: 'resolved',
        );

        self::assertSame('open', $ctx->oldStatus);
        self::assertSame('resolved', $ctx->newStatus);
    }
}
```

- [ ] **Step 2: FAIL**

- [ ] **Step 3: Implementar**

`src/Notification/Email/TemplateContext.php`:

```php
<?php
declare(strict_types=1);

namespace App\Notification\Email;

use App\Model\Entity\Ticket;
use App\Model\Entity\TicketComment;
use App\Model\Entity\User;

/**
 * Input bag for EmailTemplate::render().
 *
 * Security contract: `$comment->body` MUST be already sanitized by
 * HtmlSanitizerTrait before reaching this VO. CommentBlock inserts it raw.
 * All other string fields are treated as user-controlled text and escaped
 * inside the components with `htmlspecialchars`.
 */
final readonly class TemplateContext
{
    /**
     * @param array<int, mixed> $commentAttachments Attachments scoped to the comment (for hint only; not inlined)
     */
    public function __construct(
        public Ticket $ticket,
        public string $ticketUrl,
        public string $recipientName,
        public ?TicketComment $comment = null,
        public ?string $oldStatus = null,
        public ?string $newStatus = null,
        public ?User $actor = null,
        public array $commentAttachments = [],
    ) {
    }
}
```

- [ ] **Step 4: Test pasa**

- [ ] **Step 5: cs-fix y commit**

```bash
composer cs-fix && composer cs-check
git add src/Notification/Email/TemplateContext.php tests/TestCase/Notification/Email/TemplateContextTest.php
git commit -m "feat(notification): add TemplateContext VO for email templates"
```

---

## Task 6: `Pill` component (badge redondeado con dot opcional)

**Files:**
- Create: `src/Notification/Email/Component/Pill.php`
- Test: `tests/TestCase/Notification/Email/Component/PillTest.php`

- [ ] **Step 1: Test fallido**

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Notification\Email\Component;

use App\Notification\Email\Component\Pill;
use PHPUnit\Framework\TestCase;

final class PillTest extends TestCase
{
    public function testRendersLabelWithBackgroundAndForegroundColors(): void
    {
        $html = Pill::render(
            label: 'Pendiente',
            bg: '#E3EFFC',
            fg: '#0a3a78',
        );
        self::assertStringContainsString('Pendiente', $html);
        self::assertStringContainsString('background:#E3EFFC', $html);
        self::assertStringContainsString('color:#0a3a78', $html);
    }

    public function testRendersOptionalDot(): void
    {
        $html = Pill::render(
            label: 'Pendiente',
            bg: '#E3EFFC',
            fg: '#0a3a78',
            dotColor: '#0066cc',
        );
        self::assertStringContainsString('background:#0066cc', $html);
        self::assertStringContainsString('border-radius:50%', $html);
    }

    public function testWithoutDotOmitsDotSpan(): void
    {
        $html = Pill::render('X', '#fff', '#000');
        self::assertStringNotContainsString('border-radius:50%', $html);
    }

    public function testEscapesLabel(): void
    {
        $html = Pill::render('<script>x</script>', '#fff', '#000');
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testForStatusKnownStatusReturnsPillWithCorrectLabel(): void
    {
        $html = Pill::forStatus('pendiente');
        self::assertStringContainsString('Pendiente', $html);
    }

    public function testForStatusUnknownFallsBackToCapitalizedKey(): void
    {
        $html = Pill::forStatus('foo');
        self::assertStringContainsString('Foo', $html);
    }
}
```

- [ ] **Step 2: FAIL**

- [ ] **Step 3: Implementar**

`src/Notification/Email/Component/Pill.php`:

```php
<?php
declare(strict_types=1);

namespace App\Notification\Email\Component;

/**
 * Rounded badge with optional dot. Used for status, priority and tag labels.
 * Generic — does not depend on any domain entity.
 */
final class Pill
{
    /**
     * Status key → palette + label (mirrors the design's STATUS_THEME).
     *
     * @var array<string, array{bg:string,fg:string,dot:string,label:string}>
     */
    private const STATUS_THEME = [
        'nuevo'       => ['bg' => '#FCEFE0', 'fg' => '#6b3306', 'dot' => '#CD6A15', 'label' => 'Nuevo'],
        'abierto'     => ['bg' => '#FCE4E6', 'fg' => '#7a1a25', 'dot' => '#dc3545', 'label' => 'Abierto'],
        'pendiente'   => ['bg' => '#E3EFFC', 'fg' => '#0a3a78', 'dot' => '#0066cc', 'label' => 'Pendiente'],
        'resuelto'    => ['bg' => '#E6F7EE', 'fg' => '#00432a', 'dot' => '#00A85E', 'label' => 'Resuelto'],
    ];

    public static function render(
        string $label,
        string $bg,
        string $fg,
        ?string $dotColor = null,
    ): string {
        $style = 'display:inline-flex;align-items:center;gap:6px;padding:4px 10px;'
            . 'border-radius:999px;background:' . $bg . ';color:' . $fg . ';'
            . 'font-size:11px;font-weight:600;line-height:1;letter-spacing:0.1px;white-space:nowrap;';

        $dot = '';
        if ($dotColor !== null) {
            $dot = '<span style="width:6px;height:6px;border-radius:50%;background:' . $dotColor . ';"></span>';
        }

        return '<span style="' . $style . '">' . $dot . htmlspecialchars($label, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</span>';
    }

    /**
     * Convenience: render a pill for a known status key, falling back to
     * a capitalized form of the key when unknown.
     */
    public static function forStatus(string $statusKey): string
    {
        if (isset(self::STATUS_THEME[$statusKey])) {
            $t = self::STATUS_THEME[$statusKey];

            return self::render($t['label'], $t['bg'], $t['fg'], $t['dot']);
        }

        return self::render(ucfirst($statusKey), '#F3F4F6', '#374151');
    }
}
```

- [ ] **Step 4: Test pasa**

- [ ] **Step 5: cs-fix y commit**

```bash
composer cs-fix && composer cs-check
git add src/Notification/Email/Component/Pill.php tests/TestCase/Notification/Email/Component/PillTest.php
git commit -m "feat(notification): add Pill email component (generic badge)"
```

---

## Task 7: `Avatar` component

**Files:**
- Create: `src/Notification/Email/Component/Avatar.php`
- Test: `tests/TestCase/Notification/Email/Component/AvatarTest.php`

- [ ] **Step 1: Test fallido**

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Notification\Email\Component;

use App\Notification\Email\Component\Avatar;
use PHPUnit\Framework\TestCase;

final class AvatarTest extends TestCase
{
    public function testRendersInitialsWithGivenColorAndSize(): void
    {
        $html = Avatar::render(initials: 'AC', color: '#00A85E', size: 32);
        self::assertStringContainsString('AC', $html);
        self::assertStringContainsString('background:#00A85E', $html);
        self::assertStringContainsString('width:32px', $html);
        self::assertStringContainsString('height:32px', $html);
    }

    public function testInitialsFromNameTakesFirstLettersOfFirstTwoWords(): void
    {
        self::assertSame('AC', Avatar::initialsFromName('Alexander Caicedo'));
        self::assertSame('JL', Avatar::initialsFromName('Julián Loaiza Restrepo'));
        self::assertSame('S', Avatar::initialsFromName('Sistema'));
        self::assertSame('', Avatar::initialsFromName(''));
    }

    public function testEscapesInitials(): void
    {
        $html = Avatar::render('<x>', '#000', 32);
        self::assertStringNotContainsString('<x>', $html);
        self::assertStringContainsString('&lt;x&gt;', $html);
    }
}
```

- [ ] **Step 2: FAIL**

- [ ] **Step 3: Implementar**

`src/Notification/Email/Component/Avatar.php`:

```php
<?php
declare(strict_types=1);

namespace App\Notification\Email\Component;

/**
 * Colored circle with white initials.
 * Generic — receives initials + color directly; does not know about User.
 */
final class Avatar
{
    public static function render(string $initials, string $color, int $size = 32): string
    {
        $fontSize = (int)round($size * 0.4);
        $style = 'display:inline-flex;align-items:center;justify-content:center;'
            . 'width:' . $size . 'px;height:' . $size . 'px;border-radius:50%;'
            . 'background:' . $color . ';color:#fff;font-weight:600;'
            . 'font-size:' . $fontSize . 'px;letter-spacing:-0.3px;flex-shrink:0;';

        return '<span style="' . $style . '">'
            . htmlspecialchars($initials, ENT_QUOTES | ENT_HTML5, 'UTF-8')
            . '</span>';
    }

    /**
     * Extract up to 2 uppercase initials from a person's name.
     */
    public static function initialsFromName(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return '';
        }

        $parts = preg_split('/\s+/', $name) ?: [];
        $initials = '';
        foreach (array_slice($parts, 0, 2) as $part) {
            $initials .= mb_substr($part, 0, 1);
        }

        return mb_strtoupper($initials);
    }
}
```

- [ ] **Step 4: Test pasa**

- [ ] **Step 5: cs-fix y commit**

```bash
composer cs-fix && composer cs-check
git add src/Notification/Email/Component/Avatar.php tests/TestCase/Notification/Email/Component/AvatarTest.php
git commit -m "feat(notification): add Avatar email component"
```

---

## Task 8: `CtaButton` component

**Files:**
- Create: `src/Notification/Email/Component/CtaButton.php`
- Test: `tests/TestCase/Notification/Email/Component/CtaButtonTest.php`

- [ ] **Step 1: Test fallido**

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Notification\Email\Component;

use App\Notification\Email\Component\CtaButton;
use PHPUnit\Framework\TestCase;

final class CtaButtonTest extends TestCase
{
    public function testRendersLabelWithAccentBackgroundAndHref(): void
    {
        $html = CtaButton::render(
            label: 'Ver mi ticket',
            accent: '#CD6A15',
            url: 'https://example.com/t/1',
        );
        self::assertStringContainsString('Ver mi ticket', $html);
        self::assertStringContainsString('background:#CD6A15', $html);
        self::assertStringContainsString('href="https://example.com/t/1"', $html);
    }

    public function testIncludesFallbackUrlLine(): void
    {
        $html = CtaButton::render('Open', '#000', 'https://example.com/abc');
        self::assertStringContainsString('https://example.com/abc', $html);
        self::assertStringContainsString('pega este enlace', $html);
    }

    public function testEscapesLabelAndUrl(): void
    {
        $html = CtaButton::render('<X>', '#000', 'https://e.com/"><script>');
        self::assertStringNotContainsString('<X>', $html);
        self::assertStringNotContainsString('<script>', $html);
    }
}
```

- [ ] **Step 2: FAIL**

- [ ] **Step 3: Implementar**

`src/Notification/Email/Component/CtaButton.php`:

```php
<?php
declare(strict_types=1);

namespace App\Notification\Email\Component;

/**
 * Large accent-colored call-to-action button + plain-text fallback URL line.
 * Generic — receives label, color and URL.
 */
final class CtaButton
{
    public static function render(string $label, string $accent, string $url): string
    {
        $safeLabel = htmlspecialchars($label, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $safeUrl = htmlspecialchars($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $buttonStyle = 'display:inline-block;padding:12px 22px;border-radius:9px;'
            . 'background:' . $accent . ';color:#fff;font-size:14px;font-weight:600;'
            . 'text-decoration:none;line-height:1;'
            . 'box-shadow:0 4px 12px -3px ' . $accent . '66, inset 0 1px 0 rgba(255,255,255,0.2);';

        $arrow = ' <span style="display:inline-block;margin-left:6px;">&rarr;</span>';

        $fallback = '<div style="font-size:11px;color:#9CA3AF;margin-top:10px;'
            . 'font-family:Geist Mono,Menlo,Consolas,monospace;">'
            . 'o pega este enlace en tu navegador: ' . $safeUrl
            . '</div>';

        return '<div style="margin:8px 0 6px;">'
            . '<a href="' . $safeUrl . '" style="' . $buttonStyle . '">' . $safeLabel . $arrow . '</a>'
            . $fallback
            . '</div>';
    }
}
```

- [ ] **Step 4: Test pasa**

- [ ] **Step 5: cs-fix y commit**

```bash
composer cs-fix && composer cs-check
git add src/Notification/Email/Component/CtaButton.php tests/TestCase/Notification/Email/Component/CtaButtonTest.php
git commit -m "feat(notification): add CtaButton email component"
```

---

## Task 9: `InfoBox` component

**Files:**
- Create: `src/Notification/Email/Component/InfoBox.php`
- Test: `tests/TestCase/Notification/Email/Component/InfoBoxTest.php`

- [ ] **Step 1: Test fallido**

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Notification\Email\Component;

use App\Notification\Email\Component\InfoBox;
use PHPUnit\Framework\TestCase;

final class InfoBoxTest extends TestCase
{
    public function testRendersUppercaseLabelAndContent(): void
    {
        $html = InfoBox::render('Próximos pasos', '<p>Hola</p>', InfoBox::VARIANT_DASHED);
        self::assertStringContainsString('Próximos pasos', $html);
        self::assertStringContainsString('text-transform:uppercase', $html);
        self::assertStringContainsString('<p>Hola</p>', $html);
    }

    public function testDashedVariantUsesDashedBorder(): void
    {
        $html = InfoBox::render('L', '', InfoBox::VARIANT_DASHED);
        self::assertStringContainsString('border:1px dashed', $html);
    }

    public function testSolidVariantUsesSolidBorder(): void
    {
        $html = InfoBox::render('L', '', InfoBox::VARIANT_SOLID);
        self::assertStringContainsString('border:1px solid', $html);
    }

    public function testSoftVariantUsesAccentSoftBackgroundWhenProvided(): void
    {
        $html = InfoBox::render('L', '', InfoBox::VARIANT_SOFT, accentSoft: '#F0EBFE');
        self::assertStringContainsString('background:#F0EBFE', $html);
    }

    public function testEscapesLabel(): void
    {
        $html = InfoBox::render('<x>', '', InfoBox::VARIANT_DASHED);
        self::assertStringNotContainsString('<x>', $html);
    }
}
```

- [ ] **Step 2: FAIL**

- [ ] **Step 3: Implementar**

`src/Notification/Email/Component/InfoBox.php`:

```php
<?php
declare(strict_types=1);

namespace App\Notification\Email\Component;

/**
 * Boxed section with small uppercase label and raw HTML content.
 *
 * Variants:
 *   dashed → "Próximos pasos" style (dashed border, light bg).
 *   solid  → simple bordered box.
 *   soft   → accent-tinted background (needs $accentSoft).
 */
final class InfoBox
{
    public const VARIANT_DASHED = 'dashed';
    public const VARIANT_SOLID = 'solid';
    public const VARIANT_SOFT = 'soft';

    public static function render(
        string $label,
        string $contentHtml,
        string $variant = self::VARIANT_SOLID,
        ?string $accentSoft = null,
    ): string {
        $border = $variant === self::VARIANT_DASHED ? '1px dashed #E5E7EB' : '1px solid #E5E7EB';
        $bg = $variant === self::VARIANT_SOFT && $accentSoft !== null
            ? $accentSoft
            : '#FAFAFA';

        $boxStyle = 'border:' . $border . ';border-radius:10px;'
            . 'padding:16px 18px;margin-bottom:20px;background:' . $bg . ';';
        $labelStyle = 'font-size:11px;font-weight:600;color:#6B7280;'
            . 'letter-spacing:0.5px;text-transform:uppercase;margin-bottom:10px;';

        return '<div style="' . $boxStyle . '">'
            . '<div style="' . $labelStyle . '">'
            . htmlspecialchars($label, ENT_QUOTES | ENT_HTML5, 'UTF-8')
            . '</div>'
            . $contentHtml
            . '</div>';
    }
}
```

- [ ] **Step 4: Test pasa**

- [ ] **Step 5: cs-fix y commit**

```bash
composer cs-fix && composer cs-check
git add src/Notification/Email/Component/InfoBox.php tests/TestCase/Notification/Email/Component/InfoBoxTest.php
git commit -m "feat(notification): add InfoBox email component"
```

---

## Task 10: `Greeting` component

**Files:**
- Create: `src/Notification/Email/Component/Greeting.php`
- Test: `tests/TestCase/Notification/Email/Component/GreetingTest.php`

- [ ] **Step 1: Test fallido**

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Notification\Email\Component;

use App\Notification\Email\Component\Greeting;
use PHPUnit\Framework\TestCase;

final class GreetingTest extends TestCase
{
    public function testRendersHeadlineH1AndIntroParagraph(): void
    {
        $html = Greeting::render(
            headline: 'Tu ticket fue creado',
            intro: 'Hemos recibido tu solicitud.',
            recipientName: 'Alexander',
        );
        self::assertStringContainsString('Tu ticket fue creado', $html);
        self::assertStringContainsString('Hemos recibido tu solicitud.', $html);
        self::assertStringContainsString('Hola <strong', $html);
        self::assertStringContainsString('Alexander', $html);
    }

    public function testEscapesHeadlineIntroAndName(): void
    {
        $html = Greeting::render('<h>', '<i>', '<n>');
        self::assertStringNotContainsString('<h>', $html);
        self::assertStringNotContainsString('<i>', $html);
        self::assertStringNotContainsString('<n>', $html);
    }

    public function testEmptyRecipientNameOmitsHola(): void
    {
        $html = Greeting::render('H', 'I', '');
        self::assertStringNotContainsString('Hola', $html);
        self::assertStringContainsString('I', $html);
    }
}
```

- [ ] **Step 2: FAIL**

- [ ] **Step 3: Implementar**

`src/Notification/Email/Component/Greeting.php`:

```php
<?php
declare(strict_types=1);

namespace App\Notification\Email\Component;

/**
 * Headline (h1) + intro paragraph with "Hola {name}," prefix.
 * Generic — used at the top of every email body.
 */
final class Greeting
{
    public static function render(string $headline, string $intro, string $recipientName): string
    {
        $h = htmlspecialchars($headline, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $i = htmlspecialchars($intro, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $n = htmlspecialchars(trim($recipientName), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $headlineStyle = 'font-size:26px;font-weight:700;letter-spacing:-0.6px;'
            . 'color:#111827;margin:0;line-height:1.2;';
        $introStyle = 'font-size:14px;color:#4B5563;line-height:1.6;'
            . 'margin:12px 0 0;max-width:520px;';

        $intro = $n === ''
            ? $i
            : 'Hola <strong style="color:#111827;font-weight:600;">' . $n . '</strong>, ' . $i;

        return '<div style="margin-bottom:22px;">'
            . '<h1 style="' . $headlineStyle . '">' . $h . '</h1>'
            . '<p style="' . $introStyle . '">' . $intro . '</p>'
            . '</div>';
    }
}
```

- [ ] **Step 4: Test pasa**

- [ ] **Step 5: cs-fix y commit**

```bash
composer cs-fix && composer cs-check
git add src/Notification/Email/Component/Greeting.php tests/TestCase/Notification/Email/Component/GreetingTest.php
git commit -m "feat(notification): add Greeting email component"
```

---

## Task 11: `Card` component (genérico)

**Files:**
- Create: `src/Notification/Email/Component/Card.php`
- Test: `tests/TestCase/Notification/Email/Component/CardTest.php`

- [ ] **Step 1: Test fallido**

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Notification\Email\Component;

use App\Notification\Email\Component\Card;
use PHPUnit\Framework\TestCase;

final class CardTest extends TestCase
{
    public function testRendersHeaderStripBodyAndMeta(): void
    {
        $html = Card::render(
            headerLeftHtml: '<span>#1284</span>',
            headerRightHtml: '<span>14 may</span>',
            title: 'Cafetera #14 no enciende',
            tags: ['Mantenimiento', 'Sucursal Norte'],
            metaColumns: [
                ['label' => 'Solicitante', 'valueHtml' => '<b>Alex</b>'],
                ['label' => 'Asignado a',  'valueHtml' => '<b>Maira</b>'],
            ],
        );

        self::assertStringContainsString('#1284', $html);
        self::assertStringContainsString('14 may', $html);
        self::assertStringContainsString('Cafetera #14 no enciende', $html);
        self::assertStringContainsString('Mantenimiento', $html);
        self::assertStringContainsString('Sucursal Norte', $html);
        self::assertStringContainsString('SOLICITANTE', strtoupper($html));
        self::assertStringContainsString('<b>Alex</b>', $html);
        self::assertStringContainsString('<b>Maira</b>', $html);
    }

    public function testEscapesTitleAndTags(): void
    {
        $html = Card::render('', '', '<X>', ['<T>'], []);
        self::assertStringNotContainsString('<X>', $html);
        self::assertStringNotContainsString('<T>', $html);
    }

    public function testOmitsTagsRowWhenEmpty(): void
    {
        $html = Card::render('', '', 'Title', [], []);
        self::assertStringNotContainsString('padding-top:10px', $html);
    }
}
```

- [ ] **Step 2: FAIL**

- [ ] **Step 3: Implementar**

`src/Notification/Email/Component/Card.php`:

```php
<?php
declare(strict_types=1);

namespace App\Notification\Email\Component;

/**
 * Generic card: header strip (left/right slots) + title + optional tags + optional meta grid.
 * No knowledge of Ticket — callers (e.g. TicketCard) compose it with domain data.
 */
final class Card
{
    /**
     * @param list<string> $tags Plain text tags rendered as gray pills.
     * @param list<array{label:string, valueHtml:string}> $metaColumns 0, 1 or 2 columns.
     */
    public static function render(
        string $headerLeftHtml,
        string $headerRightHtml,
        string $title,
        array $tags = [],
        array $metaColumns = [],
    ): string {
        $headerStyle = 'display:flex;align-items:center;gap:10px;padding:10px 16px;'
            . 'background:#FAFAF9;border-bottom:1px solid #F3F4F6;';
        $titleStyle = 'font-size:16px;font-weight:600;color:#111827;'
            . 'line-height:1.3;letter-spacing:-0.1px;';

        $tagsHtml = '';
        if (!empty($tags)) {
            $tagsHtml = '<div style="margin-top:10px;">';
            foreach ($tags as $tag) {
                $tagsHtml .= '<span style="display:inline-block;margin-right:6px;'
                    . 'padding:3px 9px;border-radius:6px;background:#F3F4F6;'
                    . 'color:#374151;font-size:11px;font-weight:500;">'
                    . htmlspecialchars($tag, ENT_QUOTES | ENT_HTML5, 'UTF-8')
                    . '</span>';
            }
            $tagsHtml .= '</div>';
        }

        $metaHtml = '';
        if (!empty($metaColumns)) {
            $cellLabel = 'font-size:10px;font-weight:600;color:#9CA3AF;'
                . 'letter-spacing:0.6px;text-transform:uppercase;margin-bottom:6px;';
            $metaHtml = '<table role="presentation" cellspacing="0" cellpadding="0" '
                . 'style="width:100%;border-top:1px solid #F3F4F6;border-collapse:collapse;">'
                . '<tr>';
            $count = count($metaColumns);
            foreach ($metaColumns as $i => $col) {
                $border = $i < $count - 1 ? 'border-right:1px solid #F3F4F6;' : '';
                $metaHtml .= '<td style="padding:12px 16px;vertical-align:top;width:50%;' . $border . '">'
                    . '<div style="' . $cellLabel . '">'
                    . htmlspecialchars($col['label'], ENT_QUOTES | ENT_HTML5, 'UTF-8')
                    . '</div>'
                    . $col['valueHtml']
                    . '</td>';
            }
            $metaHtml .= '</tr></table>';
        }

        return '<div style="border:1px solid #E5E7EB;border-radius:10px;'
            . 'overflow:hidden;margin-bottom:20px;">'
            . '<div style="' . $headerStyle . '">'
            . '<div style="flex:1;">' . $headerLeftHtml . '</div>'
            . '<div>' . $headerRightHtml . '</div>'
            . '</div>'
            . '<div style="padding:16px 16px 14px;">'
            . '<div style="' . $titleStyle . '">'
            . htmlspecialchars($title, ENT_QUOTES | ENT_HTML5, 'UTF-8')
            . '</div>'
            . $tagsHtml
            . '</div>'
            . $metaHtml
            . '</div>';
    }
}
```

- [ ] **Step 4: Test pasa**

- [ ] **Step 5: cs-fix y commit**

```bash
composer cs-fix && composer cs-check
git add src/Notification/Email/Component/Card.php tests/TestCase/Notification/Email/Component/CardTest.php
git commit -m "feat(notification): add generic Card email component"
```

---

## Task 12: `EmailFrame` (incluye footer)

**Files:**
- Create: `src/Notification/Email/Component/EmailFrame.php`
- Test: `tests/TestCase/Notification/Email/Component/EmailFrameTest.php`

- [ ] **Step 1: Test fallido**

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Notification\Email\Component;

use App\Notification\Email\Component\EmailFrame;
use App\Notification\Email\EmailBrand;
use App\Notification\Email\EmailTheme;
use Cake\Core\Configure;
use PHPUnit\Framework\TestCase;

final class EmailFrameTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Configure::write('App.fullBaseUrl', 'https://mesa.example.com');
    }

    public function testRendersAccentBarLogoHeaderInnerAndFooter(): void
    {
        $html = EmailFrame::render(
            EmailTheme::creacion(),
            innerHtml: '<p>BODY</p>',
            ticketReference: '#1284',
        );

        self::assertStringContainsString('background:#CD6A15', $html);
        self::assertStringContainsString('<p>BODY</p>', $html);
        self::assertStringContainsString(EmailBrand::HEADER_TITLE, $html);
        self::assertStringContainsString(EmailBrand::HEADER_SUBTITLE, $html);
        self::assertStringContainsString('#1284', $html);
        self::assertStringContainsString('logo-mesa-ayuda.svg', $html);
        self::assertStringContainsString(EmailBrand::SUPPORT_EMAIL, $html);
        self::assertStringContainsString(EmailBrand::ORG_NIT, $html);
    }
}
```

- [ ] **Step 2: FAIL**

- [ ] **Step 3: Implementar**

`src/Notification/Email/Component/EmailFrame.php`:

```php
<?php
declare(strict_types=1);

namespace App\Notification\Email\Component;

use App\Notification\Email\EmailBrand;
use App\Notification\Email\EmailTheme;

/**
 * Full email wrap: outer canvas + white card + accent bar + logo header +
 * inner content slot + footer with brand info.
 */
final class EmailFrame
{
    public static function render(EmailTheme $theme, string $innerHtml, string $ticketReference): string
    {
        $canvasStyle = 'background:#E8E6E1;padding:32px 20px;'
            . 'font-family:Geist,-apple-system,BlinkMacSystemFont,"Segoe UI",system-ui,sans-serif;'
            . 'color:#111827;';
        $cardStyle = 'max-width:720px;margin:0 auto;background:#fff;'
            . 'border-radius:10px;overflow:hidden;'
            . 'box-shadow:0 12px 32px -16px rgba(15,23,42,0.18);';

        $accentBar = '<div style="height:4px;background:' . $theme->accent . ';"></div>';
        $header = self::renderHeader($ticketReference);
        $body = '<div style="padding:32px 48px 28px;">' . $innerHtml . '</div>';
        $footer = self::renderFooter($theme, $ticketReference);

        return '<div style="' . $canvasStyle . '">'
            . '<div style="' . $cardStyle . '">'
            . $accentBar . $header . $body . $footer
            . '</div></div>';
    }

    private static function renderHeader(string $ticketReference): string
    {
        $h = 'padding:26px 48px 20px;display:flex;align-items:center;gap:12px;'
            . 'border-bottom:1px solid #F3F4F6;';
        $title = '<div><div style="font-size:14px;font-weight:700;'
            . 'letter-spacing:-0.2px;color:#111827;">' . EmailBrand::HEADER_TITLE . '</div>'
            . '<div style="font-size:11px;color:#6B7280;margin-top:1px;">'
            . EmailBrand::HEADER_SUBTITLE . '</div></div>';
        $ref = '<div style="margin-left:auto;font-family:Geist Mono,Menlo,Consolas,monospace;'
            . 'font-size:10px;color:#9CA3AF;letter-spacing:0.5px;text-transform:uppercase;">'
            . 'Ticket ' . htmlspecialchars($ticketReference, ENT_QUOTES | ENT_HTML5, 'UTF-8')
            . '</div>';
        $logo = '<img src="' . htmlspecialchars(EmailBrand::logoUrl(), ENT_QUOTES | ENT_HTML5, 'UTF-8')
            . '" alt="" width="32" height="32" style="display:block;" />';

        return '<div style="' . $h . '">' . $logo . $title . $ref . '</div>';
    }

    private static function renderFooter(EmailTheme $theme, string $ticketReference): string
    {
        $wrap = 'padding:24px 48px 32px;border-top:1px solid #F3F4F6;background:#FAFAF9;';
        $brandRow = '<div style="display:flex;align-items:center;gap:8px;margin-bottom:14px;">'
            . '<img src="' . htmlspecialchars(EmailBrand::logoUrl(), ENT_QUOTES | ENT_HTML5, 'UTF-8')
            . '" alt="" width="18" height="18" style="display:block;opacity:0.6;" />'
            . '<span style="font-size:11px;font-weight:600;color:#6B7280;letter-spacing:0.3px;">'
            . EmailBrand::ORG_TAG_LINE . '</span></div>';

        $ref = htmlspecialchars($ticketReference, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $context = '<p style="font-size:11px;color:#6B7280;line-height:1.6;margin:0;max-width:520px;">'
            . 'Recibiste este correo porque participas en el ticket '
            . '<span style="font-family:Geist Mono,Menlo,Consolas,monospace;color:#374151;">'
            . $ref . '</span>. Puedes responder directamente a este correo para añadir un comentario al ticket.'
            . '</p>';

        $links = '<div style="margin-top:14px;font-size:11px;color:#9CA3AF;">'
            . '<a href="#" style="color:' . $theme->accent . ';text-decoration:none;font-weight:500;">Ver el ticket</a>'
            . ' · <a href="#" style="color:#6B7280;text-decoration:none;">Preferencias de notificación</a>'
            . ' · <a href="#" style="color:#6B7280;text-decoration:none;">Silenciar este ticket</a>'
            . ' · <a href="#" style="color:#6B7280;text-decoration:none;">Centro de ayuda</a>'
            . '</div>';

        $legal = '<div style="margin-top:18px;padding-top:14px;border-top:1px solid #F3F4F6;'
            . 'font-size:10px;color:#9CA3AF;line-height:1.5;">'
            . '© ' . date('Y') . ' ' . EmailBrand::ORG_NAME . ' · ' . EmailBrand::ORG_ADDRESS
            . ' · NIT ' . EmailBrand::ORG_NIT . '<br/>'
            . 'Este es un mensaje automático. Para soporte humano escribe a '
            . '<span style="color:#6B7280;font-weight:500;">' . EmailBrand::SUPPORT_EMAIL . '</span>'
            . '</div>';

        return '<div style="' . $wrap . '">' . $brandRow . $context . $links . $legal . '</div>';
    }
}
```

- [ ] **Step 4: Test pasa**

- [ ] **Step 5: cs-fix y commit**

```bash
composer cs-fix && composer cs-check
git add src/Notification/Email/Component/EmailFrame.php tests/TestCase/Notification/Email/Component/EmailFrameTest.php
git commit -m "feat(notification): add EmailFrame wrap + footer"
```

---

## Task 13: `PriorityArrow` component (Ticket-specific)

**Files:**
- Create: `src/Notification/Email/Ticket/Component/PriorityArrow.php`
- Test: `tests/TestCase/Notification/Email/Ticket/Component/PriorityArrowTest.php`

- [ ] **Step 1: Test fallido**

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Notification\Email\Ticket\Component;

use App\Notification\Email\Ticket\Component\PriorityArrow;
use PHPUnit\Framework\TestCase;

final class PriorityArrowTest extends TestCase
{
    public function testAltaRendersRedUpArrow(): void
    {
        $html = PriorityArrow::render('alta');
        self::assertStringContainsString('Alta', $html);
        self::assertStringContainsString('↑', $html);
        self::assertStringContainsString('#dc3545', $html);
    }

    public function testMediaRendersOrangeRightArrow(): void
    {
        $html = PriorityArrow::render('media');
        self::assertStringContainsString('Media', $html);
        self::assertStringContainsString('→', $html);
        self::assertStringContainsString('#CD6A15', $html);
    }

    public function testBajaRendersGrayDownArrow(): void
    {
        $html = PriorityArrow::render('baja');
        self::assertStringContainsString('Baja', $html);
        self::assertStringContainsString('↓', $html);
        self::assertStringContainsString('#6B7280', $html);
    }

    public function testUnknownPriorityFallsBackToMedia(): void
    {
        $html = PriorityArrow::render('weird');
        self::assertStringContainsString('Weird', $html);
    }
}
```

- [ ] **Step 2: FAIL**

- [ ] **Step 3: Implementar**

`src/Notification/Email/Ticket/Component/PriorityArrow.php`:

```php
<?php
declare(strict_types=1);

namespace App\Notification\Email\Ticket\Component;

/**
 * Compact priority indicator: unicode arrow + label, colored by level.
 * Specific to ticket priority semantics (alta/media/baja).
 */
final class PriorityArrow
{
    /**
     * @var array<string, array{color:string, glyph:string, label:string}>
     */
    private const MAP = [
        'alta'  => ['color' => '#dc3545', 'glyph' => '↑', 'label' => 'Alta'],
        'media' => ['color' => '#CD6A15', 'glyph' => '→', 'label' => 'Media'],
        'baja'  => ['color' => '#6B7280', 'glyph' => '↓', 'label' => 'Baja'],
    ];

    public static function render(string $priority): string
    {
        $t = self::MAP[$priority] ?? [
            'color' => '#6B7280',
            'glyph' => '→',
            'label' => ucfirst($priority),
        ];

        return '<span style="display:inline-flex;align-items:center;gap:4px;'
            . 'font-size:11px;font-weight:600;color:' . $t['color'] . ';">'
            . '<span style="font-size:12px;">' . $t['glyph'] . '</span>'
            . htmlspecialchars($t['label'], ENT_QUOTES | ENT_HTML5, 'UTF-8')
            . '</span>';
    }
}
```

- [ ] **Step 4: Test pasa**

- [ ] **Step 5: cs-fix y commit**

```bash
composer cs-fix && composer cs-check
git add src/Notification/Email/Ticket/Component/PriorityArrow.php tests/TestCase/Notification/Email/Ticket/Component/PriorityArrowTest.php
git commit -m "feat(notification): add PriorityArrow ticket component"
```

---

## Task 14: `StatusTransition` component

**Files:**
- Create: `src/Notification/Email/Ticket/Component/StatusTransition.php`
- Test: `tests/TestCase/Notification/Email/Ticket/Component/StatusTransitionTest.php`

- [ ] **Step 1: Test fallido**

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Notification\Email\Ticket\Component;

use App\Notification\Email\Ticket\Component\StatusTransition;
use PHPUnit\Framework\TestCase;

final class StatusTransitionTest extends TestCase
{
    public function testRendersAntesAhoraLabelsAndBothStatusPills(): void
    {
        $html = StatusTransition::render('abierto', 'pendiente', '#0066cc');

        self::assertStringContainsString('ANTES', strtoupper($html));
        self::assertStringContainsString('AHORA', strtoupper($html));
        self::assertStringContainsString('Abierto', $html);
        self::assertStringContainsString('Pendiente', $html);
        self::assertStringContainsString('#0066cc', $html);
        self::assertStringContainsString('CAMBIO APLICADO', strtoupper($html));
    }
}
```

- [ ] **Step 2: FAIL**

- [ ] **Step 3: Implementar**

`src/Notification/Email/Ticket/Component/StatusTransition.php`:

```php
<?php
declare(strict_types=1);

namespace App\Notification\Email\Ticket\Component;

use App\Notification\Email\Component\Pill;

/**
 * Visual "before → after" status block: two boxes with the status pills
 * and an arrow between them; the "after" box gets the accent border.
 */
final class StatusTransition
{
    public static function render(string $from, string $to, string $accent): string
    {
        $boxStyle = 'border:1px solid #E5E7EB;border-radius:10px;'
            . 'padding:16px 18px;margin-bottom:20px;background:#FAFAFA;';
        $labelStyle = 'font-size:11px;font-weight:600;color:#6B7280;'
            . 'letter-spacing:0.5px;text-transform:uppercase;margin-bottom:12px;';

        $beforeMicro = 'font-size:10px;color:#9CA3AF;margin-bottom:4px;font-weight:600;'
            . 'letter-spacing:0.4px;text-transform:uppercase;';
        $afterMicro = 'font-size:10px;color:' . $accent . ';margin-bottom:4px;font-weight:600;'
            . 'letter-spacing:0.4px;text-transform:uppercase;';

        $before = '<td style="width:45%;padding:10px 12px;background:#fff;'
            . 'border:1px solid #E5E7EB;border-radius:8px;vertical-align:top;">'
            . '<div style="' . $beforeMicro . '">Antes</div>' . Pill::forStatus($from)
            . '</td>';

        $arrow = '<td style="width:10%;text-align:center;font-size:18px;color:' . $accent . ';">→</td>';

        $after = '<td style="width:45%;padding:10px 12px;background:#fff;'
            . 'border:1px solid ' . $accent . ';border-radius:8px;'
            . 'box-shadow:0 0 0 3px ' . $accent . '1a;vertical-align:top;">'
            . '<div style="' . $afterMicro . '">Ahora</div>' . Pill::forStatus($to)
            . '</td>';

        $table = '<table role="presentation" cellspacing="0" cellpadding="0" '
            . 'style="width:100%;border-collapse:separate;border-spacing:14px 0;">'
            . '<tr>' . $before . $arrow . $after . '</tr></table>';

        return '<div style="' . $boxStyle . '">'
            . '<div style="' . $labelStyle . '">Cambio aplicado</div>'
            . $table
            . '</div>';
    }
}
```

- [ ] **Step 4: Test pasa**

- [ ] **Step 5: cs-fix y commit**

```bash
composer cs-fix && composer cs-check
git add src/Notification/Email/Ticket/Component/StatusTransition.php tests/TestCase/Notification/Email/Ticket/Component/StatusTransitionTest.php
git commit -m "feat(notification): add StatusTransition ticket component"
```

---

## Task 15: `CommentBlock` component

**Files:**
- Create: `src/Notification/Email/Ticket/Component/CommentBlock.php`
- Test: `tests/TestCase/Notification/Email/Ticket/Component/CommentBlockTest.php`

- [ ] **Step 1: Test fallido**

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Notification\Email\Ticket\Component;

use App\Notification\Email\Ticket\Component\CommentBlock;
use PHPUnit\Framework\TestCase;

final class CommentBlockTest extends TestCase
{
    public function testRendersAuthorAndBodyHtmlRaw(): void
    {
        $html = CommentBlock::render(
            authorName: 'Maira Pérez',
            authorRole: 'Líder de soporte',
            authorColor: '#7c3aed',
            bodyHtml: '<p>Hola <em>Alex</em>, ya revisamos.</p>',
            accent: '#00A85E',
            accentSoft: '#E6F7EE',
            timestamp: '14 may · 13:50',
        );

        self::assertStringContainsString('Maira Pérez', $html);
        self::assertStringContainsString('Líder de soporte', $html);
        self::assertStringContainsString('respondió a tu ticket', $html);
        self::assertStringContainsString('<p>Hola <em>Alex</em>, ya revisamos.</p>', $html);
        self::assertStringContainsString('background:#E6F7EE', $html);
        self::assertStringContainsString('14 may · 13:50', $html);
    }

    public function testEscapesAuthorNameAndRoleButNotBody(): void
    {
        $html = CommentBlock::render(
            authorName: '<x>',
            authorRole: '<y>',
            authorColor: '#000',
            bodyHtml: '<p>OK</p>',
            accent: '#000',
            accentSoft: '#fff',
            timestamp: '',
        );
        self::assertStringNotContainsString('<x>', $html);
        self::assertStringNotContainsString('<y>', $html);
        self::assertStringContainsString('<p>OK</p>', $html);
    }
}
```

- [ ] **Step 2: FAIL**

- [ ] **Step 3: Implementar**

`src/Notification/Email/Ticket/Component/CommentBlock.php`:

```php
<?php
declare(strict_types=1);

namespace App\Notification\Email\Ticket\Component;

use App\Notification\Email\Component\Avatar;

/**
 * Block with avatar+author header (tinted with accentSoft) and the comment
 * body. SECURITY: $bodyHtml is inserted raw — caller must sanitize via
 * HtmlSanitizerTrait before construction.
 */
final class CommentBlock
{
    public static function render(
        string $authorName,
        string $authorRole,
        string $authorColor,
        string $bodyHtml,
        string $accent,
        string $accentSoft,
        string $timestamp,
    ): string {
        $initials = Avatar::initialsFromName($authorName);
        $avatar = Avatar::render($initials, $authorColor, 32);

        $headerStyle = 'padding:12px 16px;background:' . $accentSoft . ';'
            . 'border-bottom:1px solid ' . $accent . '33;'
            . 'display:flex;align-items:center;gap:10px;';

        $name = htmlspecialchars($authorName, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $role = htmlspecialchars($authorRole, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $ts = htmlspecialchars($timestamp, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $meta = '<div style="flex:1;min-width:0;">'
            . '<div style="font-size:13px;font-weight:600;color:#111827;">' . $name . '</div>'
            . '<div style="font-size:11px;color:#6B7280;">' . $role . ' · respondió a tu ticket</div>'
            . '</div>'
            . '<span style="font-family:Geist Mono,Menlo,Consolas,monospace;'
            . 'font-size:10px;color:#9CA3AF;">' . $ts . '</span>';

        $body = '<div style="padding:16px 18px;font-size:14px;color:#374151;'
            . 'line-height:1.65;background:#fff;">' . $bodyHtml . '</div>';

        return '<div style="border:1px solid #E5E7EB;border-radius:10px;'
            . 'overflow:hidden;margin-bottom:20px;">'
            . '<div style="' . $headerStyle . '">' . $avatar . $meta . '</div>'
            . $body
            . '</div>';
    }
}
```

- [ ] **Step 4: Test pasa**

- [ ] **Step 5: cs-fix y commit**

```bash
composer cs-fix && composer cs-check
git add src/Notification/Email/Ticket/Component/CommentBlock.php tests/TestCase/Notification/Email/Ticket/Component/CommentBlockTest.php
git commit -m "feat(notification): add CommentBlock ticket component"
```

---

## Task 16: `TicketCard` component

**Files:**
- Create: `src/Notification/Email/Ticket/Component/TicketCard.php`
- Test: `tests/TestCase/Notification/Email/Ticket/Component/TicketCardTest.php`

`TicketCard` mapea una entidad `Ticket` a las props del componente genérico `Card`. Lee `ticket_number`, `status`, `priority`, `subject`, `requester`, `assignee`, `tags`, `created`. Para evitar dependencias con la tabla y permitir test puro, `tags` se lee de `$ticket->get('tags')` como array de strings; si está vacío se renderiza sin tags.

- [ ] **Step 1: Test fallido**

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Notification\Email\Ticket\Component;

use App\Model\Entity\Ticket;
use App\Model\Entity\User;
use App\Notification\Email\Ticket\Component\TicketCard;
use Cake\I18n\DateTime;
use PHPUnit\Framework\TestCase;

final class TicketCardTest extends TestCase
{
    private function ticket(array $overrides = []): Ticket
    {
        $requester = new User();
        $requester->set([
            'id' => 10,
            'name' => 'Alexander Caicedo',
            'email' => 'alex@example.com',
        ], ['guard' => false]);

        $assignee = new User();
        $assignee->set([
            'id' => 20,
            'name' => 'Maira Pérez',
            'email' => 'maira@example.com',
        ], ['guard' => false]);

        $t = new Ticket();
        $t->set(array_merge([
            'id' => 1,
            'ticket_number' => 'TKT-1284',
            'subject' => 'Cafetera #14 no enciende',
            'status' => 'pendiente',
            'priority' => 'alta',
            'requester' => $requester,
            'assignee' => $assignee,
            'tags' => ['Mantenimiento', 'Sucursal Norte'],
            'created' => new DateTime('2026-05-14 13:42:00'),
        ], $overrides), ['guard' => false]);

        return $t;
    }

    public function testRendersTicketNumberStatusSubjectAndPeople(): void
    {
        $html = TicketCard::render($this->ticket());

        self::assertStringContainsString('TKT-1284', $html);
        self::assertStringContainsString('Pendiente', $html);
        self::assertStringContainsString('Alta', $html);
        self::assertStringContainsString('Cafetera #14 no enciende', $html);
        self::assertStringContainsString('Mantenimiento', $html);
        self::assertStringContainsString('Alexander Caicedo', $html);
        self::assertStringContainsString('Maira Pérez', $html);
    }

    public function testWithoutAssigneeRendersUnassignedBadge(): void
    {
        $html = TicketCard::render($this->ticket(['assignee' => null]));
        self::assertStringContainsString('Sin asignar', $html);
        self::assertStringNotContainsString('Maira Pérez', $html);
    }

    public function testWithoutTagsOmitsTagsBlock(): void
    {
        $html = TicketCard::render($this->ticket(['tags' => []]));
        self::assertStringNotContainsString('Mantenimiento', $html);
    }
}
```

- [ ] **Step 2: FAIL**

- [ ] **Step 3: Implementar**

`src/Notification/Email/Ticket/Component/TicketCard.php`:

```php
<?php
declare(strict_types=1);

namespace App\Notification\Email\Ticket\Component;

use App\Model\Entity\Ticket;
use App\Notification\Email\Component\Avatar;
use App\Notification\Email\Component\Card;
use App\Notification\Email\Component\Pill;
use DateTimeInterface;

/**
 * Card built from a Ticket entity. Maps domain fields into the generic
 * Card component's props.
 */
final class TicketCard
{
    /**
     * Hash a string to one of a small set of pleasant colors for avatar
     * background — keeps the rendering deterministic without needing a
     * dedicated user-color column.
     *
     * @var list<string>
     */
    private const AVATAR_PALETTE = [
        '#00A85E', '#CD6A15', '#0066cc', '#7c3aed', '#0891b2', '#dc3545',
    ];

    public static function render(Ticket $ticket): string
    {
        $number = (string)($ticket->ticket_number ?? '');
        $status = (string)($ticket->status ?? '');
        $priority = (string)($ticket->priority ?? 'media');
        $subject = (string)($ticket->subject ?? '');

        $tags = $ticket->get('tags');
        if (!is_array($tags)) {
            $tags = [];
        }

        $headerLeft = '<span style="font-family:Geist Mono,Menlo,Consolas,monospace;'
            . 'font-size:11px;font-weight:600;color:#6B7280;margin-right:10px;">#'
            . htmlspecialchars($number, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</span>'
            . Pill::forStatus($status)
            . '<span style="margin-left:10px;">' . PriorityArrow::render($priority) . '</span>';

        $headerRight = '';
        $created = $ticket->get('created');
        if ($created instanceof DateTimeInterface) {
            $headerRight = '<span style="font-family:Geist Mono,Menlo,Consolas,monospace;'
                . 'font-size:10px;color:#9CA3AF;">'
                . htmlspecialchars($created->format('d M · H:i'), ENT_QUOTES | ENT_HTML5, 'UTF-8')
                . '</span>';
        }

        $metaColumns = [
            ['label' => 'Solicitante', 'valueHtml' => self::renderPerson($ticket->get('requester'), 'Sin solicitante')],
            ['label' => 'Asignado a',  'valueHtml' => self::renderPerson($ticket->get('assignee'),  null)],
        ];

        return Card::render(
            headerLeftHtml: $headerLeft,
            headerRightHtml: $headerRight,
            title: $subject,
            tags: array_values(array_filter(array_map('strval', $tags), static fn ($t) => $t !== '')),
            metaColumns: $metaColumns,
        );
    }

    private static function renderPerson(mixed $person, ?string $fallbackLabel): string
    {
        if ($person === null) {
            return $fallbackLabel === null
                ? '<span style="display:inline-block;padding:4px 9px;border-radius:6px;'
                    . 'background:#FCEFE0;color:#6b3306;font-size:11px;font-weight:600;">Sin asignar</span>'
                : '<span style="font-size:13px;color:#6B7280;">'
                    . htmlspecialchars($fallbackLabel, ENT_QUOTES | ENT_HTML5, 'UTF-8')
                    . '</span>';
        }

        $name = (string)($person->name ?? '');
        $role = (string)($person->role ?? '');
        $color = self::AVATAR_PALETTE[crc32($name) % count(self::AVATAR_PALETTE)];
        $initials = Avatar::initialsFromName($name);

        $textBlock = '<div style="display:inline-block;vertical-align:middle;margin-left:8px;">'
            . '<div style="font-size:13px;font-weight:500;color:#111827;">'
            . htmlspecialchars($name, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</div>'
            . '<div style="font-size:11px;color:#6B7280;">'
            . htmlspecialchars($role, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</div>'
            . '</div>';

        return '<div style="display:inline-flex;align-items:center;">'
            . Avatar::render($initials, $color, 26) . $textBlock . '</div>';
    }
}
```

- [ ] **Step 4: Test pasa**

- [ ] **Step 5: cs-fix y commit**

```bash
composer cs-fix && composer cs-check
git add src/Notification/Email/Ticket/Component/TicketCard.php tests/TestCase/Notification/Email/Ticket/Component/TicketCardTest.php
git commit -m "feat(notification): add TicketCard component"
```

---

## Task 17: `EmailTemplate` interface

**Files:**
- Create: `src/Notification/Email/EmailTemplate.php`

Trivial; no test propio (lo cubren las pruebas de las plantillas concretas).

- [ ] **Step 1: Implementar**

`src/Notification/Email/EmailTemplate.php`:

```php
<?php
declare(strict_types=1);

namespace App\Notification\Email;

/**
 * One transactional email template. Implementations are stateless and
 * registered in TemplateRegistry by `key()`.
 */
interface EmailTemplate
{
    public function key(): string;

    public function render(TemplateContext $ctx): RenderedEmail;
}
```

- [ ] **Step 2: cs-check pasa (sin tests nuevos)**

```bash
composer cs-fix && composer cs-check
```

- [ ] **Step 3: Commit**

```bash
git add src/Notification/Email/EmailTemplate.php
git commit -m "feat(notification): add EmailTemplate interface"
```

---

## Task 18: `TicketCreatedTemplate`

**Files:**
- Create: `src/Notification/Email/Ticket/Template/TicketCreatedTemplate.php`
- Test: `tests/TestCase/Notification/Email/Ticket/Template/TicketCreatedTemplateTest.php`

- [ ] **Step 1: Test fallido**

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Notification\Email\Ticket\Template;

use App\Model\Entity\Ticket;
use App\Model\Entity\User;
use App\Notification\Email\TemplateContext;
use App\Notification\Email\Ticket\Template\TicketCreatedTemplate;
use Cake\Core\Configure;
use PHPUnit\Framework\TestCase;

final class TicketCreatedTemplateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Configure::write('App.fullBaseUrl', 'https://mesa.example.com');
    }

    public function testKeyIsTicketCreated(): void
    {
        self::assertSame('ticket_created', (new TicketCreatedTemplate())->key());
    }

    public function testRenderProducesSubjectAndBodyWithExpectedTokens(): void
    {
        $requester = new User();
        $requester->set(['name' => 'Alexander Caicedo'], ['guard' => false]);

        $ticket = new Ticket();
        $ticket->set([
            'id' => 1,
            'ticket_number' => 'TKT-1284',
            'subject' => 'Cafetera #14 no enciende',
            'status' => 'nuevo',
            'priority' => 'alta',
            'requester' => $requester,
            'assignee' => null,
            'tags' => ['Mantenimiento'],
        ], ['guard' => false]);

        $ctx = new TemplateContext(
            ticket: $ticket,
            ticketUrl: 'https://mesa.example.com/tickets/view/1',
            recipientName: 'Alexander',
        );

        $email = (new TicketCreatedTemplate())->render($ctx);

        self::assertSame('Tu ticket #TKT-1284 fue creado', $email->subject);
        self::assertStringContainsString('Tu ticket fue creado', $email->bodyHtml);
        self::assertStringContainsString('Hola <strong', $email->bodyHtml);
        self::assertStringContainsString('Alexander', $email->bodyHtml);
        self::assertStringContainsString('Cafetera #14 no enciende', $email->bodyHtml);
        self::assertStringContainsString('Próximos pasos', $email->bodyHtml);
        self::assertStringContainsString('30 minutos', $email->bodyHtml);
        self::assertStringContainsString('Ver mi ticket', $email->bodyHtml);
        self::assertStringContainsString('https://mesa.example.com/tickets/view/1', $email->bodyHtml);
        self::assertStringContainsString('#CD6A15', $email->bodyHtml);
    }
}
```

- [ ] **Step 2: FAIL**

- [ ] **Step 3: Implementar**

`src/Notification/Email/Ticket/Template/TicketCreatedTemplate.php`:

```php
<?php
declare(strict_types=1);

namespace App\Notification\Email\Ticket\Template;

use App\Notification\Email\Component\CtaButton;
use App\Notification\Email\Component\EmailFrame;
use App\Notification\Email\Component\Greeting;
use App\Notification\Email\Component\InfoBox;
use App\Notification\Email\EmailTemplate;
use App\Notification\Email\EmailTheme;
use App\Notification\Email\RenderedEmail;
use App\Notification\Email\TemplateContext;
use App\Notification\Email\Ticket\Component\TicketCard;

/**
 * Notifies the requester that their ticket was created.
 * Theme: creacion (orange). Sent to requester only.
 */
final class TicketCreatedTemplate implements EmailTemplate
{
    public function key(): string
    {
        return 'ticket_created';
    }

    public function render(TemplateContext $ctx): RenderedEmail
    {
        $theme = EmailTheme::creacion();
        $subject = 'Tu ticket #' . $ctx->ticket->ticket_number . ' fue creado';

        $nextSteps =
            '<ol style="margin:0;padding-left:18px;font-size:13px;'
            . 'color:#374151;line-height:1.7;">'
            . '<li>Un agente tomará el ticket en los próximos <strong style="color:#111827;">30 minutos</strong>.</li>'
            . '<li>Recibirás un correo cuando el ticket sea asignado o cambie de estado.</li>'
            . '<li>Puedes añadir información respondiendo este correo o desde la mesa de ayuda.</li>'
            . '</ol>';

        $inner =
            Greeting::render(
                headline: 'Tu ticket fue creado',
                intro: 'hemos recibido tu solicitud y la asignaremos pronto a un agente. Mientras tanto, este es el resumen:',
                recipientName: $ctx->recipientName,
            )
            . TicketCard::render($ctx->ticket)
            . InfoBox::render('Próximos pasos', $nextSteps, InfoBox::VARIANT_DASHED)
            . CtaButton::render('Ver mi ticket', $theme->accent, $ctx->ticketUrl);

        $body = EmailFrame::render($theme, $inner, '#' . $ctx->ticket->ticket_number);

        return new RenderedEmail($subject, $body);
    }
}
```

- [ ] **Step 4: Test pasa**

- [ ] **Step 5: cs-fix y commit**

```bash
composer cs-fix && composer cs-check
git add src/Notification/Email/Ticket/Template/TicketCreatedTemplate.php tests/TestCase/Notification/Email/Ticket/Template/TicketCreatedTemplateTest.php
git commit -m "feat(notification): add TicketCreatedTemplate"
```

---

## Task 19: `TicketStatusChangedTemplate`

**Files:**
- Create: `src/Notification/Email/Ticket/Template/TicketStatusChangedTemplate.php`
- Test: `tests/TestCase/Notification/Email/Ticket/Template/TicketStatusChangedTemplateTest.php`

- [ ] **Step 1: Test fallido**

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Notification\Email\Ticket\Template;

use App\Model\Entity\Ticket;
use App\Model\Entity\User;
use App\Notification\Email\TemplateContext;
use App\Notification\Email\Ticket\Template\TicketStatusChangedTemplate;
use Cake\Core\Configure;
use PHPUnit\Framework\TestCase;

final class TicketStatusChangedTemplateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Configure::write('App.fullBaseUrl', 'https://mesa.example.com');
    }

    public function testKey(): void
    {
        self::assertSame('ticket_status_changed', (new TicketStatusChangedTemplate())->key());
    }

    public function testRenderIncludesStatusTransitionAndSubjectMentionsNewLabel(): void
    {
        $requester = new User();
        $requester->set(['name' => 'Alex'], ['guard' => false]);

        $ticket = new Ticket();
        $ticket->set([
            'ticket_number' => 'TKT-1',
            'subject' => 'Subj',
            'status' => 'pendiente',
            'priority' => 'media',
            'requester' => $requester,
            'tags' => [],
        ], ['guard' => false]);

        $actor = new User();
        $actor->set(['name' => 'Maira Pérez'], ['guard' => false]);

        $ctx = new TemplateContext(
            ticket: $ticket,
            ticketUrl: 'https://mesa.example.com/t/1',
            recipientName: 'Alex',
            oldStatus: 'abierto',
            newStatus: 'pendiente',
            actor: $actor,
        );

        $email = (new TicketStatusChangedTemplate())->render($ctx);

        self::assertStringContainsString('Pendiente', $email->subject);
        self::assertStringContainsString('TKT-1', $email->subject);
        self::assertStringContainsString('El estado de tu ticket cambió', $email->bodyHtml);
        self::assertStringContainsString('Abierto', $email->bodyHtml);
        self::assertStringContainsString('Pendiente', $email->bodyHtml);
        self::assertStringContainsString('Maira Pérez', $email->bodyHtml);
        self::assertStringContainsString('Ver el ticket', $email->bodyHtml);
    }

    public function testWithoutActorOmitsActorBanner(): void
    {
        $requester = new User();
        $requester->set(['name' => 'Alex'], ['guard' => false]);

        $ticket = new Ticket();
        $ticket->set([
            'ticket_number' => 'TKT-1',
            'subject' => 'S',
            'status' => 'resuelto',
            'priority' => 'media',
            'requester' => $requester,
            'tags' => [],
        ], ['guard' => false]);

        $ctx = new TemplateContext(
            ticket: $ticket,
            ticketUrl: 'u',
            recipientName: 'Alex',
            oldStatus: 'pendiente',
            newStatus: 'resuelto',
        );

        $email = (new TicketStatusChangedTemplate())->render($ctx);
        self::assertStringNotContainsString('aplicó este cambio', $email->bodyHtml);
    }
}
```

- [ ] **Step 2: FAIL**

- [ ] **Step 3: Implementar**

`src/Notification/Email/Ticket/Template/TicketStatusChangedTemplate.php`:

```php
<?php
declare(strict_types=1);

namespace App\Notification\Email\Ticket\Template;

use App\Notification\Email\Component\Avatar;
use App\Notification\Email\Component\CtaButton;
use App\Notification\Email\Component\EmailFrame;
use App\Notification\Email\Component\Greeting;
use App\Notification\Email\EmailTemplate;
use App\Notification\Email\EmailTheme;
use App\Notification\Email\RenderedEmail;
use App\Notification\Email\TemplateContext;
use App\Notification\Email\Ticket\Component\StatusTransition;
use App\Notification\Email\Ticket\Component\TicketCard;
use App\Service\Renderer\NotificationRenderer;

/**
 * Notifies the requester that the ticket status changed.
 * Theme: estado (blue). Sent to requester only.
 */
final class TicketStatusChangedTemplate implements EmailTemplate
{
    public function key(): string
    {
        return 'ticket_status_changed';
    }

    public function render(TemplateContext $ctx): RenderedEmail
    {
        $theme = EmailTheme::estado();
        $oldStatus = (string)($ctx->oldStatus ?? '');
        $newStatus = (string)($ctx->newStatus ?? '');

        $renderer = new NotificationRenderer();
        $newLabel = $renderer->getStatusLabel($newStatus);
        $subject = 'El estado de tu ticket #' . $ctx->ticket->ticket_number
            . ' cambió a ' . $newLabel;

        $inner =
            Greeting::render(
                headline: 'El estado de tu ticket cambió',
                intro: 'te avisamos porque hay un cambio en el seguimiento. El nuevo estado refleja la acción más reciente del agente:',
                recipientName: $ctx->recipientName,
            )
            . StatusTransition::render($oldStatus, $newStatus, $theme->accent)
            . TicketCard::render($ctx->ticket)
            . $this->renderActorBanner($ctx, $theme)
            . CtaButton::render('Ver el ticket', $theme->accent, $ctx->ticketUrl);

        $body = EmailFrame::render($theme, $inner, '#' . $ctx->ticket->ticket_number);

        return new RenderedEmail($subject, $body);
    }

    private function renderActorBanner(TemplateContext $ctx, EmailTheme $theme): string
    {
        if ($ctx->actor === null) {
            return '';
        }

        $name = (string)($ctx->actor->name ?? '');
        if ($name === '') {
            return '';
        }

        $initials = Avatar::initialsFromName($name);
        $avatar = Avatar::render($initials, $theme->accent, 22);

        $banner = 'display:flex;align-items:center;gap:10px;padding:10px 14px;'
            . 'margin-bottom:20px;background:' . $theme->accentSoft . ';'
            . 'border-radius:8px;font-size:12px;color:' . $theme->accentInk . ';';

        return '<div style="' . $banner . '">' . $avatar
            . '<span><strong style="font-weight:600;">'
            . htmlspecialchars($name, ENT_QUOTES | ENT_HTML5, 'UTF-8')
            . '</strong> aplicó este cambio.</span></div>';
    }
}
```

- [ ] **Step 4: Test pasa**

- [ ] **Step 5: cs-fix y commit**

```bash
composer cs-fix && composer cs-check
git add src/Notification/Email/Ticket/Template/TicketStatusChangedTemplate.php tests/TestCase/Notification/Email/Ticket/Template/TicketStatusChangedTemplateTest.php
git commit -m "feat(notification): add TicketStatusChangedTemplate"
```

---

## Task 20: `TicketCommentAddedTemplate`

**Files:**
- Create: `src/Notification/Email/Ticket/Template/TicketCommentAddedTemplate.php`
- Test: `tests/TestCase/Notification/Email/Ticket/Template/TicketCommentAddedTemplateTest.php`

- [ ] **Step 1: Test fallido**

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Notification\Email\Ticket\Template;

use App\Model\Entity\Ticket;
use App\Model\Entity\TicketComment;
use App\Model\Entity\User;
use App\Notification\Email\TemplateContext;
use App\Notification\Email\Ticket\Template\TicketCommentAddedTemplate;
use Cake\Core\Configure;
use Cake\I18n\DateTime;
use PHPUnit\Framework\TestCase;

final class TicketCommentAddedTemplateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Configure::write('App.fullBaseUrl', 'https://mesa.example.com');
    }

    public function testKey(): void
    {
        self::assertSame('ticket_comment_added', (new TicketCommentAddedTemplate())->key());
    }

    public function testRendersCommentBlockAndSubjectMentionsAgent(): void
    {
        $requester = new User();
        $requester->set(['name' => 'Alex'], ['guard' => false]);

        $agent = new User();
        $agent->set(['name' => 'Maira Pérez', 'role' => 'Líder'], ['guard' => false]);

        $comment = new TicketComment();
        $comment->set([
            'body' => '<p>Ya estamos revisando.</p>',
            'user' => $agent,
            'created' => new DateTime('2026-05-14 13:50:00'),
        ], ['guard' => false]);

        $ticket = new Ticket();
        $ticket->set([
            'ticket_number' => 'TKT-1',
            'subject' => 'S',
            'status' => 'pendiente',
            'priority' => 'media',
            'requester' => $requester,
            'tags' => [],
        ], ['guard' => false]);

        $ctx = new TemplateContext(
            ticket: $ticket,
            ticketUrl: 'https://mesa.example.com/t/1',
            recipientName: 'Alex',
            comment: $comment,
            actor: $agent,
        );

        $email = (new TicketCommentAddedTemplate())->render($ctx);

        self::assertStringContainsString('Maira Pérez', $email->subject);
        self::assertStringContainsString('TKT-1', $email->subject);
        self::assertStringContainsString('Tienes una nueva respuesta', $email->bodyHtml);
        self::assertStringContainsString('<p>Ya estamos revisando.</p>', $email->bodyHtml);
        self::assertStringContainsString('Responde desde este mismo correo', $email->bodyHtml);
        self::assertStringContainsString('Responder en la mesa de ayuda', $email->bodyHtml);
    }

    public function testWithoutActorSubjectFallsBackToMesaDeAyuda(): void
    {
        $requester = new User();
        $requester->set(['name' => 'Alex'], ['guard' => false]);

        $comment = new TicketComment();
        $comment->set(['body' => '<p>x</p>'], ['guard' => false]);

        $ticket = new Ticket();
        $ticket->set([
            'ticket_number' => 'TKT-9',
            'subject' => 'S',
            'status' => 'abierto',
            'priority' => 'media',
            'requester' => $requester,
            'tags' => [],
        ], ['guard' => false]);

        $ctx = new TemplateContext(
            ticket: $ticket,
            ticketUrl: 'u',
            recipientName: 'Alex',
            comment: $comment,
        );

        $email = (new TicketCommentAddedTemplate())->render($ctx);
        self::assertStringContainsString('Mesa de Ayuda te respondió', $email->subject);
    }
}
```

- [ ] **Step 2: FAIL**

- [ ] **Step 3: Implementar**

`src/Notification/Email/Ticket/Template/TicketCommentAddedTemplate.php`:

```php
<?php
declare(strict_types=1);

namespace App\Notification\Email\Ticket\Template;

use App\Notification\Email\Component\CtaButton;
use App\Notification\Email\Component\EmailFrame;
use App\Notification\Email\Component\Greeting;
use App\Notification\Email\EmailTemplate;
use App\Notification\Email\EmailTheme;
use App\Notification\Email\RenderedEmail;
use App\Notification\Email\TemplateContext;
use App\Notification\Email\Ticket\Component\CommentBlock;
use App\Notification\Email\Ticket\Component\TicketCard;
use DateTimeInterface;

/**
 * Notifies the requester that an agent left a new comment (without a status change).
 * Theme: comentario (green). Sent to requester only.
 *
 * SECURITY: $ctx->comment->body must arrive sanitized via HtmlSanitizerTrait.
 */
final class TicketCommentAddedTemplate implements EmailTemplate
{
    public function key(): string
    {
        return 'ticket_comment_added';
    }

    public function render(TemplateContext $ctx): RenderedEmail
    {
        $theme = EmailTheme::comentario();
        $agentName = $ctx->actor?->name !== '' && $ctx->actor !== null
            ? (string)$ctx->actor->name
            : 'Mesa de Ayuda';
        $agentRole = (string)($ctx->actor->role ?? '');
        $body = (string)($ctx->comment?->body ?? '');

        $subject = $agentName . ' te respondió en el ticket #' . $ctx->ticket->ticket_number;

        $timestamp = '';
        $created = $ctx->comment?->get('created');
        if ($created instanceof DateTimeInterface) {
            $timestamp = $created->format('d M · H:i');
        }

        $inner =
            Greeting::render(
                headline: 'Tienes una nueva respuesta',
                intro: htmlspecialchars($agentName, ENT_QUOTES | ENT_HTML5, 'UTF-8')
                    . ' respondió a tu ticket. Puedes contestar desde la mesa de ayuda o respondiendo este correo.',
                recipientName: $ctx->recipientName,
            )
            . CommentBlock::render(
                authorName: $agentName,
                authorRole: $agentRole,
                authorColor: $theme->accent,
                bodyHtml: $body,
                accent: $theme->accent,
                accentSoft: $theme->accentSoft,
                timestamp: $timestamp,
            )
            . TicketCard::render($ctx->ticket)
            . $this->renderReplyHint($theme)
            . CtaButton::render('Responder en la mesa de ayuda', $theme->accent, $ctx->ticketUrl);

        return new RenderedEmail($subject, EmailFrame::render(
            $theme,
            $inner,
            '#' . $ctx->ticket->ticket_number,
        ));
    }

    private function renderReplyHint(EmailTheme $theme): string
    {
        $wrap = 'display:flex;align-items:center;gap:12px;padding:12px 14px;'
            . 'margin-bottom:20px;background:#FAFAFA;border:1px solid #E5E7EB;'
            . 'border-radius:8px;font-size:12px;color:#4B5563;line-height:1.5;';

        $icon = '<span style="display:inline-flex;align-items:center;justify-content:center;'
            . 'width:34px;height:34px;border-radius:50%;flex-shrink:0;'
            . 'background:' . $theme->accentSoft . ';color:' . $theme->accentInk
            . ';font-weight:700;">↩</span>';

        $text = '<div><div style="font-weight:600;color:#111827;margin-bottom:2px;">'
            . 'Responde desde este mismo correo</div>'
            . 'Cualquier texto que envíes responderá automáticamente al hilo del ticket'
            . ' y se notificará al agente.</div>';

        return '<div style="' . $wrap . '">' . $icon . $text . '</div>';
    }
}
```

(Nota: la intro tiene HTML mezclado con escape — eso es porque `Greeting` escapa todo el intro. Para mantener el agente en negrita podemos pasar el agente plano, lo cual ya hace este código. La intro queda como texto normal con el nombre del agente en el cuerpo del texto.)

- [ ] **Step 4: Test pasa**

- [ ] **Step 5: cs-fix y commit**

```bash
composer cs-fix && composer cs-check
git add src/Notification/Email/Ticket/Template/TicketCommentAddedTemplate.php tests/TestCase/Notification/Email/Ticket/Template/TicketCommentAddedTemplateTest.php
git commit -m "feat(notification): add TicketCommentAddedTemplate"
```

---

## Task 21: `TicketUpdatedTemplate` (combo)

**Files:**
- Create: `src/Notification/Email/Ticket/Template/TicketUpdatedTemplate.php`
- Test: `tests/TestCase/Notification/Email/Ticket/Template/TicketUpdatedTemplateTest.php`

- [ ] **Step 1: Test fallido**

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Notification\Email\Ticket\Template;

use App\Model\Entity\Ticket;
use App\Model\Entity\TicketComment;
use App\Model\Entity\User;
use App\Notification\Email\TemplateContext;
use App\Notification\Email\Ticket\Template\TicketUpdatedTemplate;
use Cake\Core\Configure;
use PHPUnit\Framework\TestCase;

final class TicketUpdatedTemplateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Configure::write('App.fullBaseUrl', 'https://mesa.example.com');
    }

    public function testKey(): void
    {
        self::assertSame('ticket_updated', (new TicketUpdatedTemplate())->key());
    }

    public function testRenderIncludesBothTransitionAndCommentBlock(): void
    {
        $requester = new User();
        $requester->set(['name' => 'Alex'], ['guard' => false]);
        $agent = new User();
        $agent->set(['name' => 'Maira Pérez', 'role' => 'Líder'], ['guard' => false]);

        $comment = new TicketComment();
        $comment->set(['body' => '<p>Comentario.</p>', 'user' => $agent], ['guard' => false]);

        $ticket = new Ticket();
        $ticket->set([
            'ticket_number' => 'TKT-1',
            'subject' => 'S',
            'status' => 'pendiente',
            'priority' => 'media',
            'requester' => $requester,
            'tags' => [],
        ], ['guard' => false]);

        $ctx = new TemplateContext(
            ticket: $ticket,
            ticketUrl: 'https://mesa.example.com/t/1',
            recipientName: 'Alex',
            comment: $comment,
            oldStatus: 'abierto',
            newStatus: 'pendiente',
            actor: $agent,
        );

        $email = (new TicketUpdatedTemplate())->render($ctx);

        self::assertStringContainsString('Maira Pérez actualizó tu ticket', $email->subject);
        self::assertStringContainsString('Tu ticket fue actualizado', $email->bodyHtml);
        self::assertStringContainsString('Cambio de estado', $email->bodyHtml);
        self::assertStringContainsString('Comentario del agente', $email->bodyHtml);
        self::assertStringContainsString('Abierto', $email->bodyHtml);
        self::assertStringContainsString('Pendiente', $email->bodyHtml);
        self::assertStringContainsString('<p>Comentario.</p>', $email->bodyHtml);
        self::assertStringContainsString('Ver actualización completa', $email->bodyHtml);
    }
}
```

- [ ] **Step 2: FAIL**

- [ ] **Step 3: Implementar**

`src/Notification/Email/Ticket/Template/TicketUpdatedTemplate.php`:

```php
<?php
declare(strict_types=1);

namespace App\Notification\Email\Ticket\Template;

use App\Notification\Email\Component\CtaButton;
use App\Notification\Email\Component\EmailFrame;
use App\Notification\Email\Component\Greeting;
use App\Notification\Email\EmailTemplate;
use App\Notification\Email\EmailTheme;
use App\Notification\Email\RenderedEmail;
use App\Notification\Email\TemplateContext;
use App\Notification\Email\Ticket\Component\CommentBlock;
use App\Notification\Email\Ticket\Component\StatusTransition;
use App\Notification\Email\Ticket\Component\TicketCard;

/**
 * Combo notification: status changed AND new comment in the same operation.
 * Theme: actualizacion (purple). Sent to requester only.
 */
final class TicketUpdatedTemplate implements EmailTemplate
{
    public function key(): string
    {
        return 'ticket_updated';
    }

    public function render(TemplateContext $ctx): RenderedEmail
    {
        $theme = EmailTheme::actualizacion();
        $agentName = $ctx->actor?->name !== '' && $ctx->actor !== null
            ? (string)$ctx->actor->name
            : 'Mesa de Ayuda';

        $subject = $agentName . ' actualizó tu ticket #' . $ctx->ticket->ticket_number;

        $inner =
            Greeting::render(
                headline: 'Tu ticket fue actualizado',
                intro: 'hubo dos cambios en tu ticket: cambió el estado y un agente añadió un comentario. Aquí el detalle:',
                recipientName: $ctx->recipientName,
            )
            . $this->renderBadgeBanner($theme)
            . StatusTransition::render(
                (string)($ctx->oldStatus ?? ''),
                (string)($ctx->newStatus ?? ''),
                $theme->accent,
            )
            . CommentBlock::render(
                authorName: $agentName,
                authorRole: (string)($ctx->actor->role ?? ''),
                authorColor: $theme->accent,
                bodyHtml: (string)($ctx->comment?->body ?? ''),
                accent: $theme->accent,
                accentSoft: $theme->accentSoft,
                timestamp: '',
            )
            . TicketCard::render($ctx->ticket)
            . CtaButton::render('Ver actualización completa', $theme->accent, $ctx->ticketUrl);

        return new RenderedEmail($subject, EmailFrame::render(
            $theme,
            $inner,
            '#' . $ctx->ticket->ticket_number,
        ));
    }

    private function renderBadgeBanner(EmailTheme $theme): string
    {
        $wrap = 'display:flex;align-items:center;gap:8px;padding:12px 14px;'
            . 'margin-bottom:18px;background:' . $theme->accentSoft . ';border-radius:10px;';
        $pill = 'display:inline-flex;align-items:center;gap:6px;padding:4px 10px;'
            . 'border-radius:999px;background:#fff;color:' . $theme->accentInk . ';'
            . 'font-size:11px;font-weight:600;border:1px solid ' . $theme->accent . '33;';

        return '<div style="' . $wrap . '">'
            . '<span style="' . $pill . '">↻ Cambio de estado</span>'
            . '<span style="color:' . $theme->accentInk . ';font-size:11px;font-weight:600;">+</span>'
            . '<span style="' . $pill . '">💬 Comentario del agente</span>'
            . '</div>';
    }
}
```

- [ ] **Step 4: Test pasa**

- [ ] **Step 5: cs-fix y commit**

```bash
composer cs-fix && composer cs-check
git add src/Notification/Email/Ticket/Template/TicketUpdatedTemplate.php tests/TestCase/Notification/Email/Ticket/Template/TicketUpdatedTemplateTest.php
git commit -m "feat(notification): add TicketUpdatedTemplate (combo)"
```

---

## Task 22: `TemplateRegistry`

**Files:**
- Create: `src/Notification/Email/TemplateRegistry.php`
- Test: `tests/TestCase/Notification/Email/TemplateRegistryTest.php`

- [ ] **Step 1: Test fallido**

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Notification\Email;

use App\Notification\Email\TemplateRegistry;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class TemplateRegistryTest extends TestCase
{
    public function testResolvesAllFourTicketKeys(): void
    {
        $registry = new TemplateRegistry();

        self::assertSame('ticket_created', $registry->get('ticket_created')->key());
        self::assertSame('ticket_status_changed', $registry->get('ticket_status_changed')->key());
        self::assertSame('ticket_comment_added', $registry->get('ticket_comment_added')->key());
        self::assertSame('ticket_updated', $registry->get('ticket_updated')->key());
    }

    public function testAllReturnsFourTemplates(): void
    {
        $registry = new TemplateRegistry();
        self::assertCount(4, $registry->all());
    }

    public function testUnknownKeyThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new TemplateRegistry())->get('does_not_exist');
    }
}
```

- [ ] **Step 2: FAIL**

- [ ] **Step 3: Implementar**

`src/Notification/Email/TemplateRegistry.php`:

```php
<?php
declare(strict_types=1);

namespace App\Notification\Email;

use App\Notification\Email\Ticket\Template\TicketCommentAddedTemplate;
use App\Notification\Email\Ticket\Template\TicketCreatedTemplate;
use App\Notification\Email\Ticket\Template\TicketStatusChangedTemplate;
use App\Notification\Email\Ticket\Template\TicketUpdatedTemplate;
use InvalidArgumentException;

/**
 * Resolves EmailTemplate instances by key. Stateless and instance-free —
 * each invocation builds fresh templates (they're tiny and immutable).
 */
final class TemplateRegistry
{
    /** @var array<string, EmailTemplate> */
    private array $templates;

    public function __construct()
    {
        $instances = [
            new TicketCreatedTemplate(),
            new TicketStatusChangedTemplate(),
            new TicketCommentAddedTemplate(),
            new TicketUpdatedTemplate(),
        ];

        foreach ($instances as $tpl) {
            $this->templates[$tpl->key()] = $tpl;
        }
    }

    public function get(string $key): EmailTemplate
    {
        if (!isset($this->templates[$key])) {
            throw new InvalidArgumentException("Unknown email template: {$key}");
        }

        return $this->templates[$key];
    }

    /** @return list<EmailTemplate> */
    public function all(): array
    {
        return array_values($this->templates);
    }
}
```

- [ ] **Step 4: Test pasa**

- [ ] **Step 5: cs-fix y commit**

```bash
composer cs-fix && composer cs-check
git add src/Notification/Email/TemplateRegistry.php tests/TestCase/Notification/Email/TemplateRegistryTest.php
git commit -m "feat(notification): add TemplateRegistry"
```

---

## Task 23: Rewire `EmailService` para usar `TemplateRegistry`

**Files:**
- Modify: `src/Service/EmailService.php`

Esta tarea es de cambio interno significativo; no hay tests previos para EmailService, así que la verificación es:
1. `composer test` sigue verde (las pruebas existentes no tocan EmailService).
2. PHPStan sigue limpio.
3. cs-check pasa.

- [ ] **Step 1: Sustituir el archivo**

Reemplaza el contenido completo de `src/Service/EmailService.php` con:

```php
<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\CacheConstants;
use App\Constants\SettingKeys;
use App\Notification\Email\TemplateContext;
use App\Notification\Email\TemplateRegistry;
use App\Service\Dto\SystemConfig;
use App\Service\Renderer\NotificationRenderer;
use App\Service\Traits\GenericAttachmentTrait;
use Cake\Datasource\EntityInterface;
use Cake\Log\Log;
use Cake\ORM\Locator\LocatorAwareTrait;
use Exception;

/**
 * Email Service.
 *
 * Builds a TemplateContext from a ticket-side mutation and dispatches the
 * rendered email through Gmail. Templates and components live in
 * App\Notification\Email\* — this class is the thin orchestrator.
 */
class EmailService
{
    use LocatorAwareTrait;
    use GenericAttachmentTrait;
    use Traits\ConfigResolutionTrait;

    private const ENTITY_TABLE = 'Tickets';
    private const COMMENTS_TABLE = 'TicketComments';
    private const ENTITY_CONTAIN = ['Requesters', 'Assignees', 'Attachments'];
    private const ATTACHMENTS_PROPERTY = 'attachments';
    private const COMMENT_FOREIGN_KEY = 'comment_id';

    private NotificationRenderer $renderer;
    private TemplateRegistry $templates;
    private ?SystemConfig $config;
    private ?array $systemConfig = null;
    private ?GmailService $gmailService = null;

    public function __construct(?SystemConfig $config = null)
    {
        $this->config = $config;
        $this->systemConfig = $config?->toSettingsArray();
        $this->renderer = new NotificationRenderer();
        $this->templates = new TemplateRegistry();
    }

    private function getSettingValue(string $key, string $default = ''): string
    {
        return $this->resolveSettingValue($key, $default);
    }

    public function sendNewEntityNotification(EntityInterface $entity): bool
    {
        try {
            $entity = $this->fetchTable(self::ENTITY_TABLE)->get($entity->id, contain: ['Requesters']);

            $excludeEmails = [
                strtolower($entity->requester->email),
                strtolower($this->getSettingValue(SettingKeys::GMAIL_USER_EMAIL)),
            ];
            $additionalTo = $this->filterEmailRecipients($entity->email_to, $excludeEmails);
            $additionalCc = $this->filterEmailRecipients($entity->email_cc, $excludeEmails);

            $ctx = new TemplateContext(
                ticket: $entity,
                ticketUrl: $this->renderer->getTicketUrl($entity->id),
                recipientName: (string)($entity->requester->name ?? ''),
            );

            $rendered = $this->templates->get('ticket_created')->render($ctx);

            return $this->sendEmail(
                to: (string)($entity->requester->email ?? ''),
                subject: $rendered->subject,
                body: $rendered->bodyHtml,
                attachments: [],
                additionalTo: $additionalTo,
                additionalCc: $additionalCc,
            );
        } catch (Exception $e) {
            Log::error('Failed to send ticket created email', [
                'entity_id' => $entity->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function sendEntityStatusChangeNotification(EntityInterface $entity, string $oldStatus, string $newStatus): bool
    {
        try {
            $entity = $this->fetchTable(self::ENTITY_TABLE)->get($entity->id, contain: self::ENTITY_CONTAIN);

            $ctx = new TemplateContext(
                ticket: $entity,
                ticketUrl: $this->renderer->getTicketUrl($entity->id),
                recipientName: (string)($entity->requester->name ?? ''),
                oldStatus: $oldStatus,
                newStatus: $newStatus,
                actor: $entity->assignee ?? null,
            );

            $rendered = $this->templates->get('ticket_status_changed')->render($ctx);

            return $this->sendEmail(
                to: (string)($entity->requester->email ?? ''),
                subject: $rendered->subject,
                body: $rendered->bodyHtml,
            );
        } catch (Exception $e) {
            Log::error('Failed to send ticket status email', [
                'entity_id' => $entity->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function sendEntityCommentNotification(EntityInterface $entity, EntityInterface $comment, array $additionalTo = [], array $additionalCc = []): bool
    {
        return $this->sendCommentBasedNotification('ticket_comment_added', $entity, $comment, null, null, $additionalTo, $additionalCc);
    }

    public function sendEntityResponseNotification(EntityInterface $entity, EntityInterface $comment, string $oldStatus, string $newStatus, array $additionalTo = [], array $additionalCc = []): bool
    {
        return $this->sendCommentBasedNotification('ticket_updated', $entity, $comment, $oldStatus, $newStatus, $additionalTo, $additionalCc);
    }

    private function sendCommentBasedNotification(
        string $templateKey,
        EntityInterface $entity,
        EntityInterface $comment,
        ?string $oldStatus,
        ?string $newStatus,
        array $additionalTo = [],
        array $additionalCc = [],
    ): bool {
        try {
            $entity = $this->fetchTable(self::ENTITY_TABLE)->get($entity->id, contain: self::ENTITY_CONTAIN);
            $comment = $this->fetchTable(self::COMMENTS_TABLE)->get($comment->id, contain: ['Users']);

            $commentAttachments = [];
            if (!empty($entity->{self::ATTACHMENTS_PROPERTY})) {
                foreach ($entity->{self::ATTACHMENTS_PROPERTY} as $attachment) {
                    if ($attachment->{self::COMMENT_FOREIGN_KEY} === $comment->id && !$attachment->is_inline) {
                        $commentAttachments[] = $attachment;
                    }
                }
            }

            $ctx = new TemplateContext(
                ticket: $entity,
                ticketUrl: $this->renderer->getTicketUrl($entity->id),
                recipientName: (string)($entity->requester->name ?? ''),
                comment: $comment,
                oldStatus: $oldStatus,
                newStatus: $newStatus,
                actor: $comment->user ?? null,
                commentAttachments: $commentAttachments,
            );

            $rendered = $this->templates->get($templateKey)->render($ctx);

            return $this->sendEmail(
                to: (string)($entity->requester->email ?? ''),
                subject: $rendered->subject,
                body: $rendered->bodyHtml,
                attachments: $commentAttachments,
                additionalTo: $additionalTo,
                additionalCc: $additionalCc,
            );
        } catch (Exception $e) {
            Log::error('Failed to send ticket comment notification', [
                'entity_id' => $entity->id,
                'comment_id' => $comment->id,
                'template' => $templateKey,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function getGmailService(): GmailService
    {
        if ($this->gmailService === null) {
            $this->gmailService = new GmailService(GmailService::loadConfigFromDatabase());
        }

        return $this->gmailService;
    }

    private function sendEmail(string $to, string $subject, string $body, array $attachments = [], array $additionalTo = [], array $additionalCc = []): bool
    {
        try {
            $systemTitle = $this->getSettingValue(SettingKeys::SYSTEM_TITLE, CacheConstants::DEFAULT_SYSTEM_TITLE);
            $fromEmail = $this->getSettingValue(SettingKeys::GMAIL_USER_EMAIL, 'noreply@localhost');

            $toRecipients = [$to => $to];

            foreach ($additionalTo as $recipient) {
                if (!empty($recipient['email'])) {
                    $toRecipients[$recipient['email']] = $recipient['name'] ?? $recipient['email'];
                }
            }

            $ccRecipients = [];
            foreach ($additionalCc as $recipient) {
                if (!empty($recipient['email'])) {
                    $ccRecipients[$recipient['email']] = $recipient['name'] ?? $recipient['email'];
                }
            }

            $attachmentPaths = [];
            foreach ($attachments as $attachment) {
                $filePath = $this->getFullPath($attachment);
                if (file_exists($filePath)) {
                    $attachmentPaths[] = $filePath;
                }
            }

            $options = [
                'from' => [$fromEmail => $systemTitle],
                'headers' => ['X-Mesa-Ayuda-Notification' => 'true'],
            ];

            if (!empty($ccRecipients)) {
                $options['cc'] = $ccRecipients;
            }

            $result = $this->getGmailService()->sendEmail($toRecipients, $subject, $body, $attachmentPaths, $options);

            if ($result) {
                Log::info('Email sent successfully via Gmail API', ['to' => $to, 'subject' => $subject]);
            }

            return $result;
        } catch (Exception $e) {
            Log::error('Failed to send email via Gmail API', [
                'to' => $to,
                'subject' => $subject,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function filterEmailRecipients(string|array|null $recipients, array $excludeEmails): array
    {
        if (empty($recipients)) {
            return [];
        }

        $decoded = is_string($recipients) ? json_decode($recipients, true) : $recipients;
        if (!is_array($decoded)) {
            return [];
        }

        $filtered = [];
        foreach ($decoded as $recipient) {
            if (!empty($recipient['email'])) {
                $email = strtolower($recipient['email']);
                if (!in_array($email, $excludeEmails, true)) {
                    $filtered[] = $recipient;
                }
            }
        }

        return $filtered;
    }
}
```

- [ ] **Step 2: Correr toda la suite**

```bash
composer cs-fix && composer cs-check && composer test
```

Esperado: tests existentes pasan (ninguno toca EmailService).

- [ ] **Step 3: PHPStan**

```bash
vendor/bin/phpstan analyse src
```

Esperado: sin errores nuevos. Si phpstan no estaba en este nivel de proyecto, salta este step y registra en notas.

- [ ] **Step 4: Commit**

```bash
git add src/Service/EmailService.php
git commit -m "refactor(email): EmailService consumes TemplateRegistry instead of DB templates"
```

---

## Task 24: Drop `sendEntityAssignmentNotification` (ya implícito en Task 23) — eliminar también del listener y del notification service

**Files:**
- Modify: `src/Listener/TicketNotificationListener.php`
- Modify: `src/Service/TicketNotificationService.php`
- Modify: `src/Domain/Event/TicketAssigned.php`

- [ ] **Step 1: Limpiar `TicketNotificationListener`**

Edita `src/Listener/TicketNotificationListener.php`:

1. Borra el `use App\Domain\Event\TicketAssigned;` del bloque de imports.
2. En `implementedEvents()`, borra la línea `TicketAssigned::NAME => 'onAssigned',`.
3. Borra completo el método `public function onAssigned(TicketAssigned $event): void { ... }`.
4. Actualiza el PHPDoc de la clase para añadir una línea: *"La asignación de agente se manejaba aquí (`onAssigned`); fue removida intencionalmente — ya no se notifica por email al agente recién asignado. El evento de dominio `TicketAssigned` sigue disponible para futuros suscriptores (audit, integraciones)."*

- [ ] **Step 2: Limpiar el `case 'assignment'` en `TicketNotificationService`**

En `src/Service/TicketNotificationService.php`, dentro de `dispatchUpdateNotifications`, borra todo el `case 'assignment':` con su lógica de short-circuit y la llamada a `sendEntityAssignmentNotification`. El `default` que loguea "Unknown notification type" se encarga de cualquier llamada externa rezagada.

Patch:

```diff
-                case 'assignment':
-                    $newAssigneeId = $context['new_assignee_id'] ?? null;
-                    $actorId = $context['actor_id'] ?? null;
-                    // No assignee to notify (unassign), or actor self-assigned:
-                    // skip silently — we don't email an agent about their own action.
-                    if ($newAssigneeId === null || $newAssigneeId === $actorId) {
-                        break;
-                    }
-                    $this->emailService->sendEntityAssignmentNotification($entity);
-                    break;
-
```

- [ ] **Step 3: PHPDoc de `TicketAssigned`**

En `src/Domain/Event/TicketAssigned.php`, añade al PHPDoc de la clase: *"Currently has no listener after the email assignment notification was removed (see TicketNotificationListener). Remains in place for future audit/integration subscribers."*

- [ ] **Step 4: Verificar**

```bash
composer cs-fix && composer cs-check && composer test
```

Esperado: verde.

- [ ] **Step 5: Commit**

```bash
git add src/Listener/TicketNotificationListener.php src/Service/TicketNotificationService.php src/Domain/Event/TicketAssigned.php
git commit -m "refactor(notifications): drop ticket assignment email handler"
```

---

## Task 25: Trim `NotificationRenderer` — drop `renderStatusChangeHtml` y `renderAttachmentsHtml`

**Files:**
- Modify: `src/Service/Renderer/NotificationRenderer.php`

- [ ] **Step 1: Verificar usos**

```bash
```

Usa Grep:

```
Grep pattern: "renderStatusChangeHtml|renderAttachmentsHtml"
path: src/
```

Esperado: 0 ocurrencias en `src/` después de la Task 23 (EmailService ya no los llama).

- [ ] **Step 2: Borrar ambos métodos**

Edita `src/Service/Renderer/NotificationRenderer.php` y elimina los métodos `renderStatusChangeHtml()` y `renderAttachmentsHtml()` completos junto con su PHPDoc. Elimina también `use App\Constants\TicketConstants;` si queda huérfano (revisa: `getStatusLabel` también lo usa, así que probablemente se queda).

- [ ] **Step 3: Verificar**

```bash
composer cs-fix && composer cs-check && composer test
```

- [ ] **Step 4: Commit**

```bash
git add src/Service/Renderer/NotificationRenderer.php
git commit -m "chore(renderer): drop status-change and attachments HTML renderers (moved to email components)"
```

---

## Task 26: Borrar `EmailTemplateRenderer`, entidad `EmailTemplate` y tabla `EmailTemplatesTable`

**Files:**
- Delete: `src/Service/EmailTemplateRenderer.php`
- Delete: `src/Model/Entity/EmailTemplate.php`
- Delete: `src/Model/Table/EmailTemplatesTable.php`

- [ ] **Step 1: Confirmar que nadie los usa**

Usa Grep:

```
Grep pattern: "EmailTemplateRenderer"
path: src/

Grep pattern: "App\\\\Model\\\\Entity\\\\EmailTemplate|App\\\\Model\\\\Table\\\\EmailTemplatesTable|fetchTable\\(['\"]EmailTemplates['\"]\\)"
path: src/
```

Esperado: el único uso de `fetchTable('EmailTemplates')` será en `EmailTemplatesController` (se reescribe en Task 28). `EmailTemplateRenderer` ya no se usa.

Si la búsqueda arroja un uso de `EmailTemplatesController`, está bien — se reescribe en Task 28; igual borramos el controller actual primero o lo reescribimos antes. **Orden seguro:** ejecutar Task 28 ANTES de esta Task. Recomendación: reordenar si todavía no se llegó aquí.

(Si llegaste aquí siguiendo el orden propuesto y el grep muestra el controller usando la tabla, salta a Task 28 primero, después vuelve.)

- [ ] **Step 2: Borrar los 3 archivos**

```bash
rm src/Service/EmailTemplateRenderer.php
rm src/Model/Entity/EmailTemplate.php
rm src/Model/Table/EmailTemplatesTable.php
```

- [ ] **Step 3: Verificar**

```bash
composer cs-fix && composer cs-check && composer test
```

- [ ] **Step 4: Commit**

```bash
git add -A
git commit -m "chore(email): remove EmailTemplateRenderer, entity and table (templates live in code)"
```

---

## Task 27: `PreviewFixture` para la UI admin

**Files:**
- Create: `src/Notification/Email/PreviewFixture.php`

- [ ] **Step 1: Implementar**

`src/Notification/Email/PreviewFixture.php`:

```php
<?php
declare(strict_types=1);

namespace App\Notification\Email;

use App\Model\Entity\Ticket;
use App\Model\Entity\TicketComment;
use App\Model\Entity\User;
use Cake\I18n\DateTime;

/**
 * Builds an in-memory TemplateContext for admin previews.
 *
 * No DB access; entities are constructed via mass-assignment guarded off.
 * Variant flags let admin previews show each template with relevant context.
 */
final class PreviewFixture
{
    public const VARIANT_CREATED = 'created';
    public const VARIANT_STATUS = 'status';
    public const VARIANT_COMMENT = 'comment';
    public const VARIANT_UPDATED = 'updated';

    public static function context(string $variant): TemplateContext
    {
        $requester = new User();
        $requester->set([
            'id' => 10,
            'name' => 'Alexander Caicedo',
            'email' => 'alex@operadoracafetera.com',
            'role' => 'Auxiliar de sistemas',
        ], ['guard' => false]);

        $assignee = new User();
        $assignee->set([
            'id' => 20,
            'name' => 'Maira Pérez',
            'email' => 'maira@operadoracafetera.com',
            'role' => 'Líder de soporte',
        ], ['guard' => false]);

        $ticket = new Ticket();
        $ticket->set([
            'id' => 1,
            'ticket_number' => 'TKT-1284',
            'subject' => 'Cafetera #14 no enciende',
            'status' => 'pendiente',
            'priority' => 'alta',
            'requester' => $requester,
            'assignee' => $assignee,
            'tags' => ['Mantenimiento', 'Sucursal Norte'],
            'created' => new DateTime('2026-05-14 13:42:00'),
        ], ['guard' => false]);

        $comment = new TicketComment();
        $comment->set([
            'id' => 99,
            'body' => '<p>Hola Alexander, ya estamos revisando. El equipo lanza un código E07 '
                . 'que típicamente está asociado al motor. Mañana a las 8:00 a.m. pasa Daniel '
                . 'del taller a hacer diagnóstico in situ. ¿Puedes confirmar disponibilidad '
                . 'en la sucursal?</p>',
            'user' => $assignee,
            'created' => new DateTime('2026-05-14 13:50:00'),
        ], ['guard' => false]);

        $ctx = static fn (array $extra): TemplateContext => new TemplateContext(
            ticket: $ticket,
            ticketUrl: 'https://example.com/tickets/view/1',
            recipientName: 'Alexander',
            ...$extra,
        );

        return match ($variant) {
            self::VARIANT_STATUS => $ctx([
                'oldStatus' => 'abierto',
                'newStatus' => 'pendiente',
                'actor' => $assignee,
            ]),
            self::VARIANT_COMMENT => $ctx([
                'comment' => $comment,
                'actor' => $assignee,
            ]),
            self::VARIANT_UPDATED => $ctx([
                'comment' => $comment,
                'oldStatus' => 'abierto',
                'newStatus' => 'pendiente',
                'actor' => $assignee,
            ]),
            default => $ctx([]),
        };
    }

    /**
     * Map a template key to its preview variant.
     */
    public static function variantForKey(string $key): string
    {
        return match ($key) {
            'ticket_status_changed' => self::VARIANT_STATUS,
            'ticket_comment_added' => self::VARIANT_COMMENT,
            'ticket_updated' => self::VARIANT_UPDATED,
            default => self::VARIANT_CREATED,
        };
    }
}
```

- [ ] **Step 2: cs-check pasa**

```bash
composer cs-fix && composer cs-check
```

- [ ] **Step 3: Commit**

```bash
git add src/Notification/Email/PreviewFixture.php
git commit -m "feat(notification): add PreviewFixture for admin preview UI"
```

---

## Task 28: `TemplateDescriptor` VO + reescribir `EmailTemplatesController`

**Files:**
- Create: `src/Notification/Email/Admin/TemplateDescriptor.php`
- Modify: `src/Controller/Admin/EmailTemplatesController.php`

- [ ] **Step 1: `TemplateDescriptor`**

`src/Notification/Email/Admin/TemplateDescriptor.php`:

```php
<?php
declare(strict_types=1);

namespace App\Notification\Email\Admin;

/**
 * Read-only descriptor of a registered email template, surfaced by the
 * admin index/preview pages.
 */
final readonly class TemplateDescriptor
{
    public function __construct(
        public string $key,
        public string $accentColor,
        public string $accentSoftColor,
        public string $tag,
        public string $description,
    ) {
    }
}
```

- [ ] **Step 2: Reescribir el controller**

Reemplaza el contenido completo de `src/Controller/Admin/EmailTemplatesController.php` con:

```php
<?php
declare(strict_types=1);

namespace App\Controller\Admin;

use App\Constants\RoleConstants;
use App\Notification\Email\Admin\TemplateDescriptor;
use App\Notification\Email\EmailTheme;
use App\Notification\Email\PreviewFixture;
use App\Notification\Email\TemplateRegistry;
use Cake\Event\EventInterface;
use Cake\Http\Exception\NotFoundException;

/**
 * EmailTemplates Controller (Admin) — read-only previewer.
 *
 * Templates live in code (App\Notification\Email\*). This controller lists
 * registered templates and renders an HTML preview against a static fixture.
 * Editing is intentionally not supported; changes require a deploy.
 */
class EmailTemplatesController extends AppController
{
    public function beforeFilter(EventInterface $event)
    {
        parent::beforeFilter($event);

        return $this->redirectByRole([RoleConstants::ROLE_ADMIN], 'admin');
    }

    /**
     * List all registered templates.
     */
    public function index(): void
    {
        $registry = new TemplateRegistry();
        $descriptors = [];
        foreach ($registry->all() as $template) {
            $descriptors[] = $this->descriptorFor($template->key());
        }

        $this->set(compact('descriptors'));
    }

    /**
     * Render a preview of one template using fixture data.
     */
    public function preview(?string $key = null): void
    {
        $registry = new TemplateRegistry();
        try {
            $template = $registry->get((string)$key);
        } catch (\InvalidArgumentException) {
            throw new NotFoundException();
        }

        $ctx = PreviewFixture::context(PreviewFixture::variantForKey($template->key()));
        $rendered = $template->render($ctx);

        $this->viewBuilder()->setLayout('ajax');
        $this->set([
            'subject' => $rendered->subject,
            'bodyHtml' => $rendered->bodyHtml,
            'descriptor' => $this->descriptorFor($template->key()),
        ]);
    }

    private function descriptorFor(string $key): TemplateDescriptor
    {
        return match ($key) {
            'ticket_created' => new TemplateDescriptor(
                key: $key,
                accentColor: EmailTheme::creacion()->accent,
                accentSoftColor: EmailTheme::creacion()->accentSoft,
                tag: EmailTheme::creacion()->tag,
                description: 'Notifica al solicitante que su ticket fue creado correctamente.',
            ),
            'ticket_status_changed' => new TemplateDescriptor(
                key: $key,
                accentColor: EmailTheme::estado()->accent,
                accentSoftColor: EmailTheme::estado()->accentSoft,
                tag: EmailTheme::estado()->tag,
                description: 'Notifica al solicitante que el estado de su ticket cambió.',
            ),
            'ticket_comment_added' => new TemplateDescriptor(
                key: $key,
                accentColor: EmailTheme::comentario()->accent,
                accentSoftColor: EmailTheme::comentario()->accentSoft,
                tag: EmailTheme::comentario()->tag,
                description: 'Notifica al solicitante que un agente respondió a su ticket.',
            ),
            'ticket_updated' => new TemplateDescriptor(
                key: $key,
                accentColor: EmailTheme::actualizacion()->accent,
                accentSoftColor: EmailTheme::actualizacion()->accentSoft,
                tag: EmailTheme::actualizacion()->tag,
                description: 'Combina cambio de estado y comentario en una sola notificación.',
            ),
            default => new TemplateDescriptor($key, '#6B7280', '#F3F4F6', '', ''),
        };
    }
}
```

- [ ] **Step 3: cs-check y tests**

```bash
composer cs-fix && composer cs-check && composer test
```

- [ ] **Step 4: Commit**

```bash
git add src/Notification/Email/Admin/TemplateDescriptor.php src/Controller/Admin/EmailTemplatesController.php
git commit -m "refactor(admin): rewrite EmailTemplatesController to preview-only"
```

---

## Task 29: Reescribir `templates/Admin/EmailTemplates/index.php`

**Files:**
- Modify: `templates/Admin/EmailTemplates/index.php`

- [ ] **Step 1: Reemplazar el archivo**

`templates/Admin/EmailTemplates/index.php`:

```php
<?php
/**
 * @var \App\View\AppView $this
 * @var list<\App\Notification\Email\Admin\TemplateDescriptor> $descriptors
 */
$this->assign('title', 'Plantillas de email');
$this->assign('active_workspace', 'templates');
?>

<header class="app-page-header">
    <nav class="app-breadcrumb" aria-label="breadcrumb">
        <i class="bi bi-grid-1x2"></i>
        <span>Workspace</span>
        <i class="bi bi-chevron-right separator"></i>
        <span class="current">Plantillas de email</span>
    </nav>

    <div class="app-page-header-row">
        <div class="app-page-header-text">
            <h1 class="app-page-title">Plantillas de email</h1>
            <div class="app-page-stats">
                <span class="stat-inline">
                    <span class="dot" style="background: var(--admin-green);"></span>
                    <span class="value emphasis"><?= count($descriptors) ?></span>
                    <span class="label">plantillas</span>
                </span>
                <span class="stat-inline">
                    <span class="label">Sólo lectura — viven en el código</span>
                </span>
            </div>
        </div>
    </div>
</header>

<?php if (!empty($descriptors)): ?>
    <div class="app-grid wide">
        <?php foreach ($descriptors as $d): ?>
            <article class="app-card">
                <div class="app-card-header">
                    <div class="app-card-header-icon" style="background: <?= h($d->accentSoftColor) ?>; color: <?= h($d->accentColor) ?>;">
                        <i class="bi bi-envelope-paper"></i>
                    </div>
                    <div class="app-card-header-text">
                        <h3 class="app-card-header-title mono"><?= h($d->key) ?></h3>
                        <div class="app-card-header-subtitle"><?= h($d->tag) ?></div>
                    </div>
                </div>
                <div class="app-card-body">
                    <p class="app-card-body-text"><?= h($d->description) ?></p>
                </div>
                <div class="app-card-footer between">
                    <span class="muted">Edición deshabilitada</span>
                    <?= $this->Html->link(
                        '<i class="bi bi-eye"></i> Previsualizar',
                        ['controller' => 'EmailTemplates', 'action' => 'preview', $d->key],
                        ['class' => 'btn-brand-primary btn-brand-sm', 'target' => '_blank', 'escape' => false]
                    ) ?>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
<?php else: ?>
    <?= $this->element('empty_state', [
        'icon'    => 'envelope-x',
        'tone'    => 'neutral',
        'title'   => 'No hay plantillas registradas',
        'message' => 'Define plantillas implementando App\\Notification\\Email\\EmailTemplate.',
    ]) ?>
<?php endif; ?>
```

- [ ] **Step 2: cs-check**

```bash
composer cs-fix && composer cs-check
```

- [ ] **Step 3: Commit**

```bash
git add templates/Admin/EmailTemplates/index.php
git commit -m "refactor(admin): rewrite email templates index as read-only grid"
```

---

## Task 30: Reescribir `templates/Admin/EmailTemplates/preview.php`

**Files:**
- Modify: `templates/Admin/EmailTemplates/preview.php`

- [ ] **Step 1: Reemplazar el archivo**

`templates/Admin/EmailTemplates/preview.php`:

```php
<?php
/**
 * @var \App\View\AppView $this
 * @var string $subject
 * @var string $bodyHtml
 * @var \App\Notification\Email\Admin\TemplateDescriptor $descriptor
 */
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Preview — <?= h($descriptor->key) ?></title>
<style>
  body {
    margin: 0;
    background: #f0eee9;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", system-ui, sans-serif;
    color: #111827;
  }
  .preview-meta {
    max-width: 720px;
    margin: 24px auto 12px;
    padding: 12px 16px;
    background: #fff;
    border: 1px solid #E5E7EB;
    border-radius: 8px;
    font-size: 12px;
    color: #4B5563;
  }
  .preview-meta .label { color: #6B7280; margin-right: 6px; }
  .preview-meta .subject { color: #111827; font-weight: 600; }
  .preview-canvas { padding: 0 0 32px; }
</style>
</head>
<body>

<div class="preview-meta">
  <span class="label">Plantilla:</span>
  <span class="subject mono"><?= h($descriptor->key) ?></span>
  &nbsp;·&nbsp;
  <span class="label">Asunto:</span>
  <span class="subject"><?= h($subject) ?></span>
</div>

<div class="preview-canvas">
  <?= $bodyHtml /* trusted: rendered by our own template */ ?>
</div>

</body>
</html>
```

- [ ] **Step 2: cs-check**

```bash
composer cs-fix && composer cs-check
```

- [ ] **Step 3: Commit**

```bash
git add templates/Admin/EmailTemplates/preview.php
git commit -m "refactor(admin): rewrite email templates preview to use code templates"
```

---

## Task 31: Borrar `templates/Admin/EmailTemplates/edit.php`

**Files:**
- Delete: `templates/Admin/EmailTemplates/edit.php`

- [ ] **Step 1: Verificar que nadie referencia `action => 'edit'` en views/controllers**

Usa Grep:

```
Grep pattern: "'action' => 'edit'.*EmailTemplates|EmailTemplates.*'action' => 'edit'"
path: templates/
```

Esperado: 0. Si aparece algo (era la card de `index.php`), ya fue reescrita en Task 29.

- [ ] **Step 2: Borrar**

```bash
rm templates/Admin/EmailTemplates/edit.php
```

- [ ] **Step 3: Verificar**

```bash
composer cs-check && composer test
```

- [ ] **Step 4: Commit**

```bash
git add -A
git commit -m "chore(admin): drop email template edit view (preview-only now)"
```

---

## Task 32: Migración para drop `email_templates`

**Files:**
- Create: `config/Migrations/{TIMESTAMP}_DropEmailTemplatesTable.php`

- [ ] **Step 1: Generar timestamp y crear el archivo**

Genera un timestamp `YYYYMMDDHHMMSS` con la fecha de hoy. Por ejemplo, para 2026-05-15 a las 16:00:00 sería `20260515160000`.

```bash
TIMESTAMP=$(date +%Y%m%d%H%M%S)
echo "config/Migrations/${TIMESTAMP}_DropEmailTemplatesTable.php"
```

- [ ] **Step 2: Crear la migración**

Contenido:

```php
<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class DropEmailTemplatesTable extends BaseMigration
{
    /**
     * Drops the email_templates table — templates now live in code under
     * App\Notification\Email\*. Any custom-edited rows are intentionally
     * discarded; the deployment runbook recommends a mysqldump backup
     * before applying.
     */
    public function up(): void
    {
        if ($this->hasTable('email_templates')) {
            $this->table('email_templates')->drop()->update();
        }
    }

    public function down(): void
    {
        // Recreates the original structure so rollback succeeds. Seeded data
        // is NOT restored.
        $this->table('email_templates')
            ->addColumn('template_key', 'string', ['limit' => 100, 'null' => false])
            ->addColumn('subject',      'string', ['limit' => 255, 'null' => false])
            ->addColumn('body_html',    'text',   ['null' => true])
            ->addColumn('available_variables', 'text', ['null' => true])
            ->addColumn('is_active',    'boolean', ['default' => true, 'null' => false])
            ->addColumn('created',      'datetime', ['null' => true])
            ->addColumn('modified',     'datetime', ['null' => true])
            ->addIndex(['template_key'], ['unique' => true])
            ->create();
    }
}
```

- [ ] **Step 3: Aplicar localmente y verificar**

```bash
bin/cake migrations migrate
bin/cake migrations status
```

Esperado: la tabla `email_templates` ya no existe; status lista la nueva migración como ejecutada.

- [ ] **Step 4: Probar rollback (opcional pero recomendado)**

```bash
bin/cake migrations rollback
bin/cake migrations migrate
```

Esperado: rollback recrea la tabla vacía, migrate vuelve a borrarla.

- [ ] **Step 5: Commit**

```bash
git add config/Migrations/*_DropEmailTemplatesTable.php
git commit -m "feat(migration): drop email_templates table (templates moved to code)"
```

---

## Task 33: Smoke manual final

Verificación completa antes de marcar el feature como listo.

- [ ] **Step 1: Suite completa limpia**

```bash
composer cs-fix && composer cs-check
composer test
vendor/bin/phpstan analyse src
```

Esperado: todo verde.

- [ ] **Step 2: Server local + admin preview**

```bash
bin/cake server
```

Visita en navegador:
- `http://localhost:8765/admin/email-templates` — debe mostrar 4 cards (creación / estado / comentario / actualización), sin botón "Editar".
- `http://localhost:8765/admin/email-templates/preview/ticket_created` — abre en pestaña nueva, debe verse como `Plantillas de Correo.html` artboard 1.
- Idem `preview/ticket_status_changed`, `preview/ticket_comment_added`, `preview/ticket_updated`.

Compara visualmente con el bundle `Plantillas de Correo.html` (si aún lo tienes localmente, o usando el preview HTML como referencia).

- [ ] **Step 3: Smoke real (opcional, si Gmail está configurado en local)**

Desde el front:
1. Crear un ticket nuevo.
2. Cambiar su estado.
3. Añadir un comentario público.
4. Añadir un comentario público + cambio de estado simultáneo.

Verifica que llegan 4 correos al solicitante (creación, estado, comentario, actualización) y que se ven bien en Gmail web.

- [ ] **Step 4: PR**

Crea PR contra `main` describiendo:
- Plantillas migradas de BD a código.
- UI admin sólo lectura.
- Notificación de asignación eliminada.
- Tabla `email_templates` borrada.
- Tests unitarios añadidos por componente y plantilla.

---

## Self-review

**Spec coverage:**
- §4.1 estructura de directorios → tasks 1–22, 27, 28.
- §4.2 capas → tasks 6–16 + 17 (interfaz) + 22 (registry).
- §4.3 contrato de componentes → cubierto en tasks 6–16.
- §4.4 EmailTheme → task 3.
- §4.5 TemplateContext → task 5.
- §4.6 RenderedEmail → task 2.
- §4.7 EmailTemplate → task 17.
- §4.8 TemplateRegistry → task 22.
- §4.9 EmailBrand → task 4.
- §5 las 4 plantillas → tasks 18–21.
- §6 rewire EmailService → task 23.
- §7 UI admin → tasks 28–31, fixture en 27.
- §8 cambios fuera de Notification → task 24 (listener/notif service/event PHPDoc), task 25 (NotificationRenderer), task 26 (deletes).
- §9 migración → task 32.
- §10 asset logo → task 1.
- §11 estrategia email-safe → aplicada dentro de cada componente.
- §12 plan de tests → cubierto por cada task de componente/plantilla.
- §13 seguridad → contrato documentado en task 5 (PHPDoc TemplateContext) y task 15 (CommentBlock).
- §14 compatibilidad operativa → mencionada en task 32.
- §15 riesgos → mitigaciones reflejadas en tasks 32 (backup), 33 (smoke).
- §16 verificación pre-merge → task 33.

**Placeholder scan:** revisado, sin TBD/TODO/"implementar después". Cada step tiene comandos exactos y código completo.

**Type consistency:**
- `EmailTemplate::render(TemplateContext): RenderedEmail` usado consistentemente en tasks 17–21.
- `TemplateRegistry::get(string): EmailTemplate` y `all(): list<EmailTemplate>` consistentes entre 22, 28.
- `EmailTheme` propiedades `accent`, `accentSoft`, `accentInk`, `tag` consistentes.
- `Pill::render(string $label, string $bg, string $fg, ?string $dotColor = null)` y `Pill::forStatus(string)` consistentes entre 6, 14.
- `Avatar::render(string $initials, string $color, int $size = 32)` y `Avatar::initialsFromName(string): string` consistentes entre 7, 15, 16, 19.
- `EmailFrame::render(EmailTheme, string $innerHtml, string $ticketReference)` consistente entre 12 y todas las plantillas.
- `Card::render(string $headerLeftHtml, string $headerRightHtml, string $title, list<string> $tags, list<array> $metaColumns)` consistente entre 11 y 16.
- `InfoBox::render(string $label, string $contentHtml, string $variant, ?string $accentSoft)` consistente entre 9 y 18.

Sin inconsistencias detectadas. Plan listo.
