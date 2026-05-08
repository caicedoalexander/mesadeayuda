<?php
declare(strict_types=1);

namespace App\Service\Traits;

use HTMLPurifier;
use HTMLPurifier_Config;

/**
 * Provides sanitizeHtml() with a project-wide HTML allowlist for ticket bodies.
 * Used by services that persist user-submitted HTML (comments, ingested email).
 */
trait HtmlSanitizerTrait
{
    /**
     * Sanitize HTML content using the project-wide allowlist.
     *
     * @param string $html Raw HTML content
     * @return string Sanitized HTML
     */
    private function sanitizeHtml(string $html): string
    {
        $config = HTMLPurifier_Config::createDefault();
        $config->set('HTML.Allowed', 'p,br,b,i,u,strong,em,a[href],ul,ol,li,blockquote,h1,h2,h3,h4,h5,h6,img[src|alt|width|height],table,thead,tbody,tr,td,th,span,div,pre,code,hr');
        $config->set('HTML.TargetBlank', true);
        $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true]);
        $config->set('Attr.AllowedFrameTargets', ['_blank']);
        $config->set('Cache.DefinitionImpl', null);

        $purifier = new HTMLPurifier($config);

        return $purifier->purify($html);
    }
}
