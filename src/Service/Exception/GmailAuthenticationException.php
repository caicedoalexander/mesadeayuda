<?php
declare(strict_types=1);

namespace App\Service\Exception;

use RuntimeException;

/**
 * Thrown when Gmail OAuth authentication or token refresh fails.
 *
 * Extends RuntimeException to remain compatible with existing
 * `catch (RuntimeException $e)` and `catch (Exception $e)` handlers.
 */
class GmailAuthenticationException extends RuntimeException
{
}
