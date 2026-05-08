<?php
declare(strict_types=1);

namespace App\Service\Exception;

use RuntimeException;

/**
 * Thrown when settings encryption/decryption fails (e.g., missing salt).
 *
 * Extends RuntimeException to remain compatible with existing
 * `catch (RuntimeException $e)` and `catch (Exception $e)` handlers.
 */
class SettingsEncryptionException extends RuntimeException
{
}
