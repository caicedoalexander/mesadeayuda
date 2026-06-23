<?php
declare(strict_types=1);

namespace App\Service;

use App\Model\Entity\EmailTemplate;
use App\Service\Traits\ConfigResolutionTrait;
use App\Utility\SettingKeys;
use App\Utility\ValidationConstants;
use Cake\Log\Log;
use Cake\ORM\Locator\LocatorAwareTrait;
use Exception;

/**
 * EmailTemplateRenderer
 *
 * Loads email templates from database, caches them in-memory,
 * and renders them by replacing {{variable}} placeholders.
 */
class EmailTemplateRenderer
{
    use LocatorAwareTrait;
    use ConfigResolutionTrait;

    /**
     * @var array<string, \App\Model\Entity\EmailTemplate|null> In-memory template cache
     */
    private array $templateCache = [];
    private bool $preloaded = false;
    private ?array $systemConfig = null;

    /**
     * @param array<string, mixed>|null $systemConfig Optional config for ConfigResolutionTrait
     */
    public function __construct(?array $systemConfig = null)
    {
        $this->systemConfig = $systemConfig;
    }

    /**
     * Preload all active templates into cache (avoids N+1 queries)
     *
     * @return void
     */
    public function preloadTemplates(): void
    {
        if ($this->preloaded) {
            return;
        }

        try {
            $templatesTable = $this->fetchTable('EmailTemplates');
            $templates = $templatesTable->find()
                ->where(['is_active' => true])
                ->all();

            foreach ($templates as $template) {
                $this->templateCache[$template->template_key] = $template;
            }

            $this->preloaded = true;
        } catch (Exception $e) {
            Log::error('EmailTemplateRenderer: Failed to preload templates: ' . $e->getMessage());
        }
    }

    /**
     * Get a template by key (uses cache if preloaded)
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
     * Load template and render subject + body in one call
     *
     * @param string $templateKey Template key
     * @param array<string, string> $variables Template variables
     * @return array{subject: string, body: string}|null Rendered content or null if template not found
     */
    public function renderTemplate(string $templateKey, array $variables): ?array
    {
        $template = $this->getTemplate($templateKey);
        if (!$template) {
            Log::error("EmailTemplateRenderer: Template not found: {$templateKey}");

            return null;
        }

        return [
            'subject' => $this->render($template->subject, $variables),
            'body' => $this->render($template->body_html, $variables),
        ];
    }

    /**
     * Get system-wide variables common to all templates
     *
     * @return array<string, string>
     */
    public function getSystemVariables(): array
    {
        return [
            'system_title' => $this->resolveSettingValue(SettingKeys::SYSTEM_TITLE, ValidationConstants::DEFAULT_SYSTEM_TITLE),
            'current_year' => date('Y'),
        ];
    }

    /**
     * Clear the in-memory template cache
     *
     * @return void
     */
    public function clearCache(): void
    {
        $this->templateCache = [];
        $this->preloaded = false;
    }
}
