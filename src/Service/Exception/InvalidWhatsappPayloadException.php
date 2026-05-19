<?php
declare(strict_types=1);

namespace App\Service\Exception;

use RuntimeException;

/**
 * Thrown when the body of POST /webhooks/whatsapp/import fails validation.
 * Caller (WebhooksController) maps this to HTTP 400.
 */
final class InvalidWhatsappPayloadException extends RuntimeException
{
}
