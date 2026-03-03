<?php
declare(strict_types=1);

namespace App\Controller;

use App\Utility\ValidationConstants;
use Cake\Cache\Cache;
use Cake\Event\EventInterface;

/**
 * Users Controller
 *
 * @property \App\Model\Table\UsersTable $Users
 */
class UsersController extends AppController
{
    /**
     * Max login attempts before lockout
     */
    private const MAX_LOGIN_ATTEMPTS = 5;

    /**
     * Lockout duration in seconds (15 minutes)
     */
    private const LOCKOUT_DURATION = 900;
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

        // Check rate limiting before processing login
        if ($this->request->is('post') && $this->isLoginRateLimited()) {
            $this->Flash->error(
                sprintf('Demasiados intentos fallidos. Intenta nuevamente en %d minutos.', self::LOCKOUT_DURATION / 60)
            );

            return null;
        }

        $result = $this->Authentication->getResult();

        // If user is already logged in, redirect
        if ($result && $result->isValid()) {
            // Clear login attempts on successful login
            $ip = $this->request->clientIp();
            Cache::delete('login_attempts_' . md5($ip), ValidationConstants::CACHE_CONFIG);
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
            $ip = $this->request->clientIp();
            $cacheKey = 'login_attempts_' . md5($ip);
            $attempts = (int) Cache::read($cacheKey, ValidationConstants::CACHE_CONFIG);
            $attempts++;
            Cache::write($cacheKey, $attempts, ValidationConstants::CACHE_CONFIG);

            if ($attempts >= self::MAX_LOGIN_ATTEMPTS) {
                $this->Flash->error(
                    sprintf('Demasiados intentos fallidos. Cuenta bloqueada por %d minutos.', self::LOCKOUT_DURATION / 60)
                );
            } else {
                $remaining = self::MAX_LOGIN_ATTEMPTS - $attempts;
                $this->Flash->error(
                    sprintf('Email o contraseña inválidos. %d intentos restantes.', $remaining)
                );
            }
        }
    }

    /**
     * Check if login is rate-limited for current IP
     *
     * @return bool True if rate limited
     */
    private function isLoginRateLimited(): bool
    {
        $ip = $this->request->clientIp();
        $cacheKey = 'login_attempts_' . md5($ip);
        $attempts = (int) Cache::read($cacheKey, ValidationConstants::CACHE_CONFIG);

        return $attempts >= self::MAX_LOGIN_ATTEMPTS;
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
