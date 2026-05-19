<?php
declare(strict_types=1);

namespace App\Constants;

/**
 * Centralized setting key constants.
 *
 * Eliminates hardcoded setting key strings duplicated across services,
 * controllers, and commands.
 */
final class SettingKeys
{
    public const SYSTEM_TITLE = 'system_title';

    public const GMAIL_REFRESH_TOKEN = 'gmail_refresh_token';
    public const GMAIL_CLIENT_SECRET_JSON = 'gmail_client_secret_json';
    public const GMAIL_CHECK_INTERVAL = 'gmail_check_interval';
    public const GMAIL_USER_EMAIL = 'gmail_user_email';
    public const GMAIL_LAST_HISTORY_ID = 'gmail_last_history_id';

    public const WHATSAPP_ENABLED = 'whatsapp_enabled';
    public const WHATSAPP_API_URL = 'whatsapp_api_url';
    public const WHATSAPP_API_KEY = 'whatsapp_api_key';
    public const WHATSAPP_INSTANCE_NAME = 'whatsapp_instance_name';
    public const WHATSAPP_TICKETS_NUMBER = 'whatsapp_tickets_number';
    public const WHATSAPP_BOT_EMAIL = 'whatsapp_bot_email';

    public const N8N_ENABLED = 'n8n_enabled';
    public const N8N_WEBHOOK_URL = 'n8n_webhook_url';
    public const N8N_API_KEY = 'n8n_api_key';
    public const N8N_SEND_TAGS_LIST = 'n8n_send_tags_list';
    public const N8N_TIMEOUT = 'n8n_timeout';

    public const WEBHOOK_GMAIL_IMPORT_TOKEN = 'webhook_gmail_import_token';
    public const WEBHOOK_WHATSAPP_IMPORT_TOKEN = 'webhook_whatsapp_import_token';
    public const WEBHOOK_TICKETS_TAGS_TOKEN = 'webhook_tickets_tags_token';

    /**
     * Setting keys exposed in the admin general-settings form.
     *
     * Whitelist used by Admin\SettingsController::index to reject any
     * extra POSTed keys (would otherwise let an attacker write arbitrary
     * settings, including credentials and tokens managed by dedicated flows).
     *
     * Excludes encrypted/credential keys that have their own admin actions:
     *  - GMAIL_REFRESH_TOKEN, GMAIL_CLIENT_SECRET_JSON (gmailAuth/gmailClientSecret)
     *  - WEBHOOK_GMAIL_IMPORT_TOKEN (regenerateWebhookToken)
     *  - WHATSAPP_BOT_EMAIL (system-level identifier, not user-editable)
     */
    public const USER_EDITABLE_KEYS = [
        self::SYSTEM_TITLE,
        self::GMAIL_CHECK_INTERVAL,
        self::WHATSAPP_ENABLED,
        self::WHATSAPP_API_URL,
        self::WHATSAPP_API_KEY,
        self::WHATSAPP_INSTANCE_NAME,
        self::WHATSAPP_TICKETS_NUMBER,
        self::N8N_ENABLED,
        self::N8N_WEBHOOK_URL,
        self::N8N_API_KEY,
        self::N8N_SEND_TAGS_LIST,
        self::N8N_TIMEOUT,
    ];
}
