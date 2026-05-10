<?php
declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\AppController as BaseAppController;
use Cake\Event\EventInterface;

/**
 * Base controller for the /admin prefix.
 *
 * Centralizes the admin layout assignment that was previously branched by
 * role in the global AppController.
 */
class AppController extends BaseAppController
{
    /**
     * @param \Cake\Event\EventInterface<\Cake\Controller\Controller> $event Event.
     * @return void
     */
    public function beforeRender(EventInterface $event): void
    {
        parent::beforeRender($event);
        $this->viewBuilder()->setLayout('admin');
    }
}
