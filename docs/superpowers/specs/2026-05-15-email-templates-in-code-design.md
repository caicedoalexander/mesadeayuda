# Plantillas de correo en código — Diseño

**Fecha:** 2026-05-15
**Estado:** propuesta
**Autor:** brainstorming asistido (Alexander Caicedo)
**Diseño visual fuente:** `Plantillas de Correo.html` del handoff Claude Design (4 artboards: creación, estado, comentario, actualización)

## 1. Resumen

Migrar las plantillas de correo transaccionales de la tabla `email_templates` (BD) a código PHP. Implementar el rediseño visual nuevo (4 plantillas con tema cromático por tipo) usando un sistema de componentes reutilizables que pueda servir a otros módulos en el futuro. La UI admin de plantillas se conserva pero sólo como previsualizador (sin edición).

## 2. Motivación

- Las plantillas en BD son frágiles: requieren migración + seed para cada cambio visual, y un admin distraído puede romperlas editando HTML inline.
- El rediseño nuevo (4 plantillas con componentes ricos: avatares, status transitions, comment blocks) es difícil de mantener como blob HTML editable en BD.
- Otros módulos podrían enviar correos en el futuro (notificaciones administrativas, reportes); un sistema de componentes en código se reutiliza.

## 3. Alcance

### Incluido

- 4 plantillas nuevas en código: `ticket_created`, `ticket_status_changed`, `ticket_comment_added`, `ticket_updated`.
- Sistema de componentes reutilizables (genéricos + específicos de ticket).
- Reescritura de `EmailService` para usar un `TemplateRegistry` en lugar de cargar de BD.
- UI admin reducida a vista de previsualización (`/admin/email-templates` index + preview, sin edit).
- Migración para eliminar tabla `email_templates`.
- Eliminación de la notificación de asignación al agente (`ticket_asignacion`).
- Tests unitarios de componentes y plantillas.

### Excluido

- WhatsApp templates (siguen en `NotificationRenderer::renderWhatsappNewTicket`).
- Plantillas para otros módulos (sólo se preparan los componentes genéricos para reuso futuro).
- Sistema de A/B testing, override por tenant, o multi-idioma.
- Editor visual de plantillas.
- Cambios en cómo se envía el correo (Gmail API sigue igual).

## 4. Arquitectura

### 4.1 Estructura de directorios

```
src/Notification/Email/
├── Component/                          ← componentes genéricos reutilizables
│   ├── EmailFrame.php                  ← wrap: accent bar + header logo + body slot + footer
│   ├── Greeting.php                    ← saludo "Hola {nombre}, {intro}" + headline
│   ├── Card.php                        ← card genérico con header strip, body, meta-grid
│   ├── CtaButton.php                   ← botón + fallback URL en mono
│   ├── InfoBox.php                     ← caja dashed/soft con label uppercase
│   ├── Avatar.php                      ← círculo iniciales + color
│   └── Pill.php                        ← badge redondeado (status, priority, tag)
├── Ticket/
│   ├── Component/                      ← componentes específicos de ticket
│   │   ├── TicketCard.php
│   │   ├── StatusTransition.php
│   │   ├── CommentBlock.php
│   │   └── PriorityArrow.php
│   └── Template/                       ← las 4 plantillas
│       ├── TicketCreatedTemplate.php
│       ├── TicketStatusChangedTemplate.php
│       ├── TicketCommentAddedTemplate.php
│       └── TicketUpdatedTemplate.php
├── EmailTemplate.php                   ← interfaz: render(TemplateContext): RenderedEmail
├── EmailTheme.php                      ← VO con accent/accentSoft/accentInk + factories
├── TemplateContext.php                 ← VO con ticket/comment/oldStatus/newStatus/actor/...
├── RenderedEmail.php                   ← VO readonly { subject, bodyHtml }
├── TemplateRegistry.php                ← mapa key → EmailTemplate
├── EmailBrand.php                      ← constantes: logo URL absoluta, dirección, NIT, soporte
└── PreviewFixture.php                  ← genera un TemplateContext de muestra para /admin preview
```

