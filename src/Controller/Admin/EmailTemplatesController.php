<?php
declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\AppController;

/**
 * EmailTemplates Controller (Admin)
 *
 * Manages email notification templates.
 * Extracted from SettingsController for SRP compliance.
 */
class EmailTemplatesController extends AppController
{
    public function beforeFilter(\Cake\Event\EventInterface $event)
    {
        parent::beforeFilter($event);

        $user = $this->Authentication->getIdentity();
        if (!$user || $user->get('role') !== 'admin') {
            $this->Flash->error('Solo los administradores pueden acceder a esta sección.');
            return $this->redirect(['controller' => 'Tickets', 'action' => 'index', 'prefix' => false]);
        }
    }

    /**
     * List all email templates
     */
    public function index()
    {
        $templatesTable = $this->fetchTable('EmailTemplates');

        if ($this->request->is('post')) {
            $template = $templatesTable->newEntity($this->request->getData());

            if ($templatesTable->save($template)) {
                $this->Flash->success('Plantilla creada exitosamente.');
                return $this->redirect(['action' => 'index']);
            } else {
                $this->Flash->error('Error al crear la plantilla.');
            }
        }

        $templates = $templatesTable->find()->all();
        $this->set(compact('templates'));
    }

    /**
     * Edit email template
     */
    public function edit($id = null)
    {
        $templatesTable = $this->fetchTable('EmailTemplates');
        $template = $templatesTable->get($id);

        if ($this->request->is(['patch', 'post', 'put'])) {
            $template = $templatesTable->patchEntity($template, $this->request->getData());

            if ($templatesTable->save($template)) {
                $this->Flash->success('Plantilla actualizada exitosamente.');
                return $this->redirect(['action' => 'index']);
            } else {
                $this->Flash->error('Error al actualizar la plantilla.');
            }
        }

        $this->set(compact('template'));
    }

    /**
     * Preview email template
     */
    public function preview($id = null)
    {
        $templatesTable = $this->fetchTable('EmailTemplates');
        $template = $templatesTable->get($id);

        $sampleData = [
            'ticket_number' => 'TKT-2025-00001',
            'subject' => 'Ejemplo de asunto del ticket',
            'requester_name' => 'Juan Pérez',
            'assignee_name' => 'María González',
            'created_date' => date('d/m/Y H:i'),
            'updated_date' => date('d/m/Y H:i'),
            'ticket_url' => 'http://localhost:8080/tickets/view/1',
            'system_title' => 'Sistema de Soporte',
        ];

        $previewBody = $template->body_html;
        foreach ($sampleData as $key => $value) {
            $previewBody = str_replace('{{' . $key . '}}', $value, $previewBody);
        }

        $this->viewBuilder()->setLayout(null);
        $this->set(compact('previewBody', 'template'));
    }
}
