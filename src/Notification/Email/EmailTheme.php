<?php
declare(strict_types=1);

namespace App\Notification\Email;

/**
 * Color palette + tag for a notification type.
 * Pure data, immutable; build via the named factories.
 */
final readonly class EmailTheme
{
    /**
     * @param string $accent Main accent color (hex)
     * @param string $accentSoft Soft tint of the accent (hex)
     * @param string $accentInk Dark text color on accent backgrounds (hex)
     * @param string $tag Short human label for the theme
     */
    public function __construct(
        public string $accent,
        public string $accentSoft,
        public string $accentInk,
        public string $tag,
    ) {
    }

    /**
     * Orange palette — ticket creation notifications.
     */
    public static function creacion(): self
    {
        return new self('#CD6A15', '#FCEFE0', '#6b3306', 'Nuevo ticket');
    }

    /**
     * Blue palette — status change notifications.
     */
    public static function estado(): self
    {
        return new self('#0066cc', '#E3EFFC', '#0a3a78', 'Cambio de estado');
    }

    /**
     * Green palette — new comment notifications.
     */
    public static function comentario(): self
    {
        return new self('#00A85E', '#E6F7EE', '#00432a', 'Nuevo comentario');
    }

    /**
     * Purple palette — combo update notifications.
     */
    public static function actualizacion(): self
    {
        return new self('#7c3aed', '#F0EBFE', '#3c1d8a', 'Actualización');
    }
}