### 4.2 Capas y dependencias

```
EmailService (Service)
    ↓ usa
TemplateRegistry → EmailTemplate (interfaz)
    ↓ implementan
TicketCreatedTemplate / TicketStatusChangedTemplate / TicketCommentAddedTemplate / TicketUpdatedTemplate
    ↓ componen
Ticket\Component\* (TicketCard, StatusTransition, CommentBlock, PriorityArrow)
    ↓ componen
Component\* (EmailFrame, Greeting, Card, CtaButton, InfoBox, Avatar, Pill)
    ↓ usan
EmailTheme, EmailBrand, NotificationRenderer (formatDate, getTicketUrl, getStatusLabel)
```

Los componentes genéricos no conocen Ticket. Los componentes en `Ticket/Component/` reciben una entidad `Ticket` y la traducen a props para los componentes genéricos.

### 4.3 Componentes — contrato

Cada componente expone:

```php
final class Foo
{
    public static function render(/* props nombrados */): string;
}
```

Devuelve un fragmento HTML email-safe con estilos inline. Componentes que reciben texto controlado por usuario escapan internamente con `h()` / `htmlspecialchars()`. El único campo HTML rico (cuerpo del comentario) llega ya sanitizado por `HtmlSanitizerTrait` antes de entrar al `TemplateContext`; los componentes lo insertan tal cual.

### 4.4 EmailTheme

VO inmutable con cuatro factories:

```php
final readonly class EmailTheme
{
    public function __construct(
        public string $accent,        // color base
        public string $accentSoft,    // fondo accent claro
        public string $accentInk,     // texto sobre fondo soft
        public string $tag,           // etiqueta "Nuevo ticket" / "Cambio de estado" / etc.
    ) {}

    public static function creacion(): self      { /* #CD6A15 / #FCEFE0 / #6b3306 / 'Nuevo ticket' */ }
    public static function estado(): self        { /* #0066cc / #E3EFFC / #0a3a78 / 'Cambio de estado' */ }
    public static function comentario(): self    { /* #00A85E / #E6F7EE / #00432a / 'Nuevo comentario' */ }
    public static function actualizacion(): self { /* #7c3aed / #F0EBFE / #3c1d8a / 'Actualización' */ }
}
```

### 4.5 TemplateContext

```php
final readonly class TemplateContext
{
    public function __construct(
        public Ticket $ticket,
        public string $ticketUrl,
        public string $recipientName,
        public ?TicketComment $comment = null,
        public ?string $oldStatus = null,
        public ?string $newStatus = null,
        public ?User $actor = null,
        public array $commentAttachments = [],
    ) {}
}
```

**Contrato de seguridad:** `$comment->body` debe estar pre-sanitizado por `HtmlSanitizerTrait`. El resto de strings se asume texto controlado por usuario y se escapan en los componentes. Documentado en PHPDoc del VO.

### 4.6 RenderedEmail

```php
final readonly class RenderedEmail
{
    public function __construct(
        public string $subject,
        public string $bodyHtml,
    ) {}
}
```

### 4.7 EmailTemplate (interfaz)

```php
interface EmailTemplate
{
    public function key(): string;
    public function render(TemplateContext $ctx): RenderedEmail;
}
```

### 4.8 TemplateRegistry

```php
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
        return $this->templates[$key]
            ?? throw new InvalidArgumentException("Unknown email template: {$key}");
    }

    /** @return list<EmailTemplate> */
    public function all(): array { return array_values($this->templates); }
}
```

### 4.9 EmailBrand

Clase con constantes (no settings dinámicos):

