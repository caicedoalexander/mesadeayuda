<?php
declare(strict_types=1);

namespace App\Controller\Admin;

use App\Constants\RoleConstants;
use Cake\Event\EventInterface;

/**
 * Tags Controller (Admin)
 *
 * Manages ticket tags/labels.
 * Extracted from SettingsController for SRP compliance.
 */
class TagsController extends AppController
{
    public function beforeFilter(EventInterface $event)
    {
        parent::beforeFilter($event);

        return $this->redirectByRole([RoleConstants::ROLE_ADMIN], 'admin');
    }

    /**
     * List all tags
     */
    public function index()
    {
        $tagsTable = $this->fetchTable('Tags');

        if ($this->request->is('post')) {
            $tag = $tagsTable->newEntity($this->request->getData());

            if ($tagsTable->save($tag)) {
                $this->Flash->success('Etiqueta creada exitosamente.');

                return $this->redirect(['action' => 'index']);
            } else {
                $this->Flash->error('Error al crear la etiqueta.');
            }
        }

        $tags = $tagsTable->find()
            ->select([
                'Tags.id',
                'Tags.name',
                'Tags.color',
                'Tags.is_active',
                'Tags.created',
                'ticket_count' => $tagsTable->find()->func()->count('TicketTags.ticket_id'),
            ])
            ->leftJoinWith('TicketTags')
            ->groupBy(['Tags.id'])
            ->orderBy(['Tags.name' => 'ASC'])
            ->all();

        $this->set(compact('tags'));
    }

    /**
     * Add tag
     */
    public function add()
    {
        $tagsTable = $this->fetchTable('Tags');
        $tag = $tagsTable->newEmptyEntity();

        if ($this->request->is('post')) {
            $tag = $tagsTable->patchEntity($tag, $this->request->getData());

            if ($tagsTable->save($tag)) {
                $this->Flash->success('Etiqueta creada exitosamente.');

                return $this->redirect(['action' => 'index']);
            } else {
                $this->Flash->error('Error al crear la etiqueta.');
            }
        }

        $this->set(compact('tag'));
    }

    /**
     * Edit tag
     */
    public function edit($id = null)
    {
        $tagsTable = $this->fetchTable('Tags');
        $tag = $tagsTable->get($id);

        if ($this->request->is(['patch', 'post', 'put'])) {
            $tag = $tagsTable->patchEntity($tag, $this->request->getData());

            if ($tagsTable->save($tag)) {
                $this->Flash->success('Etiqueta actualizada exitosamente.');

                return $this->redirect(['action' => 'index']);
            } else {
                $this->Flash->error('Error al actualizar la etiqueta.');
            }
        }

        $this->set(compact('tag'));
    }

    /**
     * Delete tag
     */
    public function delete($id = null)
    {
        $this->request->allowMethod(['post', 'delete']);

        $tagsTable = $this->fetchTable('Tags');
        $tag = $tagsTable->get($id);

        if ($tagsTable->delete($tag)) {
            $this->Flash->success('Etiqueta eliminada.');
        } else {
            $this->Flash->error('Error al eliminar la etiqueta.');
        }

        return $this->redirect(['action' => 'index']);
    }
}
