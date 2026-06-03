# Eliminar la abstracción de numeración de tickets — Plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Hacer del `tickets.id` autoincremental (desde 1000) el único identificador del ticket, eliminando la tabla `ticket_number_sequences`, el `NumberGenerationService` y la columna `ticket_number`.

**Architecture:** MySQL `AUTO_INCREMENT` provee unicidad y atomicidad sin código propio. `ticket_number` se elimina por completo; todas sus ~15 referencias pasan a `id`. El número visible conserva el prefijo `#` en UI/emails ("#1000"). Los payloads salientes a n8n ya incluían `id`, así que solo se elimina la clave redundante `ticket_number`.

**Tech Stack:** CakePHP 5.x, PHP 8.5+, MySQL/MariaDB, Phinx migrations, PHPUnit, PHP_CodeSniffer (CakePHP ruleset).

**Spec:** `docs/superpowers/specs/2026-06-03-ticket-numbering-cleanup-design.md`

**Rama:** `refactor/ticket-numbering-cleanup` (ya creada).

**Nota de entorno:** la suite de tests NO usa BD (ver `tests/bootstrap.php`); son tests unitarios puros. Los cambios de esquema se verifican aparte recreando la BD de desarrollo.

**Comandos de verificación recurrentes:**
- Suite completa: `composer test`
- Test único: `vendor/bin/phpunit --filter NombreDelTest`
- Estilo: `composer cs-fix && composer cs-check`

---

## Task 1: Limpiar el historial de migraciones (esquema)

**Files:**
- Modify: `config/Migrations/20260430213127_Initial.php:566-571` (columna) y `:658-662` (índice) y `:713` (tras `->create()`)
- Delete: `config/Migrations/20260509120000_AddTicketNumberSequencesTable.php`
- Delete: `config/Migrations/20260515120000_SwitchTicketNumberToGlobalCounter.php`

- [ ] **Step 1: Eliminar la columna `ticket_number` de la tabla `tickets`**

En `config/Migrations/20260430213127_Initial.php`, borrar este bloque completo (líneas ~566-571):

```php
            ->addColumn('ticket_number', 'string', [
                'comment' => 'Unique ticket identifier. Format: TKT-YYYY-NNNNN',
                'default' => null,
                'limit' => 20,
                'null' => false,
            ])
```

- [ ] **Step 2: Eliminar el índice único de `ticket_number`**

En el mismo archivo, borrar este bloque (líneas ~658-662):

```php
            ->addIndex(
                $this->index('ticket_number')
                    ->setName('idx_ticket_number_unique')
                    ->setType('unique')
            )
```

- [ ] **Step 3: Sembrar AUTO_INCREMENT=1000 tras crear la tabla**

En el mismo archivo, localizar el cierre de la definición de `tickets`:

```php
            ->create();

        $this->table('tickets_tags')
```

Insertar el `ALTER` entre `->create();` y la siguiente tabla:

```php
            ->create();

        // Los tickets nuevos arrancan su id (identificador visible) en 1000.
        $this->execute('ALTER TABLE tickets AUTO_INCREMENT = 1000');

        $this->table('tickets_tags')
```

- [ ] **Step 4: Borrar las dos migraciones de secuencias**

```bash
git rm config/Migrations/20260509120000_AddTicketNumberSequencesTable.php
git rm config/Migrations/20260515120000_SwitchTicketNumberToGlobalCounter.php
```

- [ ] **Step 5: Verificar la migración sobre BD limpia (entorno dev)**

Recrear el esquema desde cero y comprobar que no quedan rastros:

Run: `bin/cake migrations migrate`
Expected: migra sin errores; la tabla `tickets` se crea sin columna `ticket_number` y sin la tabla `ticket_number_sequences`.

Comprobación manual (opcional, MySQL): `SHOW COLUMNS FROM tickets LIKE 'ticket_number';` → 0 filas; `SHOW TABLES LIKE 'ticket_number_sequences';` → 0 filas.

> Si la BD dev ya tenía estas migraciones aplicadas, recrear el esquema (drop + `migrations migrate`) — estamos en desarrollo, no hay datos que preservar.

- [ ] **Step 6: Commit**

```bash
git add config/Migrations/20260430213127_Initial.php
git commit -m "refactor: eliminar columna ticket_number y tabla de secuencias del esquema"
```

