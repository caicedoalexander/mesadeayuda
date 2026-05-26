<?php
declare(strict_types=1);

namespace App\Notification\Email;

use Cake\Core\Configure;

/**
 * Static branding constants for the email footer.
 *
 * Intentionally a code-side configuration: changing these requires a deploy,
 * which is fine for a single-organization installation.
 */
final class EmailBrand
{
    public const ORG_NAME = 'Compañía Operadora Portuaria Cafetera S.A.';
    public const TEAM_NAME = 'Mesa de Ayuda';

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
