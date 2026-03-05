<?php
declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\AppController;
use App\Service\GmailService;
use App\Service\SettingsService;
use App\Service\WhatsappService;
use App\Utility\SettingKeys;
use App\Utility\ValidationConstants;
use Cake\Log\Log;

/**
 * Settings Controller
 *
 * Handles system configuration including:
 * - General settings
 * - Gmail OAuth setup
 * - Automatic encryption of sensitive values
 */
class SettingsController extends AppController
{
    private SettingsService $settingsService;

    public function initialize(): void
    {
        parent::initialize();
        $this->settingsService = new SettingsService();
    }

    /**
     * Before filter - require admin role
     *
     * @param \Cake\Event\EventInterface<\Cake\Controller\Controller> $event Event.
     * @return \Cake\Http\Response|null|void
     */
    public function beforeFilter(\Cake\Event\EventInterface $event)
    {
        parent::beforeFilter($event);

        // Unlock actions with dynamic forms
        $this->FormProtection->setConfig('unlockedActions', [
            'index', 'gmailAuth', 'testWhatsapp',
        ]);

        $user = $this->Authentication->getIdentity();
        if (!$user || $user->get('role') !== ValidationConstants::ROLE_ADMIN) {
            $this->Flash->error('Solo los administradores pueden acceder a esta sección.');
            return $this->redirect(['controller' => 'Tickets', 'action' => 'index', 'prefix' => false]);
        }
    }

    /**
     * Index method - Show and update settings
     *
     * @return \Cake\Http\Response|null|void Renders view
     */
    public function index()
    {
        if ($this->request->is(['post', 'put'])) {
            $data = $this->request->getData();

            // Handle checkboxes (if not present, they're unchecked = '0')
            if (!isset($data[SettingKeys::WHATSAPP_ENABLED])) {
                $data[SettingKeys::WHATSAPP_ENABLED] = '0';
            }
            if (!isset($data[SettingKeys::N8N_ENABLED])) {
                $data[SettingKeys::N8N_ENABLED] = '0';
            }
            if (!isset($data[SettingKeys::N8N_SEND_TAGS_LIST])) {
                $data[SettingKeys::N8N_SEND_TAGS_LIST] = '0';
            }

            // Allowlist of valid setting keys to prevent arbitrary setting injection
            $allowedKeys = [
                SettingKeys::SYSTEM_TITLE, SettingKeys::GMAIL_CHECK_INTERVAL,
                SettingKeys::WHATSAPP_ENABLED, SettingKeys::WHATSAPP_API_URL, SettingKeys::WHATSAPP_API_KEY,
                SettingKeys::WHATSAPP_INSTANCE_NAME, SettingKeys::WHATSAPP_TICKETS_NUMBER,
                SettingKeys::WHATSAPP_COMPRAS_NUMBER, SettingKeys::WHATSAPP_PQRS_NUMBER,
                SettingKeys::N8N_ENABLED, SettingKeys::N8N_WEBHOOK_URL, SettingKeys::N8N_API_KEY,
                SettingKeys::N8N_SEND_TAGS_LIST, SettingKeys::N8N_TIMEOUT,
            ];

            foreach ($data as $key => $value) {
                if (in_array($key, $allowedKeys, true)) {
                    $this->settingsService->saveSetting($key, $value);
                }
            }

            $this->Flash->success('Configuración guardada exitosamente.');
            return $this->redirect(['action' => 'index']);
        }

        $this->set('settings', $this->settingsService->loadAll());
    }

    /**
     * Gmail OAuth authorization
     *
     * @return \Cake\Http\Response|null|void
     */
    public function gmailAuth()
    {
        // Load all settings (already decrypted by SettingsService)
        $allSettings = $this->settingsService->loadAll();

        $config = [];
        if (!empty($allSettings[SettingKeys::GMAIL_CLIENT_SECRET_PATH])) {
            $config['client_secret_path'] = $allSettings[SettingKeys::GMAIL_CLIENT_SECRET_PATH];
        }

        // Set redirect URI for OAuth2 flow (callback URL)
        $config['redirect_uri'] = \Cake\Routing\Router::url([
            'controller' => 'Settings',
            'action' => 'gmailAuth',
            'prefix' => 'Admin',
        ], true); // true = full URL with domain

        $gmailService = new GmailService($config);

        // Check if we have a code from Google
        $code = $this->request->getQuery('code');

        if ($code) {
            try {
                // Exchange code for tokens
                $tokens = $gmailService->authenticate($code);

                if (isset($tokens['refresh_token'])) {
                    // Save refresh token to settings using service
                    if ($this->settingsService->saveSetting(SettingKeys::GMAIL_REFRESH_TOKEN, $tokens['refresh_token'])) {
                        $this->Flash->success('Gmail autorizado exitosamente.');
                        Log::info('Gmail OAuth completed successfully');
                    } else {
                        $this->Flash->error('Error al guardar el token de Gmail.');
                        Log::error('Failed to save Gmail refresh token');
                    }
                } else {
                    // Google may not return refresh_token on re-authorization if consent was cached.
                    // This is OK if we already have a stored refresh_token.
                    $existingToken = $allSettings[SettingKeys::GMAIL_REFRESH_TOKEN] ?? null;
                    if ($existingToken) {
                        $this->Flash->success('Gmail reconectado exitosamente.');
                        Log::info('Gmail OAuth re-authorized (using existing refresh token)');
                    } else {
                        $this->Flash->warning('No se recibió refresh token. Intenta nuevamente.');
                        Log::warning('No refresh token in OAuth response', ['token_keys' => array_keys($tokens ?? [])]);
                    }
                }

                // Always trigger the worker on successful authentication/re-authentication
                @file_put_contents(TMP . \App\Command\GmailWorkerCommand::TRIGGER_FILE, (string)time());

                return $this->redirect(['action' => 'index']);
            } catch (\Exception $e) {
                $this->Flash->error('Error en la autorización: ' . $e->getMessage());
                Log::error('Gmail OAuth error: ' . $e->getMessage());
                return $this->redirect(['action' => 'index']);
            }
        }

        // No code, redirect to Google authorization URL
        $authUrl = $gmailService->getAuthUrl();
        return $this->redirect($authUrl);
    }

