<?php
declare(strict_types=1);

namespace App\Notification\Email\Component;

/**
 * Colored circle with white initials.
 * Generic — receives initials + color directly; does not know about User.
 */
final class Avatar
{
    /**
     * Render the avatar markup.
     *
     * @param string $initials Already-escaped or plain initials text
     * @param string $color Background hex color
     * @param int $size Pixel size for width/height
     * @return string HTML markup
     */
    public static function render(string $initials, string $color, int $size = 32): string
    {
        $fontSize = (int)round($size * 0.4);
        $style = 'display:inline-flex;align-items:center;justify-content:center;'
            . 'width:' . $size . 'px;height:' . $size . 'px;border-radius:50%;'
            . 'background:' . $color . ';color:#fff;font-weight:600;'
            . 'font-size:' . $fontSize . 'px;letter-spacing:-0.3px;flex-shrink:0;';

        return '<span style="' . $style . '">'
            . htmlspecialchars($initials, ENT_QUOTES | ENT_HTML5, 'UTF-8')
            . '</span>';
    }

    /**
     * Extract up to 2 uppercase initials from a person's name.
     */
    public static function initialsFromName(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return '';
        }

        $parts = preg_split('/\s+/', $name) ?: [];
        $initials = '';
        foreach (array_slice($parts, 0, 2) as $part) {
            $initials .= mb_substr($part, 0, 1);
        }

        return mb_strtoupper($initials);
    }
}
