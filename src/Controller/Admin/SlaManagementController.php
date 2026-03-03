<?php
declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\AppController;
use App\Service\SlaManagementService;
use App\Utility\ValidationConstants;
use Cake\Log\Log;

/**
 * SLA Management Controller
 *
 * Handles SLA (Service Level Agreement) configuration for:
 * - PQRS (by type: Petición, Queja, Reclamo, Sugerencia)
 * - Compras
 * - Tickets (future)
 */
class SlaManagementController extends AppController
{
    private SlaManagementService $slaService;

    /**
     * Initialize
     *
     * @return void
     */
    public function initialize(): void
    {
        parent::initialize();
        $this->slaService = new SlaManagementService();
    }

    /**
     * Before filter
     *
     * @param \Cake\Event\EventInterface $event Event
     * @return \Cake\Http\Response|null|void
     */
    public function beforeFilter(\Cake\Event\EventInterface $event)
    {
        parent::beforeFilter($event);

        // Unlock actions with dynamic forms
        $this->FormProtection->setConfig('unlockedActions', ['index']);

        // Only admins can access SLA management
        $user = $this->Authentication->getIdentity();
        if ($user && $user->get('role') !== ValidationConstants::ROLE_ADMIN) {
            $this->Flash->error('Solo los administradores pueden acceder a la gestión de SLA.');
            return $this->redirect(['controller' => 'Tickets', 'action' => 'index']);
        }
    }

    /**
     * Index - View and edit all SLA configurations
     *
     * @return \Cake\Http\Response|null|void
     */
    public function index()
    {
        if ($this->request->is('post')) {
            return $this->save();
        }

        // Load all SLA configurations (always fresh from DB - no cache)
        $slaConfigurations = $this->slaService->getAllSlaConfigurations();

        $this->set(compact('slaConfigurations'));
        $this->viewBuilder()->setLayout('admin');
    }

    /**
     * Save SLA configuration
     *
     * @return \Cake\Http\Response|null
     */
    public function save()
    {
        if (!$this->request->is('post')) {
            return $this->redirect(['action' => 'index']);
        }

        try {
            $result = $this->slaService->saveAllSettings($this->request->getData());

            if ($result['success']) {
                $this->Flash->success($result['message']);
                Log::info('SLA settings updated successfully', ['count' => $result['count'], 'user' => $this->Authentication->getIdentity()?->get('email')]);
            } else {
                $this->Flash->warning($result['message']);
                Log::warning('Some SLA settings failed to save', ['errors' => $result['errors']]);
            }
        } catch (\Exception $e) {
            $this->Flash->error('Error al guardar la configuración de SLA: ' . $e->getMessage());
            Log::error('Error saving SLA settings', ['exception' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
        }

        return $this->redirect(['action' => 'index']);
    }

    /**
     * Preview SLA calculations
     *
     * @return void
     */
    public function preview()
    {
        $now = new \Cake\I18n\DateTime();

        // Preview PQRS SLA calculations
        $pqrsTypes = ['peticion', 'queja', 'reclamo', 'sugerencia'];
        $pqrsPreview = [];

        foreach ($pqrsTypes as $type) {
            $deadlines = $this->slaService->calculatePqrsSlaDeadlines($type, $now);
            $pqrsPreview[$type] = [
                'first_response' => $deadlines['first_response_sla_due']->i18nFormat('yyyy-MM-dd HH:mm:ss'),
                'resolution' => $deadlines['resolution_sla_due']->i18nFormat('yyyy-MM-dd HH:mm:ss'),
            ];
        }

        // Preview Compras SLA calculations
        $comprasDeadlines = $this->slaService->calculateComprasSlaDeadlines($now);
        $comprasPreview = [
            'first_response' => $comprasDeadlines['first_response_sla_due']->i18nFormat('yyyy-MM-dd HH:mm:ss'),
            'resolution' => $comprasDeadlines['resolution_sla_due']->i18nFormat('yyyy-MM-dd HH:mm:ss'),
        ];

        $this->set(compact('pqrsPreview', 'comprasPreview', 'now'));
        $this->viewBuilder()->setLayout('admin');
    }
}