    /**
     * Test Gmail connection
     *
     * @return \Cake\Http\Response|null|void
     */
    public function testGmail()
    {
        // Load all settings (already decrypted by SettingsService)
        $allSettings = $this->settingsService->loadAll();

        $config = [
            'refresh_token' => $allSettings[SettingKeys::GMAIL_REFRESH_TOKEN] ?? '',
            'client_secret_path' => $allSettings[SettingKeys::GMAIL_CLIENT_SECRET_PATH] ?? '',
        ];

        try {
            $gmailService = new GmailService($config);
            $messages = $gmailService->getMessages('is:unread', 5);

            $this->Flash->success('Conexión exitosa. Se encontraron ' . count($messages) . ' mensajes no leídos.');
            Log::info('Gmail connection test successful', ['message_count' => count($messages)]);
        } catch (\Exception $e) {
            $this->Flash->error('Error al conectar con Gmail: ' . $e->getMessage());
            Log::error('Gmail connection test failed: ' . $e->getMessage());
        }

        return $this->redirect(['action' => 'index']);
    }

    // Redirect legacy routes to new controllers

    public function emailTemplates()
    {
        return $this->redirect(['controller' => 'EmailTemplates', 'action' => 'index', 'prefix' => 'Admin']);
    }

    public function editTemplate($id = null)
    {
        return $this->redirect(['controller' => 'EmailTemplates', 'action' => 'edit', $id, 'prefix' => 'Admin']);
    }

    public function previewTemplate($id = null)
    {
        return $this->redirect(['controller' => 'EmailTemplates', 'action' => 'preview', $id, 'prefix' => 'Admin']);
    }

    /**
     * Users management
     *
     * @return \Cake\Http\Response|null|void Renders view
     */
    public function users()
    {
        $usersTable = $this->fetchTable('Users');

        $users = $this->paginate($usersTable->find()
            ->contain(['Organizations'])
            ->where(['Users.role IN' => ValidationConstants::STAFF_ROLES])
            ->orderBy(['Users.created' => 'DESC']));

        $this->set(compact('users'));
    }

    /**
     * Edit user
     *
     * @param string|null $id User id
     * @return \Cake\Http\Response|null|void Renders view
     */
    public function editUser($id = null)
    {
        $usersTable = $this->fetchTable('Users');
        $user = $usersTable->get($id, contain: ['Organizations']);

        if ($this->request->is(['patch', 'post', 'put'])) {
            $data = $this->request->getData();

            // Handle profile image upload
            $profileImageFile = $this->request->getUploadedFile('profile_image_upload');
            if ($profileImageFile && $profileImageFile->getError() === UPLOAD_ERR_OK) {
                $result = (new \App\Service\ProfileImageService())->saveProfileImage((int) $user->id, $profileImageFile);

                if ($result['success']) {
                    $data['profile_image'] = $result['filename'];
                } else {
                    $this->Flash->error($result['message']);
                    $organizations = $this->fetchTable('Organizations')->find('list')->toArray();
                    $this->set(compact('user', 'organizations'));
                    return;
                }
            }

            // Handle password change
            if (!empty($data['new_password'])) {
                if ($data['new_password'] !== $data['confirm_password']) {
                    $this->Flash->error('Las contraseñas no coinciden.');
                    $organizations = $this->fetchTable('Organizations')->find('list')->toArray();
                    $this->set(compact('user', 'organizations'));
                    return;
                }
                // Set password field to new_password value
                $data['password'] = $data['new_password'];
            } else {
                // Explicitly unset password if not changing it
                unset($data['password']);
            }

            // Remove password-related fields that shouldn't be patched
            unset($data['new_password']);
            unset($data['confirm_password']);
            unset($data['profile_image_upload']);

            $user = $usersTable->patchEntity($user, $data);

            if ($usersTable->save($user)) {
                $this->Flash->success('Usuario actualizado exitosamente.');
                return $this->redirect(['action' => 'users']);
            } else {
                $this->Flash->error('Error al actualizar el usuario.');
            }
        }

        $organizations = $this->fetchTable('Organizations')->find('list')->toArray();
        $this->set(compact('user', 'organizations'));
    }