```php
final class EmailBrand
{
    public const ORG_NAME       = 'Operadora Cafetera S.A.S.';
    public const ORG_TAG_LINE   = 'MESA DE AYUDA · OPERADORA CAFETERA';
    public const ORG_ADDRESS    = 'Carrera 43A #1-50, Medellín';
    public const ORG_NIT        = '901.234.567-8';
    public const SUPPORT_EMAIL  = 'soporte@operadoracafetera.com';
    public const HEADER_TITLE   = 'Mesa de Ayuda';
    public const HEADER_SUBTITLE = 'Soporte Interno';

    public static function logoUrl(): string
    {
        // URL absoluta usando Router::url('/img/logo-mesa-ayuda.svg', true)
        return Router::url('/img/logo-mesa-ayuda.svg', true);
    }
}
```

El SVG se sirve desde `webroot/img/logo-mesa-ayuda.svg` (copiado del bundle de diseño).

## 5. Las 4 plantillas

### 5.1 TicketCreatedTemplate — `ticket_created`

- Tema: `creacion` (naranja)
- Subject: `Tu ticket #{ticket_number} fue creado`
- Destinatario: requester
- Composición del body:
  1. `Greeting` — headline "Tu ticket fue creado", intro "Hemos recibido tu solicitud y la asignaremos pronto a un agente. Mientras tanto, este es el resumen:"
  2. `TicketCard($ticket)`
  3. `InfoBox(variant: 'dashed', label: 'Próximos pasos')` — `<ol>` con 3 items hardcodeados:
     - "Un agente tomará el ticket en los próximos **30 minutos**."
     - "Recibirás un correo cuando el ticket sea asignado o cambie de estado."
     - "Puedes añadir información respondiendo este correo o desde la mesa de ayuda."
  4. `CtaButton('Ver mi ticket', $theme->accent, $ctx->ticketUrl)`

### 5.2 TicketStatusChangedTemplate — `ticket_status_changed`

- Tema: `estado` (azul)
- Subject: `El estado de tu ticket #{ticket_number} cambió a {new_status_label}`
- Destinatario: requester
- Composición:
  1. `Greeting` — headline "El estado de tu ticket cambió"
  2. `StatusTransition($oldStatus, $newStatus, $theme->accent)`
  3. `TicketCard($ticket)`
  4. *(opcional, si `$ctx->actor !== null`)* banner `soft` con `Avatar($actor)` + "**{actorName}** aplicó este cambio."
  5. `CtaButton('Ver el ticket', $theme->accent, $ctx->ticketUrl)`

### 5.3 TicketCommentAddedTemplate — `ticket_comment_added`

- Tema: `comentario` (verde)
- Subject: `{agent_name} te respondió en el ticket #{ticket_number}`
  (si `$comment->user` es null, fallback "Mesa de Ayuda te respondió en el ticket #{ticket_number}")
- Destinatario: requester
- Composición:
  1. `Greeting` — headline "Tienes una nueva respuesta", intro "{agente} respondió a tu ticket. Puedes contestar desde la mesa de ayuda o respondiendo este correo."
  2. `CommentBlock($author, $body, $theme)`
  3. `TicketCard($ticket)`
  4. Hint inline (caja gris con ícono de reply) — "Responde desde este mismo correo / Cualquier texto que envíes responderá automáticamente al hilo del ticket…"
  5. `CtaButton('Responder en la mesa de ayuda', $theme->accent, $ctx->ticketUrl)`

### 5.4 TicketUpdatedTemplate — `ticket_updated`

- Tema: `actualizacion` (morado)
- Subject: `{agent_name} actualizó tu ticket #{ticket_number}` (fallback "Mesa de Ayuda")
- Destinatario: requester
- Composición:
  1. `Greeting` — headline "Tu ticket fue actualizado"
  2. Banner `soft` accent morado con doble badge: "↻ Cambio de estado" + "+" + "💬 Comentario del agente" + timestamp
  3. `StatusTransition($oldStatus, $newStatus, $theme->accent)`
  4. `CommentBlock($author, $body, $theme)`
  5. `TicketCard($ticket)`
  6. `CtaButton('Ver actualización completa', $theme->accent, $ctx->ticketUrl)`

## 6. Rewire de `EmailService`

### 6.1 Cambios en el constructor

