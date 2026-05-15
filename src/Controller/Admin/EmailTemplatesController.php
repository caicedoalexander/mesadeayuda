<?php
declare(strict_types=1);

namespace App\Controller\Admin;

use App\Constants\RoleConstants;
use App\Notification\Email\Admin\TemplateDescriptor;
use App\Notification\Email\EmailTheme;
use App\Notification\Email\PreviewFixture;
use App\Notification\Email\TemplateRegistry;
use Cake\Event\EventInterface;
use Cake\Http\Exception\NotFoundException;
use InvalidArgumentException;

/**
 * EmailTemplates Controller (Admin) — read-only previewer.
 *
 * Templates live in code (App\Notification\Email\*). This controller lists
 * registered templates and renders an HTML preview against a static fixture.
 * Editing is intentionally not supported; changes require a deploy.
 */
class EmailTemplatesController extends AppController
{
    public function beforeFilter(EventInterface $event)
    {
        parent::beforeFilter($event);

        return $this->redirectByRole([RoleConstants::ROLE_ADMIN], 'admin');
    }

    /**
     * List all registered templates.
     */
    public function index(): void
    {
        $registry = new TemplateRegistry();
        $descriptors = [];
        foreach ($registry->all() as $template) {
            $descriptors[] = $this->descriptorFor($template->key());
        }

        $this->set(compact('descriptors'));
    }

    /**
     * Render a preview of one template using fixture data.
     */
    public function preview(?string $key = null): void
    {
        $registry = new TemplateRegistry();

        try {
            $template = $registry->get((string)$key);
        } catch (InvalidArgumentException) {
            throw new NotFoundException();
        }

        $ctx = PreviewFixture::context(PreviewFixture::variantForKey($template->key()));
        $rendered = $template->render($ctx);

        $this->viewBuilder()->setLayout('ajax');
        $this->set([
            'subject' => $rendered->subject,
            'bodyHtml' => $rendered->bodyHtml,
            'descriptor' => $this->descriptorFor($template->key()),
        ]);
    }

    private function descriptorFor(string $key): TemplateDescriptor
    {
        return match ($key) {
            'ticket_created' => new TemplateDescriptor(
                key: $key,
                accentColor: EmailTheme::creacion()->accent,
                accentSoftColor: EmailTheme::creacion()->accentSoft,
                tag: EmailTheme::creacion()->tag,
                description: 'Notifica al solicitante que su ticket fue creado correctamente.',
            ),
            'ticket_status_changed' => new TemplateDescriptor(
                key: $key,
                accentColor: EmailTheme::estado()->accent,
                accentSoftColor: EmailTheme::estado()->accentSoft,
                tag: EmailTheme::estado()->tag,
                description: 'Notifica al solicitante que el estado de su ticket cambió.',
            ),
            'ticket_comment_added' => new TemplateDescriptor(
                key: $key,
                accentColor: EmailTheme::comentario()->accent,
                accentSoftColor: EmailTheme::comentario()->accentSoft,
                tag: EmailTheme::comentario()->tag,
                description: 'Notifica al solicitante que un agente respondió a su ticket.',
            ),
            'ticket_updated' => new TemplateDescriptor(
                key: $key,
                accentColor: EmailTheme::actualizacion()->accent,
                accentSoftColor: EmailTheme::actualizacion()->accentSoft,
                tag: EmailTheme::actualizacion()->tag,
                description: 'Combina cambio de estado y comentario en una sola notificación.',
            ),
            default => new TemplateDescriptor($key, '#6B7280', '#F3F4F6', '', ''),
        };
    }
}