---

## Task 2: Eliminar el contador — factories, ingestión, servicio y generador

Unidad atómica: el `id` lo asigna MySQL en `save()`, así que las factories dejan de recibir número, la ingestión deja de generarlo, y se borran `NumberGenerationService` + `generateTicketNumber()`. La validación `requirePresence('ticket_number','create')` DEBE eliminarse o la creación de tickets fallaría validación.

**Files:**
- Modify: `tests/TestCase/Model/Entity/TicketTest.php:212-245`
- Modify: `tests/TestCase/Model/Entity/TicketWhatsappFactoryTest.php` (completo)
- Modify: `src/Model/Entity/Ticket.php:291-324` y `:340-359`
- Modify: `src/Service/TicketIngestionService.php:98-127` y `:235-244`
- Modify: `src/Model/Table/TicketsTable.php:8,52,95-102,174,195-198,289`
- Modify: `src/Controller/Trait/TicketListingTrait.php:148`
- Delete: `src/Service/NumberGenerationService.php`

- [ ] **Step 1: Actualizar los tests de las factories (RED)**

En `tests/TestCase/Model/Entity/TicketTest.php`, en `testFromEmailIngestSetsInitialStatusAndPriority()` (línea ~212), quitar el argumento `ticketNumber:` y la aserción del número:

```php
    public function testFromEmailIngestSetsInitialStatusAndPriority(): void
    {
        $ticket = Ticket::fromEmailIngest(
            requesterId: 42,
            subject: 'Mi pedido',
            sanitizedDescription: '<p>cuerpo limpio</p>',
            channel: 'email',
            sourceEmail: 'cliente@example.com',
        );

        self::assertSame('nuevo', $ticket->status);
        self::assertSame('media', $ticket->priority);
        self::assertSame(42, $ticket->requester_id);
        self::assertSame('Mi pedido', $ticket->subject);
        self::assertSame('<p>cuerpo limpio</p>', $ticket->description);
        self::assertSame('email', $ticket->channel);
        self::assertSame('cliente@example.com', $ticket->source_email);
    }
```

En el mismo archivo, en `testFromEmailIngestFallsBackToSinAsuntoWhenSubjectEmpty()` (línea ~233), quitar `ticketNumber: 'T-0002',`:

```php
        $ticket = Ticket::fromEmailIngest(
            requesterId: 1,
            subject: '',
            sanitizedDescription: '',
            channel: 'email',
            sourceEmail: 'x@y.z',
        );
```

> Revisar el resto de `TicketTest.php`: cualquier otra llamada a `Ticket::fromEmailIngest(` con `ticketNumber:` debe perder ese argumento (p. ej. `testFromEmailIngestPassesThroughGmailIdsAndRecipients` en ~247).

- [ ] **Step 2: Actualizar `TicketWhatsappFactoryTest` (RED)**

Reemplazar el contenido de los dos tests en `tests/TestCase/Model/Entity/TicketWhatsappFactoryTest.php` para quitar `ticketNumber:` y la aserción del número:

```php
    public function testBuildsTicketWithChannelWhatsapp(): void
    {
        $ticket = Ticket::fromWhatsappIngest(
            requesterId: 42,
            subject: 'Impresora',
            sanitizedDescription: '<p>desde ayer</p>',
            sourcePhone: '+573001234567',
            whatsappMessageId: 'wamid.abc',
        );

        self::assertSame(42, $ticket->requester_id);
        self::assertSame('Impresora', $ticket->subject);
        self::assertSame('<p>desde ayer</p>', $ticket->description);
        self::assertSame(TicketConstants::CHANNEL_WHATSAPP, $ticket->channel);
        self::assertSame(TicketConstants::STATUS_NUEVO, $ticket->status);
        self::assertSame(TicketConstants::PRIORITY_MEDIA, $ticket->priority);
        self::assertSame('+573001234567', $ticket->source_phone);
        self::assertSame('wamid.abc', $ticket->whatsapp_message_id);
    }

    public function testReplacesEmptySubjectWithFallback(): void
    {
        $ticket = Ticket::fromWhatsappIngest(
            requesterId: 1,
            subject: '',
            sanitizedDescription: 'x',
            sourcePhone: '+573001234567',
            whatsappMessageId: 'wamid.def',
        );

        self::assertSame('(Sin asunto)', $ticket->subject);
    }
```

