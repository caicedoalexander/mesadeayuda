<?php
declare(strict_types=1);

/**
 * CakePHP(tm) : Rapid Development Framework (https://cakephp.org)
 * Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 * @link      https://cakephp.org CakePHP(tm) Project
 * @since     0.2.9
 * @license   https://opensource.org/licenses/mit-license.php MIT License
 */
namespace App\Controller;

use App\Utility\SettingKeys;
use App\Utility\SettingsEncryptionTrait;
use App\Utility\ValidationConstants;
use Cake\Controller\Controller;

/**
 * Application Controller
 *
 * Add your application-wide methods in the class below, your controllers
 * will inherit them.
 *
 * @link https://book.cakephp.org/5/en/controllers.html#the-app-controller
 * @property \Authentication\Controller\Component\AuthenticationComponent $Authentication
 */
class AppController extends Controller
{
    use SettingsEncryptionTrait;
    /**
     * Initialization hook method.
     *
     * Use this method to add common initialization code like loading components.
     *
     * e.g. `$this->loadComponent('FormProtection');`
     *
     * @return void
     */
    public function initialize(): void
    {
        parent::initialize();

        $this->loadComponent('Flash');
        $this->loadComponent('Authentication.Authentication');

        $this->loadComponent('FormProtection');
    }

    /**
     * Before filter callback
     *
     * @param \Cake\Event\EventInterface<\Cake\Controller\Controller> $event Event.
     * @return \Cake\Http\Response|null|void
     */
    public function beforeFilter(\Cake\Event\EventInterface $event)
    {
        parent::beforeFilter($event);

        // Make user data available in all views
        $user = $this->Authentication->getIdentity();
        $this->set('currentUser', $user);

        // Load system settings with cache (1 hour TTL)
        $systemConfig = \Cake\Cache\Cache::remember(ValidationConstants::CACHE_SETTINGS, function () {
            $systemSettingsTable = $this->fetchTable('SystemSettings');
            $settings = $systemSettingsTable->find()
                ->select(['setting_key', 'setting_value'])
                ->toArray();

            $config = [];
            foreach ($settings as $setting) {
                $config[$setting->setting_key] = $setting->setting_value;
            }

            // Decrypt sensitive values automatically
            return $this->processSettings($config);
        }, ValidationConstants::CACHE_CONFIG);

        // Filter out sensitive settings before passing to views
        $sensitiveKeys = [
            SettingKeys::GMAIL_REFRESH_TOKEN, SettingKeys::GMAIL_CLIENT_SECRET_PATH,
            SettingKeys::WHATSAPP_API_KEY,
            SettingKeys::N8N_API_KEY, SettingKeys::N8N_WEBHOOK_URL,
        ];
        $safeConfig = array_diff_key($systemConfig, array_flip($sensitiveKeys));
        $this->set('systemConfig', $safeConfig);
        $this->set('systemTitle', $systemConfig[SettingKeys::SYSTEM_TITLE] ?? ValidationConstants::DEFAULT_SYSTEM_TITLE);

        // Set layout based on user role
        if ($user) {
            $role = $user->get('role');
            if ($role === ValidationConstants::ROLE_ADMIN) {
                $this->viewBuilder()->setLayout('admin');
            } elseif ($role === ValidationConstants::ROLE_AGENT) {
                $this->viewBuilder()->setLayout('agent');
            } elseif ($role === ValidationConstants::ROLE_COMPRAS) {
                $this->viewBuilder()->setLayout('compras');
            } elseif ($role === ValidationConstants::ROLE_SERVICIO_CLIENTE) {
                $this->viewBuilder()->setLayout('servicio_cliente');
            } else {
                $this->viewBuilder()->setLayout('requester');
            }
        }
    }

    /**
     * Get the default redirect target for a given role
     *
     * @param string $role User role
     * @return array CakePHP-style URL array
     */
    protected function getDefaultRedirectForRole(string $role): array
    {
        $roleRedirects = [
            ValidationConstants::ROLE_SERVICIO_CLIENTE => ['controller' => 'Pqrs', 'action' => 'index', '?' => ['view' => 'mis_pqrs']],
            ValidationConstants::ROLE_COMPRAS => ['controller' => 'Compras', 'action' => 'index', '?' => ['view' => 'mis_compras']],
            ValidationConstants::ROLE_AGENT => ['controller' => 'Tickets', 'action' => 'index', '?' => ['view' => 'mis_tickets']],
            ValidationConstants::ROLE_REQUESTER => ['controller' => 'Tickets', 'action' => 'index', '?' => ['view' => 'mis_tickets']],
            ValidationConstants::ROLE_ADMIN => ['controller' => 'Tickets', 'action' => 'index'],
        ];

        return $roleRedirects[$role] ?? ['controller' => 'Tickets', 'action' => 'index'];
    }

    /**
     * Redirect user by role if not allowed for current module
     *
     * Eliminates ~45 lines of duplicated code across 3 controllers
     *
     * @param array $allowedRoles Roles allowed to access current module
     * @param string $moduleName Module name for error message (e.g., 'tickets', 'PQRS', 'compras')
     * @return \Cake\Http\Response|null Redirect response if not allowed, null if access granted
     */
    protected function redirectByRole(array $allowedRoles, string $moduleName): ?\Cake\Http\Response
    {
        $user = $this->Authentication->getIdentity();

        if (!$user) {
            return null; // Allow unauthenticated access (will be handled by Authentication plugin)
        }

        $role = $user->get('role');

        // Check if user role is allowed
        if (in_array($role, $allowedRoles, true)) {
            return null; // Access granted
        }

        // User not allowed - redirect to their default module
        $this->Flash->error(__('No tienes permiso para acceder al módulo de {0}.', $moduleName));

        return $this->redirect($this->getDefaultRedirectForRole($role));
    }
}
