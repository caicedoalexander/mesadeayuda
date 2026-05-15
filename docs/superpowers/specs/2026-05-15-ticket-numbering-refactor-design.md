# Refactor: numeración de tickets a contador global desde 1000

**Fecha:** 2026-05-15
**Estado:** Aprobado, pendiente de plan de implementación
**Autor:** brainstorming con a.caicedo.dev

## Contexto

Hoy `NumberGenerationService` genera identificadores `TKT-YYYY-NNNNN` con secuencia que se reinicia cada año. El mecanismo es atómico vía `ticket_number_sequences` (commit 9ac752a) y depende de `Psr\Clock\ClockInterface` para obtener el año.

Se quiere simplificar:

- Los tickets nuevos deben numerarse como un entero plano monótono empezando en `1000`: `"1000"`, `"1001"`, …
- Los tickets ya emitidos (`TKT-YYYY-NNNNN`) se conservan intactos. **No hay migración de datos.**
- La columna `tickets.ticket_number` ya es VARCHAR; los dos formatos conviven sin colisión porque ningún ticket actual es un entero plano.

## Objetivos

1. Reemplazar la lógica de numeración por un contador global, sin prefijo, sin año.
2. Eliminar las dependencias que dejan de tener sentido (`ClockInterface`, helper `formatNumber`).
3. Mantener la garantía atómica actual frente a concurrencia.
4. No tocar el resto del flujo de creación (controllers, `TicketIngestionService`, listeners de dominio).

## No objetivos

- No migrar tickets antiguos.
- No renombrar la columna `year` de `ticket_number_sequences` ni droppear la tabla. Vive con la semántica de "fila global = `year=0`".
- No refactorizar `TicketsController::add` ni `TicketIngestionService::createFromEmail`. El refactor "más profundo" pedido por el usuario se concentra en el dominio de numeración, que es donde está la complejidad real (Clock, formatter, secuencia por año).
- No cambiar el tipo de columna `ticket_number`.

## Diseño

### Cambios

| Archivo | Acción |
|---|---|
| `config/Migrations/<timestamp>_SwitchTicketNumberToGlobalCounter.php` | **Nuevo.** `up()`: `TRUNCATE ticket_number_sequences;` y `INSERT (year=0, last_seq=999)`. `down()`: `TRUNCATE` (no restaura el contador anual; ver §Riesgos). |
| `src/Service/NumberGenerationService.php` | **Reescrito.** Sin `ClockInterface`, sin `formatNumber()`, sin cálculo de año. `generate()` ejecuta la idiom atómica con `year=0` fijo y devuelve `(string)$sequence`. |
| `tests/TestCase/Service/NumberGenerationServiceTest.php` | **Reemplazado.** Los tests actuales cubren un formatter que desaparece. Se sustituyen por test(s) de comportamiento de `generate()` (ver §Testing). |

### Archivos que **no** se tocan

- `src/Model/Table/TicketsTable.php`: `generateTicketNumber()` queda como delegate trivial — único punto de entrada al service desde el ORM, se conserva por estabilidad de API interna.
- `src/Service/TicketIngestionService.php`, `src/Service/TicketPipelineService.php`, `src/Controller/TicketsController.php`: no dependen del formato del número.
- `src/Controller/Admin/EmailTemplatesController.php`: la referencia a `TKT-` está en strings de plantilla, no en lógica de parseo.

### Data flow después del refactor

```
Creación de ticket (web o Gmail/n8n)
  └─► TicketsTable::generateTicketNumber()
        └─► NumberGenerationService::generate()
              └─► INSERT INTO ticket_number_sequences (year, last_seq)
                  VALUES (0, LAST_INSERT_ID(1))
                  ON DUPLICATE KEY UPDATE last_seq = LAST_INSERT_ID(last_seq + 1)
              └─► SELECT LAST_INSERT_ID()
              └─► return (string) $sequence    // "1000", "1001", ...
```