- [ ] **Step 3: Ejecutar los tests para verificar el fallo (RED)**

Run: `vendor/bin/phpunit --filter "TicketTest|TicketWhatsappFactoryTest"`
Expected: FAIL con `ArgumentCountError: Too few arguments to function ... fromEmailIngest()/fromWhatsappIngest()`. Las factories todavía declaran `$ticketNumber` como primer parámetro obligatorio, pero los tests ya no lo pasan. El Step 4 elimina ese parámetro y el test pasa a verde.

- [ ] **Step 4: Quitar `$ticketNumber` de las factories del entity**

En `src/Model/Entity/Ticket.php`, en `fromEmailIngest()` (línea ~291) quitar el parámetro y la asignación:

```php
    public static function fromEmailIngest(
        int $requesterId,
        string $subject,
        string $sanitizedDescription,
        string $channel,
        string $sourceEmail,
        ?string $gmailMessageId = null,
        ?string $gmailThreadId = null,
        mixed $emailTo = null,
        mixed $emailCc = null,
        ?string $rfcMessageId = null,
        ?string $inReplyTo = null,
        ?string $referencesHeader = null,
    ): self {
        $ticket = new self();
        $ticket->gmail_message_id = $gmailMessageId;
```

(Eliminar la línea `$ticket->ticket_number = $ticketNumber;` y el `@param string $ticketNumber ...` del docblock.)

En `fromWhatsappIngest()` (línea ~340):

```php
    public static function fromWhatsappIngest(
        int $requesterId,
        string $subject,
        string $sanitizedDescription,
        string $sourcePhone,
        string $whatsappMessageId,
    ): self {
        $ticket = new self();
        $ticket->subject = $subject !== '' ? $subject : '(Sin asunto)';
```

(Eliminar `$ticket->ticket_number = $ticketNumber;` y su `@param`.)

- [ ] **Step 5: Quitar la generación de número en `TicketIngestionService`**

En `src/Service/TicketIngestionService.php`, eliminar la línea `$ticketNumber = $ticketsTable->generateTicketNumber();` (~99) y el comentario `// Generate ticket number` que la precede. En la llamada a `Ticket::fromEmailIngest(` (~113) quitar `ticketNumber: $ticketNumber,`:

```php
        $ticket = Ticket::fromEmailIngest(
            requesterId: (int)$user->id,
            subject: $subject,
            sanitizedDescription: $description,
            channel: $channel,
            sourceEmail: $fromEmail,
            gmailMessageId: $emailData['gmail_message_id'] ?? null,
            gmailThreadId: $emailData['gmail_thread_id'] ?? null,
            emailTo: !empty($emailData['email_to']) ? $emailData['email_to'] : null,
            emailCc: !empty($emailData['email_cc']) ? $emailData['email_cc'] : null,
            rfcMessageId: $emailData['rfc_message_id'] ?? null,
            inReplyTo: $emailData['in_reply_to'] ?? null,
            referencesHeader: $emailData['references_header'] ?? null,
        );
```

En `createFromWhatsapp()`, eliminar la línea `$ticketNumber = $ticketsTable->generateTicketNumber();` (~235) y quitar `ticketNumber: $ticketNumber,` de la llamada a `Ticket::fromWhatsappIngest(` (~237):

```php
        $ticket = Ticket::fromWhatsappIngest(
            requesterId: (int)$user->id,
            subject: $payload->subject,
            sanitizedDescription: $description,
            sourcePhone: $payload->phoneNumber,
            whatsappMessageId: $payload->messageId,
        );
```

Actualizar el comentario en ~151-152 que menciona "ticket_number": cambiarlo a referirse solo a `ticket.id`:

```php
        // Must run AFTER the initial ticket save so we have ticket.id
        // (used to compute the local upload URL). See audit CRIT-4 (F1+F2+G1).
```

- [ ] **Step 6: Limpiar `TicketsTable` (display, validación, regla, generador, búsqueda)**

En `src/Model/Table/TicketsTable.php`:

(a) Quitar el import (línea 8): borrar `use App\Service\NumberGenerationService;`

(b) `setDisplayField` (línea 52):

```php
        $this->setDisplayField('id');
```

(c) Quitar el bloque de validación de `ticket_number` (líneas 97-102), dejando intacto el de `gmail_message_id`:

```php
        $validator
            ->scalar('gmail_message_id')
            ->maxLength('gmail_message_id', 255)
            ->allowEmptyString('gmail_message_id')
            ->add('gmail_message_id', 'unique', ['rule' => 'validateUnique', 'provider' => 'table']);
```

(d) Quitar la regla de unicidad (línea 174): borrar
`$rules->add($rules->isUnique(['ticket_number']), ['errorField' => 'ticket_number']);`

(e) Borrar el método `generateTicketNumber()` completo (líneas ~190-198):

```php
    /**
     * Generate unique ticket number in format TKT-YYYY-NNNNN
     *
     * @return string
     */
    public function generateTicketNumber(): string
    {
        return (new NumberGenerationService())->generate();
    }
```

(f) Búsqueda (línea 289): reemplazar la condición de `ticket_number` por `id` (LIKE conserva el comportamiento de coincidencia parcial previo; MySQL castea el entero a texto):

```php
                    'Tickets.id LIKE' => '%' . $search . '%',
```

- [ ] **Step 7: Quitar `ticket_number` de los campos ordenables**

En `src/Controller/Trait/TicketListingTrait.php`, en `getValidSortFields()` (línea 148), reemplazar `'ticket_number'` por `'id'`:

```php
        return ['created', 'modified', 'status', 'priority', 'subject', 'id'];
```

- [ ] **Step 8: Borrar `NumberGenerationService`**

```bash
git rm src/Service/NumberGenerationService.php
```

- [ ] **Step 9: Ejecutar la suite completa (GREEN)**

Run: `composer test`
Expected: PASS — toda la suite verde. (Las plantillas y estrategias siguen leyendo `ticket_number` por su cuenta; sus tests no dependen de este cambio y siguen pasando.)

- [ ] **Step 10: Estilo y commit**

```bash
composer cs-fix && composer cs-check
git add src/Model/Entity/Ticket.php src/Service/TicketIngestionService.php src/Model/Table/TicketsTable.php src/Controller/Trait/TicketListingTrait.php tests/TestCase/Model/Entity/TicketTest.php tests/TestCase/Model/Entity/TicketWhatsappFactoryTest.php
git commit -m "refactor: usar tickets.id como identificador; eliminar NumberGenerationService"
```

---

## Task 3: Payloads salientes y logs — eliminar la clave redundante `ticket_number`

`N8nService` y `WebhooksController` ya envían `id`/`ticket_id`; la clave `ticket_number` es redundante. Los `Log::info` que la incluyen también la duplican con `ticket_id`.

**Files:**
- Modify: `src/Service/N8nService.php:112,152`
- Modify: `src/Controller/WebhooksController.php:159`
- Modify: `src/Service/TicketIngestionService.php:189,298,323,438`

- [ ] **Step 1: `N8nService` — payload y log**

Línea ~152, borrar del payload la línea:
```php
                'ticket_number' => $ticket->ticket_number,
```
(El payload mantiene `'id' => $ticket->id`, que el workflow de n8n debe leer.)

Línea ~112, borrar del contexto de log:
```php
                    'ticket_number' => $ticket->ticket_number,
```

- [ ] **Step 2: `WebhooksController` — respuesta del import**

Línea ~159, borrar de la respuesta JSON:
```php
                'ticket_number' => $result['ticket']->ticket_number,
```
(La respuesta mantiene `'ticket_id' => (int)$result['ticket']->id`.)

- [ ] **Step 3: `TicketIngestionService` — contextos de log**

Borrar la línea `'ticket_number' => $ticket->ticket_number,` en los cuatro `Log::*` (líneas ~189, ~298, ~323, ~438). Cada uno conserva `'ticket_id' => $ticket->id`.

- [ ] **Step 4: Verificar suite y estilo**

Run: `composer test && composer cs-check`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Service/N8nService.php src/Controller/WebhooksController.php src/Service/TicketIngestionService.php
git commit -m "refactor: eliminar clave redundante ticket_number de payloads y logs"
```

> **Dependencia externa (no es código de este repo):** el workflow de n8n y cualquier consumidor del webhook deben actualizarse para leer `id` en lugar de `ticket_number`. Se documenta en Task 8 (`docs/operations/n8n-gmail-webhook.md`).

---

## Task 4: Adjuntos — nombrar la carpeta por `id`

**Files:**
- Modify: `src/Service/Traits/GenericAttachmentTrait.php:127,307`
- Modify: `src/Service/TicketAttachmentService.php:110` (docblock)

- [ ] **Step 1: Reemplazar `ticket_number` por `id` como nombre de carpeta**

En `src/Service/Traits/GenericAttachmentTrait.php`, en ambas ocurrencias (líneas ~127 y ~307):

```php
        $entityNumber = (string)$entity->id;
