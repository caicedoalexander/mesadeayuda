<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class RemoveUrgentePriority extends BaseMigration
{
    /**
     * Elimina la prioridad `urgente` del sistema. Las prioridades válidas
     * quedan reducidas a `baja`, `media`, `alta`.
     *
     * `tickets.priority` es VARCHAR (sin constraint ENUM), por lo que esta
     * migración solo necesita un UPDATE defensivo que reasigne cualquier
     * ticket con `priority='urgente'` a `alta` (el siguiente nivel de
     * severidad disponible). En el momento del rollout no había filas
     * `urgente` en BD; el UPDATE es idempotente y seguro re-ejecutado.
     */
    public function up(): void
    {
        $this->execute(
            "UPDATE tickets SET priority = 'alta' WHERE priority = 'urgente'",
        );
    }

    public function down(): void
    {
        // No es posible reintroducir la prioridad `urgente` en filas migradas
        // sin perder información: el UPDATE colapsó `urgente` → `alta` y no
        // hay marca que permita distinguir las filas originalmente urgentes
        // de las que ya estaban en `alta`. Restaurar desde backup si se
        // necesita volver al estado previo.
        throw new \RuntimeException(
            'RemoveUrgentePriority is not reversible. Restore from backup.',
        );
    }
}