```php
public function __construct(?SystemConfig $config = null)
{
    $this->config    = $config;
    $this->renderer  = new NotificationRenderer();
    $this->templates = new TemplateRegistry();
    // Eliminado: $this->templateRenderer (EmailTemplateRenderer)
    // Eliminado: $this->systemConfig (array view de $config)
}
```

`ConfigResolutionTrait` deja de ser necesario para esta clase si no quedan llamadas a `resolveSettingValue()` fuera de `sendEmail()`. Se evalúa en implementación.

### 6.2 Métodos afectados

| Método | Cambio |
|---|---|
| `sendNewEntityNotification` | Construye `TemplateContext` con ticket+requester+ticketUrl, pide `ticket_created`, despacha. |
| `sendEntityStatusChangeNotification` | Añade `oldStatus`, `newStatus` al context; pide `ticket_status_changed`. |
| `sendEntityCommentNotification` | Construye context con comment+actor; pide `ticket_comment_added`. |
| `sendEntityResponseNotification` | Context con comment + status delta; pide `ticket_updated`. |
| `sendEntityAssignmentNotification` | **Eliminado.** |
| `getTemplate`, `replaceVariables`, `getSystemVariables`, `buildCommentVariables`, `getAgentProfileImageUrl`, `getView`, `getAbsoluteUrl` | Eliminados (los componentes resuelven sus propias URLs vía `Router::url(..., true)`). |
| `sendEmail` | Sin cambios. |
| `filterEmailRecipients` | Sin cambios. |
| `sendGenericTemplateEmail`, `sendCommentBasedNotification` | Eliminados o reducidos drásticamente (cada `sendXxx()` queda lo bastante corto para inlining directo). |
| `RAW_HTML_VARIABLES` constante | Eliminada (el contrato de sanitización vive en `TemplateContext`). |

## 7. UI admin (preview-only)

### 7.1 Controller `EmailTemplatesController`

Reescrito a dos acciones:

- `index()` — pide `TemplateRegistry::all()`, mapea a una lista de `TemplateDescriptor { key, subjectTemplate, accentColor, description, themeTag }` y la pasa a la vista. Sin POST.
- `preview(string $key)` — pide `TemplateRegistry::get($key)`, construye `PreviewFixture::context()`, llama `render($ctx)` y pasa `$rendered->bodyHtml` + `$rendered->subject` a la vista.

`TemplateDescriptor` puede ser un VO pequeño dentro del namespace `Notification\Email\Admin\`.

### 7.2 Vistas

- `templates/Admin/EmailTemplates/index.php` — reescrita, cards una por plantilla con accent color en el icono, subject template como preview text y un solo botón "Ver previsualización".
- `templates/Admin/EmailTemplates/preview.php` — simplificada: muestra subject arriba, HTML body en un wrapper centrado de 720px que imita el fondo `#E8E6E1` del diseño. Sin variables, sin "ejemplo de uso".
- `templates/Admin/EmailTemplates/edit.php` — **eliminada**.

### 7.3 PreviewFixture

Stub estático que produce un `TemplateContext` con datos verosímiles sin tocar BD. Implementación: crea entidades `Ticket`, `TicketComment`, `User`, etc. con `new Ticket(['...' => ...])` y los relaciona en memoria. No persiste.

## 8. Cambios fuera del namespace `Notification`

### 8.1 `App\Service\EmailTemplateRenderer`

**Eliminado.**

### 8.2 `App\Service\Renderer\NotificationRenderer`

Mantiene: `formatDate`, `getTicketUrl`, `getStatusLabel`, `renderWhatsappNewTicket`.
Elimina: `renderStatusChangeHtml`, `renderAttachmentsHtml`.

### 8.3 `App\Model\Entity\EmailTemplate` y `App\Model\Table\EmailTemplatesTable`

**Eliminados.**

### 8.4 `App\Listener\TicketNotificationListener`

- Elimina handler `onAssigned()`.
- Elimina entrada `TicketAssigned::NAME => 'onAssigned'` en `implementedEvents()`.
- Elimina `use TicketAssigned;` si queda huérfano.
- Extiende el comentario PHPDoc para documentar que la asignación dejó de notificar por email intencionalmente (cita commit edac652 y este spec).

