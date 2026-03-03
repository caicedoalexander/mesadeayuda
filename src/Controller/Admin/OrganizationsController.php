<?php
declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\AppController;
use App\Utility\ValidationConstants;

/**
 * Organizations Controller (Admin)
 *
 * Manages organizations/companies.
 * Extracted from SettingsController for SRP compliance.
 */
class OrganizationsController extends AppController
{
    public function beforeFilter(\Cake\Event\EventInterface $event)
    {
        parent::beforeFilter($event);

        $user = $this->Authentication->getIdentity();
        if (!$user || $user->get('role') !== ValidationConstants::ROLE_ADMIN) {
            $this->Flash->error('Solo los administradores pueden acceder a esta sección.');
            return $this->redirect(['controller' => 'Tickets', 'action' => 'index', 'prefix' => false]);
        }
    }

    /**
     * List all organizations
     */
    public function index()
    {
        $organizationsTable = $this->fetchTable('Organizations');

        $organizations = $this->paginate($organizationsTable->find()
            ->select([
                'Organizations.id',
                'Organizations.name',
                'Organizations.created',
                'Organizations.modified',
                'user_count' => $organizationsTable->find()->func()->count('Users.id')
            ])
            ->leftJoinWith('Users')
            ->group(['Organizations.id'])
            ->orderBy(['Organizations.name' => 'ASC']));

        $this->set(compact('organizations'));
    }

    /**
     * Add organization
     */
    public function add()
    {
        $organizationsTable = $this->fetchTable('Organizations');
        $organization = $organizationsTable->newEmptyEntity();

        if ($this->request->is('post')) {
            $organization = $organizationsTable->patchEntity($organization, $this->request->getData());

            if ($organizationsTable->save($organization)) {
                $this->Flash->success('Organización creada exitosamente.');
                return $this->redirect(['action' => 'index']);
            } else {
                $this->Flash->error('Error al crear la organización.');
            }
        }

        $this->set(compact('organization'));
    }

    /**
     * Edit organization
     */
    public function edit($id = null)
    {
        $organizationsTable = $this->fetchTable('Organizations');
        $organization = $organizationsTable->get($id);

        if ($this->request->is(['patch', 'post', 'put'])) {
            $organization = $organizationsTable->patchEntity($organization, $this->request->getData());

            if ($organizationsTable->save($organization)) {
                $this->Flash->success('Organización actualizada exitosamente.');
                return $this->redirect(['action' => 'index']);
            } else {
                $this->Flash->error('Error al actualizar la organización.');
            }
        }

        $this->set(compact('organization'));
    }

    /**
     * Delete organization
     */
    public function delete($id = null)
    {
        $this->request->allowMethod(['post', 'delete']);

        $organizationsTable = $this->fetchTable('Organizations');
        $organization = $organizationsTable->get($id);

        $userCount = $this->fetchTable('Users')->find()->where(['organization_id' => $id])->count();
        if ($userCount > 0) {
            $this->Flash->error('No se puede eliminar la organización porque tiene usuarios asociados.');
            return $this->redirect(['action' => 'index']);
        }

        if ($organizationsTable->delete($organization)) {
            $this->Flash->success('Organización eliminada.');
        } else {
            $this->Flash->error('Error al eliminar la organización.');
        }

        return $this->redirect(['action' => 'index']);
    }
}
