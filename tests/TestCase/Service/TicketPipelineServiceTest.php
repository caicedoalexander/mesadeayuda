<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Domain\Event\TicketStatusChanged;
use Cake\Event\EventManager;
use Cake\TestSuite\TestCase;

class TicketPipelineServiceTest extends TestCase
{
    public function testChangeStatusDispatchesByDefault(): void
    {
        $eventManager = new EventManager();
        $dispatched = [];
        $eventManager->on(TicketStatusChanged::NAME, function ($event) use (&$dispatched): void {
            $dispatched[] = $event;
        });

        $this->markTestIncomplete('Wire up after Task 1.3 lands the deferDispatch parameter');
    }
}
