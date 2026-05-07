<?php
declare(strict_types=1);

namespace App\Service\Exception;

use RuntimeException;

/**
 * Lanzada cuando el import se invoca sin OAuth de Gmail configurado.
 *
 * En el flujo HTTP se traduce a 503 (servicio no configurado).
 */
final class GmailNotConfiguredException extends RuntimeException
{
    /**
     * Construye la excepción para el caso de refresh_token ausente.
     */
    public static function missingRefreshToken(): self
    {
        return new self('Gmail OAuth no configurado: falta refresh_token. Autoriza Gmail en /admin/settings.');
    }
}