    public function tags()
    {
        return $this->redirect(['controller' => 'Tags', 'action' => 'index', 'prefix' => 'Admin']);
    }

    public function addTag()
    {
        return $this->redirect(['controller' => 'Tags', 'action' => 'add', 'prefix' => 'Admin']);
    }

    public function editTag($id = null)
    {
        return $this->redirect(['controller' => 'Tags', 'action' => 'edit', $id, 'prefix' => 'Admin']);
    }

    public function deleteTag($id = null)
    {
        return $this->redirect(['controller' => 'Tags', 'action' => 'delete', $id, 'prefix' => 'Admin']);
    }

    /**
     * Add user
     *
     * @return \Cake\Http\Response|null|void Renders view
     */
    public function addUser()
    {
        $usersTable = $this->fetchTable('Users');
        $user = $usersTable->newEmptyEntity();

        if ($this->request->is('post')) {
            $data = $this->request->getData();

            // Validate password confirmation
            if (!empty($data['password']) && $data['password'] !== $data['confirm_password']) {
                $this->Flash->error('Las contraseñas no coinciden.');
            } else {
                // Remove confirm_password from data
                unset($data['confirm_password']);

                $user = $usersTable->patchEntity($user, $data);

                if ($usersTable->save($user)) {
                    $this->Flash->success('Usuario creado exitosamente.');
                    return $this->redirect(['action' => 'users']);
                } else {
                    $this->Flash->error('Error al crear el usuario.');
                }
            }
        }

        $organizations = $this->fetchTable('Organizations')->find('list')->toArray();
        $this->set(compact('user', 'organizations'));
    }

    /**
     * Deactivate user
     *
     * @param string|null $id User id
     * @return \Cake\Http\Response|null|void Redirects back
     */
    public function deactivateUser($id = null)
    {
        $this->request->allowMethod(['post']);

        $usersTable = $this->fetchTable('Users');
        $user = $usersTable->get($id);

        $user->is_active = false;

        if ($usersTable->save($user)) {
            $this->Flash->success('Usuario desactivado exitosamente.');
        } else {
            $this->Flash->error('Error al desactivar el usuario.');
        }

        return $this->redirect(['action' => 'users']);
    }

    /**
     * Activate user
     *
     * @param string|null $id User id
     * @return \Cake\Http\Response|null|void Redirects back
     */
    public function activateUser($id = null)
    {
        $this->request->allowMethod(['post']);

        $usersTable = $this->fetchTable('Users');
        $user = $usersTable->get($id);

        $user->is_active = true;

        if ($usersTable->save($user)) {
            $this->Flash->success('Usuario activado exitosamente.');
        } else {
            $this->Flash->error('Error al activar el usuario.');
        }

        return $this->redirect(['action' => 'users']);
    }

    /**
     * Test WhatsApp connection
     *
     * @return \Cake\Http\Response|null
     */
    public function testWhatsapp()
    {
        $this->request->allowMethod(['get']);
        $this->viewBuilder()->setClassName('Json');

        // Pass null to force config resolution from cache/DB (view config excludes sensitive keys)
        $whatsappService = new WhatsappService(null);
        $result = $whatsappService->testConnection();

        $this->set([
            'success' => $result['success'],
            'message' => $result['message'],
        ]);
        $this->viewBuilder()->setOption('serialize', ['success', 'message']);

        return null;
    }

    /**
     * Test n8n connection
     *
     * @return \Cake\Http\Response|null
     */
    public function testN8n()
    {
        $this->request->allowMethod(['get']);
        $this->viewBuilder()->setClassName('Json');

        $n8nService = new \App\Service\N8nService();
        $result = $n8nService->testConnection();

        $this->set([
            'success' => $result['success'],
            'message' => $result['message'],
        ]);
        $this->viewBuilder()->setOption('serialize', ['success', 'message']);

        return null;
    }
    public function organizations()
    {
        return $this->redirect(['controller' => 'Organizations', 'action' => 'index', 'prefix' => 'Admin']);
    }

    public function addOrganization()
    {
        return $this->redirect(['controller' => 'Organizations', 'action' => 'add', 'prefix' => 'Admin']);
    }

    public function editOrganization($id = null)
    {
        return $this->redirect(['controller' => 'Organizations', 'action' => 'edit', $id, 'prefix' => 'Admin']);
    }

    public function deleteOrganization($id = null)
    {
        return $this->redirect(['controller' => 'Organizations', 'action' => 'delete', $id, 'prefix' => 'Admin']);
    }
}
