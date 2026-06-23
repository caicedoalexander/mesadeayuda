<?php
declare(strict_types=1);

namespace App\Controller;

use App\Service\ProfileImageService;
use Cake\Event\EventInterface;
use Cake\Http\Exception\NotFoundException;
use Cake\Http\Response;

/**
 * Users Controller
 *
 * @property \App\Model\Table\UsersTable $Users
 */
class UsersController extends AppController
{
    /**
     * Before filter callback
     *
     * @param \Cake\Event\EventInterface $event Event.
     * @return \Cake\Http\Response|null|void
     */
    public function beforeFilter(EventInterface $event)
    {
        parent::beforeFilter($event);

        // Allow login action without authentication
        $this->Authentication->addUnauthenticatedActions(['login']);
    }

    /**
     * Login action
     *
     * @return \Cake\Http\Response|null|void
     */
    public function login()
    {
        $this->request->allowMethod(['get', 'post']);
        $this->viewBuilder()->setLayout('login');

        $result = $this->Authentication->getResult();

        // If user is already logged in, redirect
        if ($result && $result->isValid()) {
            $user = $this->Authentication->getIdentity();
            $target = $this->request->getQuery('redirect');

            // Validate redirect is a safe internal path (prevent open redirect)
            if (!$target || !is_string($target) || !preg_match('#^/[a-zA-Z0-9]#', $target) || str_contains($target, '//')) {
                $target = $this->getDefaultRedirectForRole($user->get('role'));
            }

            return $this->redirect($target);
        }

        // Display error if user submitted invalid credentials
        if ($this->request->is('post') && !$result->isValid()) {
            $this->Flash->error('Email o contraseña inválidos.');
        }
    }

    /**
     * Sirve la foto de perfil vía redirect 302 a URL presignada de S3.
     * Autorización: cualquier usuario autenticado (igual que el resto de
     * vistas que muestran avatares).
     *
     * @param string|null $id User id
     */
    public function profileImage(?string $id = null): Response
    {
        $user = $this->fetchTable('Users')->get((int)$id);

        $key = (string)$user->profile_image;
        $url = $key !== '' ? (new ProfileImageService())->presignedImageUrl($key) : null;

        if ($url === null) {
            throw new NotFoundException('Imagen de perfil no encontrada.');
        }

        return $this->redirect($url);
    }

    /**
     * Logout action
     *
     * @return \Cake\Http\Response|null|void
     */
    public function logout()
    {
        $result = $this->Authentication->getResult();

        if ($result && $result->isValid()) {
            $this->Authentication->logout();
            $this->Flash->success('Has cerrado sesión exitosamente');

            return $this->redirect(['controller' => 'Users', 'action' => 'login']);
        }
    }
}