```

- [ ] **Step 2: Actualizar el docblock obsoleto**

En `src/Service/TicketAttachmentService.php` (línea ~110), quitar la mención a `ticket_number`:

```php
     * @param \Cake\Datasource\EntityInterface $ticket Ticket entity (must have gmail_message_id)
```

- [ ] **Step 3: Verificar suite y estilo**

Run: `composer test && composer cs-check`
Expected: PASS. (No hay tests que aserten la ruta por `ticket_number`.)

- [ ] **Step 4: Commit**

```bash
git add src/Service/Traits/GenericAttachmentTrait.php src/Service/TicketAttachmentService.php
git commit -m "refactor: nombrar carpeta de adjuntos por ticket id"
```

---

## Task 5: Superficies de notificación (emails) — usar `id`, conservar `#`

**Files:**
- Modify: `src/Notification/Email/Ticket/Template/TicketCreatedTemplate.php:32,39`
- Modify: `src/Notification/Email/Ticket/Template/TicketUpdatedTemplate.php:45`
- Modify: `src/Notification/Email/Ticket/Template/TicketStatusChangedTemplate.php:40`
- Modify: `src/Notification/Email/Ticket/Template/TicketCommentAddedTemplate.php:46`
- Modify: `src/Notification/Email/Ticket/Component/TicketCard.php:37`
- Modify: `src/Service/Renderer/NotificationRenderer.php:84`
- Modify: `src/Notification/Email/PreviewFixture.php:52-53`
- Modify: `tests/TestCase/Notification/Email/Ticket/Template/TicketCreatedTemplateTest.php:49-50,67,70`
- Modify: `tests/TestCase/Notification/Email/Ticket/Template/TicketUpdatedTemplateTest.php:53,76`
- Modify: `tests/TestCase/Notification/Email/Ticket/Template/TicketStatusChangedTemplateTest.php:46,70,85`
- Modify: `tests/TestCase/Notification/Email/Ticket/Template/TicketCommentAddedTemplateTest.php:53,75,95,124`
- Modify: `tests/TestCase/Notification/Email/Ticket/Component/TicketCardTest.php:37-38,55`
- Modify: `tests/TestCase/Notification/Email/TemplateContextTest.php:15,28`

- [ ] **Step 1: Actualizar los tests de plantillas (RED)**

`TicketCreatedTemplateTest.php`: en el `$ticket->set([...])` (líneas ~48-56) cambiar `'id' => 1` por `'id' => 1284` y borrar `'ticket_number' => 'TKT-1284',`. Cambiar aserciones:

```php
        self::assertSame('Tu ticket #1284 fue creado', $email->subject);
```
```php
        self::assertStringContainsString('#1284', $email->bodyHtml);
```

`TicketUpdatedTemplateTest.php`: en el `$ticket->set([...])` (~52) añadir `'id' => 1,` y borrar `'ticket_number' => 'TKT-1',`. Cambiar aserción (~76):
```php
        self::assertStringContainsString('#1', $email->bodyHtml);
```

`TicketStatusChangedTemplateTest.php`: en AMBOS `$ticket->set([...])` (~45 y ~84) añadir `'id' => 1,` y borrar `'ticket_number' => 'TKT-1',`. Cambiar aserción (~70):
```php
        self::assertStringContainsString('El estado de tu ticket #1', $email->bodyHtml);
```

`TicketCommentAddedTemplateTest.php`: en los tres `$ticket->set([...])` (~52, ~94, ~123) añadir `'id' => 1,` y borrar la línea `'ticket_number' => 'TKT-1',` / `'ticket_number' => 'TKT-9',`. Cambiar aserción (~75):
```php
        self::assertStringContainsString('#1', $email->bodyHtml);
```

`TicketCardTest.php`: en el `$t->set(array_merge([...]))` (~36) cambiar `'id' => 1` por `'id' => 1284` y borrar `'ticket_number' => 'TKT-1284',`. Cambiar aserción (~55):
```php
        self::assertStringContainsString('1284', $html);
```

