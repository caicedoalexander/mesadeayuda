<?php
declare(strict_types=1);

namespace App\Controller\Admin;

use App\Constants\CacheConstants;
use App\Constants\RoleConstants;
use App\Constants\SettingKeys;
use App\Controller\AppController;
use App\Service\GmailService;
use App\Service\N8nService;
use App\Service\ProfileImageService;
use App\Service\SettingsService;
use App\Service\WhatsappService;
use Cake\Cache\Cache;
use Cake\Event\EventInterface;
use Cake\Http\Response;
use Cake\Log\Log;
use Cake\Routing\Router;
use Exception;

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
    public function beforeFilter(EventInterface $event)
    {
        parent::beforeFilter($event);

        // Unlock actions with dynamic forms
        $this->FormProtection->setConfig('unlockedActions', [
            'index', 'gmailAuth', 'gmailClientSecret', 'testWhatsapp', 'regenerateWebhookToken',
        ]);

        return $this->redirectByRole([RoleConstants::ROLE_ADMIN], 'admin');
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

            // Allowlist lives in SettingKeys::USER_EDITABLE_KEYS so the form,
            // tests and any future settings UI share a single source of truth.
            foreach ($data as $key => $value) {
                if (in_array($key, SettingKeys::USER_EDITABLE_KEYS, true)) {
                    $this->settingsService->saveSetting($key, $value);
                }
            }

            $this->Flash->success('Configuración guardada exitosamente.');

            return $this->redirect(['action' => 'index']);
        }

        $allSettings = $this->settingsService->loadAll();
        $webhookToken = $allSettings[SettingKeys::WEBHOOK_GMAIL_IMPORT_TOKEN] ?? '';
        $webhookUrl = Router::url(['_name' => 'webhook_gmail_import'], true);
        $lastWebhookRun = (int)(Cache::read('gmail_import_last_run', 'default') ?? 0);

        $this->set([
            'settings' => $allSettings,
            'webhookGmailToken' => $webhookToken,
            'webhookGmailUrl' => $webhookUrl,
            'webhookGmailLastRun' => $lastWebhookRun > 0 ? date('Y-m-d H:i:s', $lastWebhookRun) : null,
        ]);
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
        $clientSecretJson = $allSettings[SettingKeys::GMAIL_CLIENT_SECRET_JSON] ?? '';
        if (!empty($clientSecretJson)) {
            $decoded = json_decode($clientSecretJson, true);
            if (is_array($decoded)) {
                $config['client_secret'] = $decoded;
            }
        }

        // Set redirect URI for OAuth2 flow (callback URL).
        // Use the dedicated /oauth/gmail/callback route (defined in config/routes.php)
        // as the canonical redirect URI registered in Google Cloud Console.
        $config['redirect_uri'] = Router::url('/oauth/gmail/callback', true);

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

                return $this->redirect(['action' => 'index']);
            } catch (Exception $e) {
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
        try {
            $gmailService = new GmailService(GmailService::loadConfigFromDatabase());
            $messages = $gmailService->getMessages('is:unread', 5);

            $this->Flash->success('Conexión exitosa. Se encontraron ' . count($messages) . ' mensajes no leídos.');
            Log::info('Gmail connection test successful', ['message_count' => count($messages)]);
        } catch (Exception $e) {
            $this->Flash->error('Error al conectar con Gmail: ' . $e->getMessage());
            Log::error('Gmail connection test failed: ' . $e->getMessage());
        }

        return $this->redirect(['action' => 'index']);
    }

    /**
     * Save Gmail OAuth client_secret JSON pasted from Google Cloud Console.
     *
     * Validates JSON structure (web/installed root with client_id, client_secret,
     * redirect_uris) and persists encrypted via SettingsService.
     *
     * @return \Cake\Http\Response
     */
    public function gmailClientSecret(): Response
    {
        $this->request->allowMethod(['post']);

        $json = trim((string)$this->request->getData('client_secret_json'));

        if ($json === '') {
            $this->Flash->error('Pega el contenido del archivo client_secret.json.');

            return $this->redirect(['action' => 'index']);
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            $this->Flash->error('JSON inválido: ' . json_last_error_msg());

            return $this->redirect(['action' => 'index']);
        }

        $root = $decoded['web'] ?? $decoded['installed'] ?? null;
        $required = ['client_id', 'client_secret', 'redirect_uris'];
        if (!is_array($root) || array_diff($required, array_keys($root))) {
            $this->Flash->error(
                'El JSON debe contener client_id, client_secret y redirect_uris bajo "web" o "installed".',
            );

            return $this->redirect(['action' => 'index']);
        }

        $saved = $this->settingsService->saveSetting(SettingKeys::GMAIL_CLIENT_SECRET_JSON, $json);

        if ($saved) {
            $this->Flash->success('Configuración de Gmail guardada. Ahora autoriza el acceso OAuth.');
            Log::info('Gmail client_secret updated', [
                'user' => $this->Authentication->getIdentity()?->get('email'),
            ]);
        } else {
            $this->Flash->error('No se pudo guardar la configuración de Gmail.');
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
            ->where(['Users.role IN' => RoleConstants::STAFF_ROLES])
            ->orderBy(['Users.created' => 'DESC']));

        $this->set(compact('users'));
    }

    /**
     * Edit user
     *
     * @param string|null $id User id
     * @return \Cake\Http\Response|null|void Renders view
     */
    public function editUser(?string $id = null)
    {
        $usersTable = $this->fetchTable('Users');
        $user = $usersTable->get($id);

        if ($this->request->is(['patch', 'post', 'put'])) {
            $data = $this->request->getData();

            // Handle profile image upload
            $profileImageFile = $this->request->getUploadedFile('profile_image_upload');
            if ($profileImageFile && $profileImageFile->getError() === UPLOAD_ERR_OK) {
                $result = (new ProfileImageService())->saveProfileImage((int)$user->id, $profileImageFile);

                if ($result['success']) {
                    $data['profile_image'] = $result['filename'];
                } else {
                    $this->Flash->error($result['message']);
                    $this->set(compact('user'));

                    return;
                }
            }

            // Handle password change
            if (!empty($data['new_password'])) {
                if ($data['new_password'] !== $data['confirm_password']) {
                    $this->Flash->error('Las contraseñas no coinciden.');
                    $this->set(compact('user'));

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

        $this->set(compact('user'));
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

        $this->set(compact('user'));
    }

    /**
     * Deactivate user
     *
     * @param string|null $id User id
     * @return \Cake\Http\Response|null|void Redirects back
     */
    public function deactivateUser(?string $id = null)
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
    public function activateUser(?string $id = null)
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
     * Regenera el shared secret del webhook de Gmail.
     *
     * @return \Cake\Http\Response
     */
    public function regenerateWebhookToken(): Response
    {
        $this->request->allowMethod(['POST']);

        // Capture the current token *before* rotation so it can stay valid
        // for a short grace window — avoids dropping in-flight n8n traffic.
        $currentToken = $this->settingsService->loadAll()[SettingKeys::WEBHOOK_GMAIL_IMPORT_TOKEN] ?? null;

        $token = bin2hex(random_bytes(32));
        $saved = $this->settingsService->saveSetting(SettingKeys::WEBHOOK_GMAIL_IMPORT_TOKEN, $token);

        if ($saved) {
            if (is_string($currentToken) && $currentToken !== '') {
                Cache::write(
                    CacheConstants::WEBHOOK_GMAIL_PREVIOUS_TOKEN,
                    [
                        'token' => $currentToken,
                        'expires_at' => time() + CacheConstants::WEBHOOK_TOKEN_OVERLAP_SECONDS,
                    ],
                    'default',
                );
            }
            $this->Flash->success(sprintf(
                'Token de webhook regenerado. El anterior sigue siendo aceptado por %d s mientras actualizas n8n.',
                CacheConstants::WEBHOOK_TOKEN_OVERLAP_SECONDS,
            ));
        } else {
            $this->Flash->error('No se pudo regenerar el token.');
        }

        return $this->redirect(['action' => 'index']);
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

        $n8nService = new N8nService();
        $result = $n8nService->testConnection();

        $this->set([
            'success' => $result['success'],
            'message' => $result['message'],
        ]);
        $this->viewBuilder()->setOption('serialize', ['success', 'message']);

        return null;
    }
}