La fila `(0, 999)` se siembra en la migration; la primera ejecución de `ON DUPLICATE KEY UPDATE` la sube a `1000`. No existe rama especial para "primer ticket".

### Manejo de errores

Idéntico al actual: si `SELECT LAST_INSERT_ID()` devuelve `< 1`, `generate()` lanza `RuntimeException` con mensaje descriptivo. Como la fila se siembra con `last_seq=999`, el primer valor emitido siempre será `≥ 1000`; no hay rama "tabla vacía" que cubrir.

### Concurrencia

La misma idiom atómica MySQL (`INSERT … ON DUPLICATE KEY UPDATE` + `LAST_INSERT_ID(expr)`) que ya cubre el caso multi-request. No regresión respecto al estado actual.

## Testing

Tests obsoletos a borrar: los que validan `NumberGenerationService::formatNumber()` (el método desaparece).

Tests nuevos:

1. **Test de comportamiento contra conexión real** (PHPUnit, suite Unit, conexión de test):
   - Sembrar `ticket_number_sequences` con `(0, 999)`.
   - Llamar `generate()` dos veces.
   - Esperar `"1000"` y `"1001"` respectivamente.
   - Verificar que el valor devuelto es string y representa un entero positivo.

2. Si la infraestructura de fixtures no cubre `ticket_number_sequences` todavía, el test se marca `markTestSkipped()` con TODO claro y se documenta en el plan de implementación. **No bloqueante** para mergear el refactor — el comportamiento se valida manualmente con `bin/cake server` + creación de un ticket.

No se requieren cambios en tests de integración existentes; ninguno aserta sobre el formato exacto del número.

## Riesgos y mitigaciones

| Riesgo | Mitigación |
|---|---|
| **Rollback inseguro tras emitir tickets nuevos**: si se ejecuta `down()` después de haber creado tickets `1000+`, la tabla queda vacía y el siguiente ticket reseteará el contador, generando colisiones. | Documentar en el docblock de la migration que `down()` solo es seguro **antes** de emitir cualquier ticket nuevo. Para revertir después, requiere intervención manual: recalcular `MAX(CAST(ticket_number AS UNSIGNED))` sobre los tickets emitidos. |
| **Colisión teórica con un ticket viejo de valor `"1000"`**: no ocurre — todos los tickets actuales tienen prefijo `TKT-`, por lo que ningún número entero plano existe. | Validación previa al deploy: `SELECT COUNT(*) FROM tickets WHERE ticket_number REGEXP '^[0-9]+$'` debe devolver `0`. Incluir esta verificación en el plan de implementación. |
| **Semántica rara: columna `year` almacena `0`** | Comentario en la migration y en el service explicando que `year=0` representa "contador global post-2026-05-15". Aceptable porque el refactor más limpio (drop + tabla nueva) se descartó por costo. |

## Plan de rollback

1. **Antes de emitir tickets nuevos**: ejecutar `bin/cake migrations rollback` para volver a la versión `20260509120000_AddTicketNumberSequencesTable`. El estado previo se restaura porque la fila global se elimina y no hay tickets nuevos.
2. **Después de emitir tickets nuevos**: no usar `down()`. Si hay que volver al formato anterior, requiere desarrollo nuevo (no cubierto por este spec).

## Alternativas descartadas

- **B) Tabla nueva `ticket_counter` (single-row), drop de la vieja**: más limpia conceptualmente pero implica drop + recreate, rollback más delicado, y la idiom MySQL atómica es la misma. Descartada por coste/beneficio.
- **C) Columna `INT AUTO_INCREMENT` separada**: sobrediseño. Complicaría la coexistencia con los `TKT-…` viejos en la misma columna y cambiaría el tipo. Descartada.

## Aprobaciones

- Diseño revisado y aprobado en sesión de brainstorming 2026-05-15.
- Pendiente: revisión del usuario del documento escrito, luego transición a `writing-plans`.