`TemplateContextTest.php`: en `$t->set(...)` (~15) dejar solo `['id' => 1]`:
```php
        $t->set(['id' => 1], ['guard' => false]);
```
Cambiar la aserción (~28):
```php
        self::assertSame(1, $ctx->ticket->id);
```

- [ ] **Step 2: Ejecutar los tests para verificar el fallo**

Run: `vendor/bin/phpunit tests/TestCase/Notification/Email`
Expected: FAIL — las plantillas todavía leen `ticket_number` (vacío), los bodies no contienen `#1284`/`#1`.

- [ ] **Step 3: Actualizar las plantillas de email**

`TicketCreatedTemplate.php` (líneas 32 y 39):

```php
        $subject = 'Tu ticket #' . $ctx->ticket->id . ' fue creado';
```
```php
        $ticketNumber = htmlspecialchars((string)$ctx->ticket->id, ENT_QUOTES | ENT_HTML5, 'UTF-8');
```

`TicketUpdatedTemplate.php` (línea 45), `TicketStatusChangedTemplate.php` (línea 40), `TicketCommentAddedTemplate.php` (línea 46) — misma sustitución en cada uno:

```php
        $ticketNumber = htmlspecialchars((string)$ctx->ticket->id, ENT_QUOTES | ENT_HTML5, 'UTF-8');
```

- [ ] **Step 4: Actualizar `TicketCard` y `NotificationRenderer`**

`TicketCard.php` (línea 37):
```php
        $number = (string)($ticket->id ?? '');
```

`NotificationRenderer.php` (línea 84) — sustituir solo el campo, sin añadir `#` (paridad con el texto WhatsApp original):
```php
            "*{$ticket->id}*\n" .
```

- [ ] **Step 5: Actualizar `PreviewFixture`**

`PreviewFixture.php` (líneas ~52-53): cambiar `'id' => 1` por `'id' => 1284` y borrar `'ticket_number' => 'TKT-1284',`.

- [ ] **Step 6: Ejecutar los tests (GREEN)**

Run: `vendor/bin/phpunit tests/TestCase/Notification/Email`
Expected: PASS.

- [ ] **Step 7: Estilo y commit**

```bash
composer cs-fix && composer cs-check
git add src/Notification tests/TestCase/Notification/Email src/Service/Renderer/NotificationRenderer.php
git commit -m "refactor: emails y tarjeta de ticket muestran id (#1000) en vez de ticket_number"
```

---

## Task 6: Barrido de fixtures de tests restantes

Quitar el relleno `ticket_number` de los tests donde es dato de fixture no aserción (no cambia comportamiento; mantiene las entidades honestas con el esquema nuevo).

**Files:**
- Modify: `tests/TestCase/Service/TicketPipelineServiceTest.php:46,313`
- Modify: `tests/TestCase/Notification/Strategy/TicketStatusChangedStrategyTest.php:169`
- Modify: `tests/TestCase/Notification/Strategy/TicketCreatedStrategyTest.php:115`
- Modify: `tests/TestCase/Notification/Strategy/TicketRespondedStrategyTest.php:110`
- Modify: `tests/TestCase/Notification/Strategy/TicketCommentAddedStrategyTest.php:108`
- Modify: `tests/TestCase/Model/Table/TicketsTableTest.php:29,61,92`

- [ ] **Step 1: Eliminar las líneas `'ticket_number' => ...,` de los fixtures**

En cada archivo y línea listados, borrar la línea `'ticket_number' => '...',` del array que arma la entidad. No tocar nada más. (En `TicketPipelineServiceTest` los valores son `'T-0001'`/`'T-0002'`; en los demás `'TKT-0001'`.)

- [ ] **Step 2: Ejecutar la suite completa**

Run: `composer test`
Expected: PASS.

- [ ] **Step 3: Commit**

```bash
git add tests/
git commit -m "test: quitar fixtures de ticket_number tras eliminar la columna"
```

---

## Task 7: Templates de vista (sin tests unitarios)

**Files:**
- Modify: `templates/Tickets/view.php:12`
- Modify: `templates/Tickets/index.php:170`
- Modify: `templates/element/tickets/header.php:30`

- [ ] **Step 1: `view.php` (línea 12)**

