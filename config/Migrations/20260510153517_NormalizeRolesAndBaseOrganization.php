<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Normalize role values to {admin, asesor_tic, external} and ensure all staff
 * users belong to a base organization.
 *
 * Migration mappings:
 *   - 'agent'             -> 'asesor_tic'
 *   - 'servicio_cliente'  -> 'asesor_tic'  (collapsed; no real permission diff)
 *   - 'requester'         -> 'external'    (non-functional marker; no login)
 *
 * NOTE: This migration intentionally uses raw SQL UPDATEs which do NOT trigger
 * AuditBehavior. We do not want users_history to be flooded with N rows for a
 * structural rename. The down() method does not reconstruct 'servicio_cliente'
 * (data loss accepted and documented).
 */
final class NormalizeRolesAndBaseOrganization extends BaseMigration
{
    public bool $autoId = false;

    public function up(): void
    {
        // 1. Ensure base organization exists. organizations.name has no unique
        //    index so we use check-then-insert.
        $existing = $this->fetchRow(
            "SELECT id FROM organizations WHERE name = 'Organización Base' LIMIT 1"
        );
        if ($existing === false) {
            $this->execute(
                "INSERT INTO organizations (name, domain, created, modified) "
                . "VALUES ('Organización Base', NULL, NOW(), NOW())"
            );
            $row = $this->fetchRow(
                "SELECT id FROM organizations WHERE name = 'Organización Base' LIMIT 1"
            );
            $baseId = (int)$row['id'];
        } else {
            $baseId = (int)$existing['id'];
        }

        // 2. Collapse agent + servicio_cliente -> asesor_tic.
        $this->execute(
            "UPDATE users SET role = 'asesor_tic' "
            . "WHERE role IN ('agent', 'servicio_cliente')"
        );

        // 3. Rename requester -> external (non-functional marker).
        $this->execute("UPDATE users SET role = 'external' WHERE role = 'requester'");

        // 4. Backfill organization_id for staff users only.
        $this->execute(
            "UPDATE users SET organization_id = {$baseId} "
            . "WHERE organization_id IS NULL AND role IN ('admin', 'asesor_tic')"
        );

        // 5. Change the column default so newly auto-created users (Gmail import)
        //    that don't specify a role land as 'external'.
        $this->execute(
            "ALTER TABLE users MODIFY role VARCHAR(255) NOT NULL DEFAULT 'external'"
        );
    }

    public function down(): void
    {
        // Restore default first.
        $this->execute(
            "ALTER TABLE users MODIFY role VARCHAR(255) NOT NULL DEFAULT 'requester'"
        );

        // Best-effort revert. 'servicio_cliente' is NOT reconstructed.
        $this->execute("UPDATE users SET role = 'agent' WHERE role = 'asesor_tic'");
        $this->execute("UPDATE users SET role = 'requester' WHERE role = 'external'");

        // organization_id is left as-is on rollback (was nullable from start;
        // unsetting it would lose information added intentionally).
    }
}
