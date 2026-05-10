<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\CacheConstants;
use App\Constants\SettingKeys;
use App\Model\Entity\EmailTemplate;
use App\Service\Traits\ConfigResolutionTrait;
use Cake\ORM\Locator\LocatorAwareTrait;

/**
 * EmailTemplateRenderer
 *
 * **Layer:** template loader + string renderer.
 *
 * Loads email templates from the `email_templates` table, caches them
 * in-memory and renders them by replacing {{variable}} placeholders.
 *
 * For domain-specific formatting (dates, status labels, attachments
 * HTML, WhatsApp message text), use {@see \App\Service\Renderer\NotificationRenderer}
 * instead.
 */
class EmailTemplateRenderer
{
    use LocatorAwareTrait;
    use ConfigResolutionTrait;

    /**
     * @var array<string, \App\Model\Entity\EmailTemplate|null> In-memory template cache
     */
    private array $templateCache = [];
    private ?array $systemConfig = null;

    /**
     * @param array<string, mixed>|null $systemConfig Optional config for ConfigResolutionTrait
     */
    public function __construct(?array $systemConfig = null)
    {
        $this->systemConfig = $systemConfig;
    }

    /**
     * Get a template by key (uses cache if previously fetched).
     *
     * @param string $templateKey Template key
     * @return \App\Model\Entity\EmailTemplate|null
     */
    public function getTemplate(string $templateKey): ?EmailTemplate
    {
        if (isset($this->templateCache[$templateKey])) {
            return $this->templateCache[$templateKey];
        }

        $templatesTable = $this->fetchTable('EmailTemplates');
        $template = $templatesTable->find()
            ->where([
                'template_key' => $templateKey,
                'is_active' => true,
            ])
            ->first();

        $this->templateCache[$templateKey] = $template;

        return $template;
    }

    /**
     * Render a template by replacing {{variable}} placeholders
     *
     * @param string $templateString Template string with {{variables}}
     * @param array<string, string> $variables Key => value pairs
     * @return string Rendered string
     */
    public function render(string $templateString, array $variables): string
    {
        foreach ($variables as $key => $value) {
            $templateString = str_replace('{{' . $key . '}}', (string)$value, $templateString);
        }

        return $templateString;
    }

    /**
     * Get system-wide variables common to all templates
     *
     * @return array<string, string>
     */
    public function getSystemVariables(): array
    {
        return [
            'system_title' => $this->resolveSettingValue(SettingKeys::SYSTEM_TITLE, CacheConstants::DEFAULT_SYSTEM_TITLE),
            'current_year' => date('Y'),
        ];
    }
}
