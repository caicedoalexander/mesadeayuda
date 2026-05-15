<?php
declare(strict_types=1);

namespace App\Notification\Email;

/**
 * Color palette + tag for a notification type.
 * Pure data, immutable; build via the named factories.
 */
final readonly class EmailTheme
{
    public function __construct(
        public string $accent,
        public string $accentSoft,
        public string $accentInk,
        public string $tag,
    ) {
    }

    public static function creacion(): self
    {
        return new self('#CD6A15', '#FCEFE0', '#6b3306', 'Nuevo ticket');
    }

    public static function estado(): self
    {
        return new self('#0066cc', '#E3EFFC', '#0a3a78', 'Cambio de estado');
    }

    public static function comentario(): self
    {
        return new self('#00A85E', '#E6F7EE', '#00432a', 'Nuevo comentario');
    }

    public static function actualizacion(): self
    {
        return new self('#7c3aed', '#F0EBFE', '#3c1d8a', 'Actualización');
    }
}
