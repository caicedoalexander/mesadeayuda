<?php
declare(strict_types=1);

namespace App\Notification\Email;

use Cake\Core\Configure;

/**
 * Static branding constants used by email templates' header and footer.
 *
 * Intentionally a code-side configuration: changing these requires a deploy,
 * which is fine for a single-organization installation.
 */
final class EmailBrand
{
    public const ORG_NAME = 'Operadora Cafetera S.A.S.';
    public const ORG_TAG_LINE = 'Mesa de Ayuda';
    public const ORG_ADDRESS = 'Buenaventura, Valle del Cauca';
    public const ORG_NIT = '800247852';
    public const SUPPORT_EMAIL = 'mesadeayuda@operadoracafetera.com';
    public const HEADER_TITLE = 'Mesa de Ayuda';
    public const HEADER_SUBTITLE = 'Soporte Interno';

    /**
     * Absolute URL to the logo asset. Reads `App.fullBaseUrl` from Configure
     * so email clients can load it regardless of the recipient's network.
     */
    public static function logoUrl(): string
    {
        $base = rtrim((string)Configure::read('App.fullBaseUrl', ''), '/');

        return $base . '/img/logo-mesa-ayuda.svg';
    }
}
