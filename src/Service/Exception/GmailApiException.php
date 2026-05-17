<?php
declare(strict_types=1);

namespace App\Service\Exception;

use RuntimeException;
use Throwable;

/**
 * Wraps Google\Service\Exception (and non-Google throwables that surface
 * inside GmailService) with a small string category used for retry
 * decisions, log enrichment, and counters in GmailImportResult.
 *
 * Extends RuntimeException so existing catch (RuntimeException) and
 * catch (Throwable) handlers in GmailImportService / EmailService
 * continue to work without changes.
 */
final class GmailApiException extends RuntimeException
{
    /**
     * @param string $category One of GmailErrorCategory constants
     * @param int $code HTTP status code from the original Google\Service\Exception (0 if unknown)
     * @param string $message Original error message
     * @param \Throwable|null $previous Underlying exception, preserved via getPrevious()
     */
    public function __construct(
        public readonly string $category,
        int $code,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Return the GmailErrorCategory string assigned at construction.
     */
    public function getCategory(): string
    {
        return $this->category;
    }
}
