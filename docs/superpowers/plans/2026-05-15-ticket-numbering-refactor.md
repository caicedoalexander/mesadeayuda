# Ticket Numbering Refactor — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Reemplazar `NumberGenerationService` por un contador global atómico que emita `"1000"`, `"1001"`, … sin tocar tickets ya emitidos ni el resto del flujo de creación.

**Architecture:** Reutiliza la tabla `ticket_number_sequences` con una fila "global" (`year=0`, `last_seq=999`). El service ejecuta la misma idiom `INSERT … ON DUPLICATE KEY UPDATE last_seq = LAST_INSERT_ID(last_seq+1)` que ya existe, pero con `year=0` fijo y devolviendo el entero como string plano. Sin `ClockInterface`, sin formatter, sin año.

**Tech Stack:** CakePHP 5.x migrations, PHP 8.5, MySQL/MariaDB, PHPUnit (suite Unit, sin DB).

**Spec:** `docs/superpowers/specs/2026-05-15-ticket-numbering-refactor-design.md`

---

## File Structure

| Archivo | Responsabilidad | Acción |
|---|---|---|
| `config/Migrations/20260515120000_SwitchTicketNumberToGlobalCounter.php` | Truncar `ticket_number_sequences` y sembrar la fila global `(0, 999)` | Crear |
| `src/Service/NumberGenerationService.php` | Asignar atómicamente el siguiente entero global como string | Reescribir |
| `tests/TestCase/Service/NumberGenerationServiceTest.php` | Tests del antiguo formatter (obsoletos) | Eliminar |
| `src/Model/Table/TicketsTable.php` | `generateTicketNumber()` delegate | Sin cambios (sigue válido) |

---

## Task 1: Migración — switch a contador global

**Files:**
- Create: `config/Migrations/20260515120000_SwitchTicketNumberToGlobalCounter.php`

- [ ] **Step 1: Pre-check de seguridad — confirmar que no existe ningún ticket con número plano numérico**

Run:
```bash
bin/cake migrations status
```
Expected: la última migración aplicada es `20260514120000_AddTicketAssignedEmailTemplate` o posterior, y `20260509120000_AddTicketNumberSequencesTable` está en estado `up`.

Luego, contra la DB de la app:
```bash
docker compose exec db mysql -u root -p$DB_ROOT_PASSWORD <db_name> -e "SELECT COUNT(*) AS plain_numeric FROM tickets WHERE ticket_number REGEXP '^[0-9]+$';"
```
Expected: `plain_numeric = 0`. Si devuelve `> 0`, **PARAR** — hay tickets que colisionarían con el nuevo rango; documentar el caso y volver al spec.

Si no se tiene acceso CLI a MySQL, ejecutar el mismo SELECT desde el cliente que se use habitualmente (TablePlus, phpMyAdmin, etc.).

- [ ] **Step 2: Crear el archivo de migration**

Crear `config/Migrations/20260515120000_SwitchTicketNumberToGlobalCounter.php`:

```php
<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Cambia ticket_number a un contador global que arranca en 1000.
 *
 * Contexto: la migración 20260509120000_AddTicketNumberSequencesTable
 * estableció un contador atómico por año (TKT-YYYY-NNNNN). A partir de
 * 2026-05-15 los tickets nuevos se numeran como entero plano monótono
 * empezando en 1000 ("1000", "1001", ...). Los tickets ya emitidos se
 * conservan intactos: la columna es VARCHAR y ningún número existente
 * es un entero plano, así que ambos formatos coexisten sin colisión.
 *
 * Estrategia:
 *   - TRUNCATE: descarta las filas de contador por año (eran historial
 *     vivo solo mientras se usaba el formato anual; ya no aplican).
 *   - INSERT (year=0, last_seq=999): siembra el contador global. La
 *     columna se llama "year" por compatibilidad con el schema previo;
 *     el valor 0 representa "contador global post-2026-05-15".
 *
 * El primer INSERT ... ON DUPLICATE KEY UPDATE ejecutado por
 * NumberGenerationService sube last_seq a 1000 y devuelve "1000".
 *
 * Rollback (down): TRUNCATE. Solo es seguro ANTES de emitir tickets
 * nuevos. Después de emitir, revertir requiere intervención manual
 * (recalcular MAX numérico). Ver spec 2026-05-15.
 */
class SwitchTicketNumberToGlobalCounter extends BaseMigration
{
    /**
     * @return void
     */
    public function up(): void
    {
        $this->execute('TRUNCATE TABLE ticket_number_sequences');
        $this->execute(
            'INSERT INTO ticket_number_sequences (year, last_seq) VALUES (0, 999)',
        );
    }

    /**
     * @return void
     */
    public function down(): void
    {
        $this->execute('TRUNCATE TABLE ticket_number_sequences');
    }
}
```

