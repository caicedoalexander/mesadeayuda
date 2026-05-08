<?php
declare(strict_types=1);

namespace App\View\Helper;

use Cake\View\Helper;
use HTMLPurifier;
use HTMLPurifier_Config;

/**
 * Sanitize Helper
 *
 * Provides output-level HTML sanitization for user-generated content.
 * Defense in depth: input is sanitized by HTMLPurifier in services,
 * this helper provides a second layer at the template/output level.
 */
class SanitizeHelper extends Helper
{
    private ?HTMLPurifier $purifier = null;

    /**
     * Sanitize HTML content for safe rendering
     *
     * @param string|null $html Raw HTML content
     * @return string Sanitized HTML safe for rendering
     */
    public function html(?string $html): string
    {
        if ($html === null || $html === '') {
            return '';
        }

        if ($this->purifier === null) {
            $config = HTMLPurifier_Config::createDefault();
            $config->set('HTML.Allowed', 'p,br,b,i,u,strong,em,a[href],ul,ol,li,blockquote,h1,h2,h3,h4,h5,h6,img[src|alt|width|height],table,thead,tbody,tr,td,th,span,div,pre,code,hr');
            $config->set('HTML.TargetBlank', true);
            $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true]);
            $config->set('Attr.AllowedFrameTargets', ['_blank']);
            $config->set('Cache.DefinitionImpl', null);
            $this->purifier = new HTMLPurifier($config);
        }

        return $this->purifier->purify($html);
    }
}
