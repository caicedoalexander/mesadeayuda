<?php
declare(strict_types=1);

use Cake\Utility\Security;
use Migrations\BaseMigration;

/**
 * Genera y persiste el shared secret usado por POST /webhooks/gmail/import.
 *
 * El token es 64 hex chars, cifrado con Security::encrypt() y prefijo
 * '{encrypted}' (mismo formato que SettingsEncryptionTrait::encryptSetting).
 */
class AddGmailWebhookToken extends BaseMigration
{
    private const SETTING_KEY = 'webhook_gmail_import_token';

    /**
     * @return void
     */
    public function up(): void
    {
        // No-op si ya existe (idempotente: facilita re-run en entornos compartidos)
        $existing = $this->fetchRow(
            "SELECT id FROM system_settings WHERE setting_key = '" . self::SETTING_KEY . "' LIMIT 1"
        );
        if ($existing) {
            return;
        }

        $token = bin2hex(random_bytes(32));
        $encrypted = '{encrypted}' . base64_encode(Security::encrypt($token, Security::getSalt()));

        $now = date('Y-m-d H:i:s');
        $this->table('system_settings')->insert([
            [
                'setting_key' => self::SETTING_KEY,
                'setting_value' => $encrypted,
                'setting_type' => 'string',
                'description' => 'Shared secret para POST /webhooks/gmail/import (n8n)',
                'created' => $now,
                'modified' => $now,
            ],
        ])->save();
    }

    /**
     * @return void
     */
    public function down(): void
    {
        $this->execute("DELETE FROM system_settings WHERE setting_key = '" . self::SETTING_KEY . "'");
    }
}