- [ ] **Step 3: Ejecutar la migration**

Run:
```bash
bin/cake migrations migrate
```
Expected: salida lista a `SwitchTicketNumberToGlobalCounter` como `migrated`. No errores SQL.

- [ ] **Step 4: Verificar el estado de la tabla**

Run:
```bash
docker compose exec db mysql -u root -p$DB_ROOT_PASSWORD <db_name> -e "SELECT year, last_seq FROM ticket_number_sequences;"
```
Expected: exactamente una fila → `year=0, last_seq=999`.

- [ ] **Step 5: Commit**

```bash
git add config/Migrations/20260515120000_SwitchTicketNumberToGlobalCounter.php
git commit -m "feat(tickets): migration — switch numbering to global counter from 1000"
```

---

## Task 2: Reescribir `NumberGenerationService`

**Files:**
- Modify: `src/Service/NumberGenerationService.php` (reescritura completa, 103 → ~50 líneas)
- Delete: `tests/TestCase/Service/NumberGenerationServiceTest.php` (probaba el formatter eliminado)

- [ ] **Step 1: Eliminar el test obsoleto**

El test actual cubre exclusivamente `NumberGenerationService::formatNumber()`, método que desaparece en este refactor. No hay tests del path atómico (el docblock del propio test lo declara out-of-scope para unit tests).

Run:
```bash
git rm tests/TestCase/Service/NumberGenerationServiceTest.php
```
Expected: el archivo queda staged como `deleted`.

- [ ] **Step 2: Verificar que la suite de tests sigue verde sin ese archivo**

Run:
```bash
composer test
```
Expected: PASS en todos los tests restantes. Si algún otro test importaba símbolos del archivo borrado (no debería — el grep previo confirmó que solo `TicketsTable` usa el service, y el service mantiene `generate()`), corregir antes de continuar.

- [ ] **Step 3: Reescribir `NumberGenerationService`**

Sustituir todo el contenido de `src/Service/NumberGenerationService.php` por:

```php
<?php
declare(strict_types=1);

namespace App\Service;

use Cake\ORM\Locator\LocatorAwareTrait;
use RuntimeException;

/**
 * NumberGenerationService
 *
 * Genera identificadores secuenciales de ticket como entero plano monótono
 * empezando en 1000 ("1000", "1001", "1002", ...).
 *
 * Implementación atómica vía la tabla {@see ticket_number_sequences}, fila
 * global (year=0): una sola sentencia INSERT ... ON DUPLICATE KEY UPDATE
 * incrementa el contador y deja el nuevo valor disponible vía
 * LAST_INSERT_ID() en la misma sesión MySQL. Esto evita la race condition
 * que tenía la versión read-then-format previa.
 *
 * La fila global se siembra con last_seq=999 por la migration
 * 20260515120000_SwitchTicketNumberToGlobalCounter, por lo que la primera
 * llamada a generate() devuelve "1000".
 *
 * Los tickets emitidos antes de 2026-05-15 conservan su formato
 * TKT-YYYY-NNNNN; la columna ticket_number es VARCHAR y ambos formatos
 * coexisten sin colisión (ningún ticket previo es un entero plano).
 */
class NumberGenerationService
{
    use LocatorAwareTrait;

    private const SEQUENCE_TABLE = 'ticket_number_sequences';

    /**
     * Genera el siguiente número de ticket secuencial global.
     *
     * @return string Número generado (e.g., "1000")
     * @throws \RuntimeException si el contador no devuelve una secuencia válida
     */
    public function generate(): string
    {
        $connection = $this->fetchTable('Tickets')->getConnection();

        $connection->execute(
            'INSERT INTO ' . self::SEQUENCE_TABLE . ' (year, last_seq) '
            . 'VALUES (0, LAST_INSERT_ID(1)) '
            . 'ON DUPLICATE KEY UPDATE last_seq = LAST_INSERT_ID(last_seq + 1)',
        );

        $row = $connection->execute('SELECT LAST_INSERT_ID() AS seq')->fetch('assoc');
        $sequence = (int)($row['seq'] ?? 0);

        if ($sequence < 1) {
            throw new RuntimeException('Failed to allocate ticket number sequence');
        }

        return (string)$sequence;
    }
}
```

