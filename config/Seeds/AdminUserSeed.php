<?php
declare(strict_types=1);

use App\Constants\RoleConstants;
use Authentication\PasswordHasher\DefaultPasswordHasher;
use Migrations\BaseSeed;

/**
 * Creates a development admin user so the app is usable right after a fresh
 * `migrations migrate` (the users table starts empty).
 *
 * DEV ONLY: credentials are hardcoded for local convenience. Do NOT rely on
 * these in any non-development environment — create real admins via /admin.
 *
 * Idempotent: skips if the email already exists, so it is safe to re-run.
 */
class AdminUserSeed extends BaseSeed
{
    private const ADMIN_EMAIL = 'admin@mesadeayuda.local';
    private const ADMIN_PASSWORD = 'Admin1234';

    /**
     * @return void
     */
    public function run(): void
    {
        $existing = $this->fetchRow(
            sprintf("SELECT id FROM users WHERE email = '%s'", self::ADMIN_EMAIL),
        );

        if ($existing !== false) {
            echo "AdminUserSeed: '" . self::ADMIN_EMAIL . "' already exists, skipping.\n";

            return;
        }

        $now = date('Y-m-d H:i:s');

        $this->insert('users', [
            [
                'email' => self::ADMIN_EMAIL,
                'password' => (new DefaultPasswordHasher())->hash(self::ADMIN_PASSWORD),
                'first_name' => 'Admin',
                'last_name' => 'Sistema',
                'role' => RoleConstants::ROLE_ADMIN,
                'is_active' => 1,
                'created' => $now,
                'modified' => $now,
            ],
        ]);

        echo "AdminUserSeed: created '" . self::ADMIN_EMAIL . "' (password: " . self::ADMIN_PASSWORD . ").\n";
    }
}
