<?php
declare(strict_types=1);

namespace App\Constants;

/**
 * Cache configuration constants.
 */
final class CacheConstants
{
    public const CACHE_SETTINGS   = 'system_settings';
    public const CACHE_CONFIG     = '_cake_core_';
    public const CACHE_RESILIENCE = 'resilience';

    public const DEFAULT_SYSTEM_TITLE = 'Mesa de Ayuda';

    /**
     * Cache slot that holds the previous webhook token during a rotation
     * grace window. Stored payload: ['token' => string, 'expires_at' => int].
     */
    public const WEBHOOK_GMAIL_PREVIOUS_TOKEN = 'webhook_gmail_previous_token';
    public const WEBHOOK_WHATSAPP_PREVIOUS_TOKEN = 'webhook_whatsapp_previous_token';
    public const WEBHOOK_TICKETS_TAGS_PREVIOUS_TOKEN = 'webhook_tickets_tags_previous_token';

    /**
     * Seconds the previous webhook token remains valid after rotation,
     * giving inflight n8n requests time to pick up the new credential.
     */
    public const WEBHOOK_TOKEN_OVERLAP_SECONDS = 300;
}
