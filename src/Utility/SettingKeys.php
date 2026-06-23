<?php
declare(strict_types=1);

namespace App\Utility;

/**
 * Centralized setting key constants.
 *
 * Eliminates hardcoded setting key strings duplicated across services,
 * controllers, and commands.
 */
final class SettingKeys
{
    // ── System ──────────────────────────────────────────────────────────
    public const SYSTEM_TITLE = 'system_title';

    // ── Gmail ───────────────────────────────────────────────────────────
    public const GMAIL_REFRESH_TOKEN = 'gmail_refresh_token';
    public const GMAIL_CLIENT_SECRET_JSON = 'gmail_client_secret_json';
    public const GMAIL_CHECK_INTERVAL = 'gmail_check_interval';
    public const GMAIL_USER_EMAIL = 'gmail_user_email';

    // ── WhatsApp ────────────────────────────────────────────────────────
    public const WHATSAPP_ENABLED = 'whatsapp_enabled';
    public const WHATSAPP_API_URL = 'whatsapp_api_url';
    public const WHATSAPP_API_KEY = 'whatsapp_api_key';
    public const WHATSAPP_INSTANCE_NAME = 'whatsapp_instance_name';
    public const WHATSAPP_TICKETS_NUMBER = 'whatsapp_tickets_number';

    // ── n8n ─────────────────────────────────────────────────────────────
    public const N8N_ENABLED = 'n8n_enabled';
    public const N8N_WEBHOOK_URL = 'n8n_webhook_url';
    public const N8N_API_KEY = 'n8n_api_key';
    public const N8N_SEND_TAGS_LIST = 'n8n_send_tags_list';
    public const N8N_TIMEOUT = 'n8n_timeout';

    // ── Webhooks ────────────────────────────────────────────────────────
    public const WEBHOOK_GMAIL_IMPORT_TOKEN = 'webhook_gmail_import_token';
}