```php
$this->assign('title', '#' . $ticket->id);
```

- [ ] **Step 2: `index.php` (línea 170)**

```php
                                        <span class="mono ticket-id"><?= h($ticket->id) ?></span>
```

- [ ] **Step 3: `header.php` (línea 30)**

```php
        <span class="mono current">#<?= h($entity->id) ?></span>
```

- [ ] **Step 4: Verificación manual (entorno dev)**

Levantar el servidor (`bin/cake server`), crear un ticket y abrir su detalle: el título y el breadcrumb muestran `#1000` (o el id correspondiente); la lista muestra el id en la columna mono.

- [ ] **Step 5: Commit**

```bash
git add templates/
git commit -m "refactor: vistas de tickets muestran id en vez de ticket_number"
```

---

## Task 8: Limpieza del entity, docs y barrido final

**Files:**
- Modify: `src/Model/Entity/Ticket.php:14,57`
- Modify: `CLAUDE.md:98`
- Modify: `README.md:190`
- Modify: `docs/operations/n8n-gmail-webhook.md` (nota de payload)

- [ ] **Step 1: Quitar `ticket_number` del entity**

En `src/Model/Entity/Ticket.php`: borrar la línea del docblock `@property string $ticket_number` (línea 14) y la entrada `'ticket_number' => false,` del array `$_accessible` (línea 57).

- [ ] **Step 2: Actualizar `CLAUDE.md`**

Reemplazar la frase final de la línea 98:

```markdown
`GmailImportService` + `TicketIngestionService` cover the inbound side; UTF-8 + markup-safe truncation lives in `TicketIngestionService`. El identificador del ticket es el `id` autoincremental de la tabla `tickets` (arranca en 1000); MySQL garantiza unicidad y atomicidad — no introducir tablas de secuencia ni contadores propios.
```

- [ ] **Step 3: Actualizar `README.md` (línea 190)**

```markdown
- **Adjuntos:** uso compartido vía `GenericAttachmentTrait`. Almacenamiento en disco local bajo `webroot/uploads/attachments/{id}/`.
```

- [ ] **Step 4: Nota en la doc del webhook n8n**

En `docs/operations/n8n-gmail-webhook.md`, añadir una nota indicando que el payload/respuesta usa la clave `id` (entero, desde 1000) como identificador del ticket; la antigua clave `ticket_number` fue eliminada y el workflow de n8n debe leer `id`.

- [ ] **Step 5: Barrido final — no deben quedar referencias vivas**

Run: `vendor/bin/phpunit` (o `composer test`) y luego buscar referencias:

Buscar en código y tests (deben dar 0 resultados, salvo specs/plans/audits históricos en `docs/`):
- `ticket_number`
- `NumberGenerationService`
- `generateTicketNumber`
- `ticket_number_sequences`

Expected: sin coincidencias en `src/`, `config/Migrations/`, `templates/`, `tests/`. (Las menciones en `docs/superpowers/specs/`, `docs/superpowers/plans/` y `docs/audits/` son historia y se dejan intactas.)

- [ ] **Step 6: Verificación final completa**

Run: `composer cs-fix && composer cs-check && composer test`
Expected: estilo limpio y suite verde.

- [ ] **Step 7: Commit**

```bash
git add src/Model/Entity/Ticket.php CLAUDE.md README.md docs/operations/n8n-gmail-webhook.md
git commit -m "refactor: limpiar entity, docs y notas de integracion tras eliminar ticket_number"
```

---

## Criterios de éxito (verificación global)

1. `grep` de `ticket_number` / `NumberGenerationService` / `ticket_number_sequences` / `generateTicketNumber` no devuelve nada en `src/`, `config/Migrations/`, `templates/`, `tests/`.
2. `bin/cake migrations migrate` sobre BD limpia crea el esquema sin la columna ni la tabla de secuencias.
3. Crear un ticket nuevo le asigna `id = 1000` (el primero) y la UI/email muestran "#1000".
4. `composer test` y `composer cs-check` pasan.
5. El payload a n8n envía `id` (sin `ticket_number`).

## Dependencia externa pendiente (fuera del repo)

Actualizar el workflow de n8n para leer la clave `id` del payload del webhook en lugar de `ticket_number`. Sin este cambio, la integración de etiquetado por IA quedará sin el identificador esperado.