### 8.5 `App\Domain\Event\TicketAssigned`

**No se elimina.** Se queda disponible para futuros suscriptores (audit, integraciones). Se documenta en su PHPDoc que actualmente no tiene listener.

### 8.6 `App\Service\TicketNotificationService`

Si `dispatchUpdateNotifications($ticket, 'assignment', ...)` despachaba al método `sendEntityAssignmentNotification` ahora eliminado, esa rama del switch/case se elimina. Revisar en implementación.

## 9. Migración

Una nueva migración: `YYYYMMDDHHMMSS_DropEmailTemplatesTable.php`.

```php
public function up(): void
{
    if ($this->hasTable('email_templates')) {
        $this->table('email_templates')->drop()->update();
    }
}

public function down(): void
{
    // Recrea estructura; los datos no se restauran (las plantillas viven en código).
    $this->table('email_templates')
        ->addColumn('template_key', 'string', ['limit' => 100, 'null' => false])
        ->addColumn('subject',      'string', ['limit' => 255, 'null' => false])
        ->addColumn('body_html',    'text',   ['null' => true])
        ->addColumn('available_variables', 'text', ['null' => true])
        ->addColumn('is_active',    'boolean',['default' => true, 'null' => false])
        ->addColumn('created',      'datetime',['null' => true])
        ->addColumn('modified',     'datetime',['null' => true])
        ->addIndex(['template_key'], ['unique' => true])
        ->create();
}
```

La migración previa `20260514120000_AddTicketAssignedEmailTemplate` permanece en el historial; no se borran migraciones ya aplicadas.

## 10. Assets

- `webroot/img/logo-mesa-ayuda.svg` — copiar del bundle de diseño (`mesa-de-ayuda/project/assets/logo-mesa-ayuda.svg`).
- `EmailBrand::logoUrl()` lo resuelve a URL absoluta vía `Router::url('/img/logo-mesa-ayuda.svg', true)`.

## 11. Estrategia de HTML email-safe

- Estilos **inline en cada elemento** (sin `<style>` tag — Gmail lo respeta condicionalmente, mejor no depender).
- Layout con `<table>` cuando necesite columnas resistentes a Outlook (meta-grid del `TicketCard`).
- Fonts: `font-family: "Geist", -apple-system, BlinkMacSystemFont, "Segoe UI", system-ui, sans-serif`. No se embeben web fonts.
- Border-radius, sombras suaves: se aceptan; degradan en Outlook a cajas planas.
- No grid CSS. Flex permitido sólo en filas simples (`display: flex`) — Outlook lo ignora pero el resto de clientes lo respeta.
- Anchos máximos: container 720px (consistente con el diseño).
- Sin SVG inline en el body del correo: el logo del header se embebe como `<img src="{logoUrl}">`; los iconitos del banner combo de "Actualización" se reemplazan por glyphs unicode (↻, 💬, →) o `<img>`s pequeños alojados en webroot.

## 12. Plan de tests

| Archivo | Cobertura |
|---|---|
| `tests/TestCase/Notification/Email/Component/EmailFrameTest.php` | accent bar color, logo URL, header title/subtitle, footer presente |
| `tests/TestCase/Notification/Email/Component/CtaButtonTest.php` | label, accent background, URL en href y en fallback line |
| `tests/TestCase/Notification/Email/Component/PillTest.php` | render para cada status conocido + fallback de label |
| `tests/TestCase/Notification/Email/Component/StatusTransitionTest.php` | from y to aparecen con labels correctos; accent en caja "ahora" |
| `tests/TestCase/Notification/Email/Component/CommentBlockTest.php` | author name escapado; body HTML pasa tal cual (no double-escape) |
| `tests/TestCase/Notification/Email/Ticket/Template/TicketCreatedTemplateTest.php` | subject contiene ticket_number; body contiene headline, ticket subject, CTA URL |
| `tests/TestCase/Notification/Email/Ticket/Template/TicketStatusChangedTemplateTest.php` | subject menciona el nuevo estado; body tiene StatusTransition con ambos status |
| `tests/TestCase/Notification/Email/Ticket/Template/TicketCommentAddedTemplateTest.php` | subject menciona el agente; CommentBlock incluye body sanitizado sin re-escapar |
| `tests/TestCase/Notification/Email/Ticket/Template/TicketUpdatedTemplateTest.php` | subject combo; body incluye banner doble + StatusTransition + CommentBlock |
| `tests/TestCase/Notification/Email/TemplateRegistryTest.php` | `get()` resuelve las 4 keys; `get('unknown')` lanza InvalidArgumentException |