- [ ] **Step 4: Static analysis — PHPStan**

Run:
```bash
vendor/bin/phpstan analyse src/Service/NumberGenerationService.php
```
Expected: `[OK] No errors`. Si PHPStan reporta error por `LocatorAwareTrait` o tipos, ajustar (no debería: el patrón es idéntico al actual).

- [ ] **Step 5: Code style — cs-fix + cs-check**

Run:
```bash
composer cs-fix && composer cs-check
```
Expected: cs-fix sin cambios (o cambios mínimos sobre el archivo nuevo); cs-check PASS.

- [ ] **Step 6: Suite completa de tests**

Run:
```bash
composer test
```
Expected: PASS. La eliminación del test del formatter no rompe nada porque ningún otro test ni código de producción referencia `formatNumber`.

- [ ] **Step 7: Smoke manual contra la app**

Levantar el servidor y crear un ticket nuevo (web o forzando ingest):

```bash
bin/cake server
```

Crear un ticket vía la UI (o `POST /webhooks/gmail/import` con payload de prueba). Luego:

```bash
docker compose exec db mysql -u root -p$DB_ROOT_PASSWORD <db_name> -e "SELECT id, ticket_number FROM tickets ORDER BY id DESC LIMIT 3; SELECT year, last_seq FROM ticket_number_sequences;"
```
Expected:
- El ticket recién creado tiene `ticket_number = "1000"` (o `"1001"` etc. si ya se crearon varios durante el smoke).
- La fila `(0, N)` en `ticket_number_sequences` refleja el último valor emitido.
- Los tickets anteriores siguen con su `TKT-YYYY-NNNNN` sin modificar.

Si el primer ticket post-migración **no** salió `"1000"`, parar e investigar (probable causa: la migration no se ejecutó o la fila semilla no es `(0, 999)`).

- [ ] **Step 8: Commit**

```bash
git add src/Service/NumberGenerationService.php tests/TestCase/Service/NumberGenerationServiceTest.php
git commit -m "refactor(tickets): simplify NumberGenerationService to global counter

Drop ClockInterface, formatNumber helper, and year-based sequencing.
generate() now returns a plain integer string starting at 1000,
backed by the global row (year=0) seeded by migration 20260515120000.

Old tickets (TKT-YYYY-NNNNN) coexist untouched in the VARCHAR column.

Removes obsolete NumberGenerationServiceTest — it covered only the
deleted formatter; the atomic path remains validated by manual smoke
per the existing unit-only test policy."
```

---

## Task 3: Verificación final

**Files:** ninguno

- [ ] **Step 1: Re-correr la suite completa**

```bash
composer test
```
Expected: PASS.

- [ ] **Step 2: cs-check final**

```bash
composer cs-check
```
Expected: PASS.

- [ ] **Step 3: Verificar `migrations status`**

```bash
bin/cake migrations status
```
Expected: `SwitchTicketNumberToGlobalCounter` aparece como `up`.

- [ ] **Step 4: Verificar que ningún consumidor quedó roto**

```bash
git grep -n "formatNumber\|ClockInterface" src/Service/NumberGenerationService.php
```
Expected: sin resultados (ambos símbolos eliminados).

```bash
git grep -n "NumberGenerationService" src/ tests/
```
Expected: solo dos referencias — `src/Model/Table/TicketsTable.php` (delegate) y `src/Service/NumberGenerationService.php` (la clase). Ninguna referencia colgante.

- [ ] **Step 5: Tag opcional para marcar el cutover**

Si se quiere dejar marca visible en el historial:

```bash
git tag -a ticket-numbering-v2 -m "Cutover: ticket numbers now plain integers from 1000"
```
(Opcional, no afecta el merge.)

---

## Notas de rollback

- **Antes de emitir tickets `1000+`**: `bin/cake migrations rollback` revierte limpiamente; reaplicar el commit que reintroduce el service viejo si se desea volver al formato anterior.
- **Después de emitir tickets `1000+`**: NO usar `down()`. El TRUNCATE dejaría el contador en `0` y el siguiente ticket colisionaría. Recuperación manual: `INSERT (0, MAX_plain_numeric)` calculando el máximo con `SELECT MAX(CAST(ticket_number AS UNSIGNED)) FROM tickets WHERE ticket_number REGEXP '^[0-9]+$'`.
