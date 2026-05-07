<?php
declare(strict_types=1);

use Cake\Utility\Security;
use Migrations\BaseMigration;

/**
 * Mueve el contenido de config/google/client_secret.json (referenciado por
 * system_settings.gmail_client_secret_path) a system_settings.gmail_client_secret_json
 * cifrado, y borra el archivo del disco.
 *
 * Idempotente: si la fila path ya no existe, no hace nada.
 */
class MigrateGmailClientSecretToDatabase extends BaseMigration
{
    private const OLD_KEY = 'gmail_client_secret_path';
    private const NEW_KEY = 'gmail_client_secret_json';

    /**
     * @return void
     */
    public function up(): void
    {
        $row = $this->fetchRow(
            "SELECT setting_value FROM system_settings WHERE setting_key = '" . self::OLD_KEY . "' LIMIT 1",
        );

        if ($row && !empty($row['setting_value'])) {
            $path = (string)$row['setting_value'];

            if (file_exists($path) && is_readable($path)) {
                $json = file_get_contents($path);

                if ($json !== false && json_decode($json, true) !== null) {
                    $existing = $this->fetchRow(
                        "SELECT id FROM system_settings WHERE setting_key = '" . self::NEW_KEY . "' LIMIT 1",
                    );

                    if (!$existing) {
                        $encrypted = '{encrypted}' . base64_encode(Security::encrypt($json, Security::getSalt()));
                        $now = date('Y-m-d H:i:s');

                        $this->table('system_settings')->insert([[
                            'setting_key' => self::NEW_KEY,
                            'setting_value' => $encrypted,
                            'setting_type' => 'string',
                            'description' => 'Gmail OAuth client_secret (JSON cifrado)',
                            'created' => $now,
                            'modified' => $now,
                        ]])->save();
                    }

                    if (is_writable($path)) {
                        unlink($path);
                    }
                }
            }
        }

        $this->execute("DELETE FROM system_settings WHERE setting_key = '" . self::OLD_KEY . "'");
    }

    /**
     * @return void
     */
    public function down(): void
    {
        // Reverse no recrea archivos en disco. Para rollback, re-pegar el JSON
        // manualmente en /admin/settings.
        $this->execute("DELETE FROM system_settings WHERE setting_key = '" . self::NEW_KEY . "'");
    }
}