No se agregan tests de integración con Gmail API (no existen hoy y serían sobre-ingeniería). No se agregan golden snapshots (descartado por mantenimiento).

## 13. Seguridad

- Contrato de sanitización: `TemplateContext::$comment->body` debe llegar pasado por `HtmlSanitizerTrait`. Esto ya ocurre hoy en `EmailService::sendCommentBasedNotification`. Se documenta en PHPDoc del VO.
- Resto de strings (subject, names, status labels, ticket_number) se tratan como texto controlado por usuario y se escapan en los componentes con `h()`.
- No se introducen nuevos puntos de entrada de HTML usuario más allá de los actuales.

## 14. Compatibilidad y migración operativa

- La tabla `email_templates` se elimina en la misma release. No hay "modo dual" intermedio: si se hace rollback, también hay que hacer rollback de la migración (`migrations rollback`).
- Los enlaces a `/admin/email-templates` siguen funcionando (sólo cambia su contenido a sólo lectura).
- Si en producción existen plantillas custom modificadas vía admin, **se pierden** al hacer el drop. Antes del deploy se recomienda hacer un `mysqldump` de la tabla por si hay que recuperar contenido manualmente. Documentado en notas de release.

## 15. Riesgos

| Riesgo | Mitigación |
|---|---|
| Rotura visual en Outlook desktop | HTML email-safe; QA manual del preview en Gmail web (audiencia interna principal) + un cliente Outlook si es accesible. |
| Pérdida de personalización custom existente | Backup pre-deploy de `email_templates`; revisión en code-review de si hay diferencias importantes con las plantillas seed actuales. |
| `TicketNotificationService::dispatchUpdateNotifications('assignment', ...)` deja una rama muerta | Revisar y limpiar como parte de la implementación. |
| El comentario del usuario pasa con HTML peligroso | Contrato de sanitización ya existente (`HtmlSanitizerTrait`). El cambio no relaja el contrato. |
| Tamaño de email crece por estilos inline | Aceptable; los correos modernos manejan 100KB+ sin problema. |

## 16. Verificación pre-merge

- `composer cs-fix && composer cs-check` limpio.
- `vendor/bin/phpstan analyse src` sin errores nuevos.
- `composer test` verde.
- `bin/cake migrations migrate` aplica el drop sin error.
- Smoke manual: las 4 previews en `/admin/email-templates/preview/{key}` se ven consistentes con el diseño.
- Smoke real (recomendado): crear un ticket en local, cambiar estado y comentar; verificar visualmente los 3 correos recibidos en Gmail web.

## 17. Decisiones clave (referencia rápida)

1. **DB → código:** elimina tabla `email_templates`, plantillas viven en clases.
2. **UI admin:** sobrevive en modo sólo lectura (index + preview, sin edit).
3. **`ticket_asignacion`:** se elimina (no se notifica al agente recién asignado).
4. **Componentes:** clases PHP con builders estáticos; genéricos en `Component/`, específicos en `Ticket/Component/`.
5. **Naming:** keys nuevas `ticket_created` / `ticket_status_changed` / `ticket_comment_added` / `ticket_updated`. Componentes genéricos sin "ticket" en su API.
6. **Branding:** constantes en `EmailBrand` (logo, dirección, NIT, correo humano).
7. **HTML email-safe:** estilos inline, tablas para layout cuando convenga, fonts con system fallback.
