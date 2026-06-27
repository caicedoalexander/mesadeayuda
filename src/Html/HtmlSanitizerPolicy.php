<?php
declare(strict_types=1);

namespace App\Html;

use HTMLPurifier;
use HTMLPurifier_Config;

/**
 * Single source of truth for the project's HTMLPurifier policy.
 *
 * Both the Service layer (HtmlSanitizerTrait, at persistence) and the View
 * layer (SanitizeHelper, at render) build their purifier from here so the
 * allowed HTML/CSS can never diverge between input and output sanitization.
 *
 * The CSS allowlist is deliberately limited to basic typography so the body
 * of ingested email keeps its original look (font, size, colour, alignment,
 * spacing) without opening a CSS/XSS surface. Dangerous properties and values
 * (position, expression(), url(javascript:), ...) are dropped by HTMLPurifier.
 */
final class HtmlSanitizerPolicy
{
    /**
     * Allowed elements. The `style` attribute is enabled only on the text
     * elements that legitimately carry typography in email bodies.
     *
     * @var list<string>
     */
    private const HTML_ALLOWED = [
        'p[style]', 'br', 'b', 'i', 'u', 'strong', 'em', 'a[href|style]',
        'ul', 'ol', 'li[style]', 'blockquote[style]',
        'h1[style]', 'h2[style]', 'h3[style]', 'h4[style]', 'h5[style]', 'h6[style]',
        'img[src|alt|width|height]',
        'table[style]', 'thead', 'tbody', 'tr[style]', 'td[style]', 'th[style]',
        'span[style]', 'div[style]', 'pre', 'code', 'hr',
    ];

    /**
     * Basic-typography CSS allowlist. Everything else (layout, positioning,
     * sizing, backgrounds, borders) is stripped by HTMLPurifier.
     *
     * @var list<string>
     */
    private const CSS_ALLOWED_PROPERTIES = [
        'font-family', 'font-size', 'font-weight', 'font-style', 'text-decoration',
        'color', 'text-align', 'line-height',
        'margin', 'margin-top', 'margin-bottom', 'margin-left', 'margin-right', 'padding',
    ];

    /**
     * Build a purifier configured with the project-wide policy.
     */
    public static function createPurifier(): HTMLPurifier
    {
        $config = HTMLPurifier_Config::createDefault();
        $config->set('HTML.Allowed', implode(',', self::HTML_ALLOWED));
        $config->set('CSS.AllowedProperties', implode(',', self::CSS_ALLOWED_PROPERTIES));
        $config->set('HTML.TargetBlank', true);
        $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true]);
        $config->set('Attr.AllowedFrameTargets', ['_blank']);
        $config->set('Cache.DefinitionImpl', null);

        return new HTMLPurifier($config);
    }
}
