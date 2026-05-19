<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Gmail;

use App\Service\Exception\GmailApiException;
use App\Service\Gmail\GmailErrorCategory;
use App\Service\Gmail\MarkReadQueueService;
use App\Service\GmailService;
use Cake\Datasource\EntityInterface;
use Cake\ORM\Entity;
use Cake\ORM\Query\SelectQuery;
use Cake\ORM\Table;
use LogicException;
use PHPUnit\Framework\TestCase;

final class MarkReadQueueServiceTest extends TestCase
{
    /**
     * @param list<array<string, mixed>> $rows
     */
    private function makeTable(array $rows = []): Table
    {
        return new class ($rows) extends Table {
            /**
             * @var list<\Cake\ORM\Entity>
             */
            public array $rows;
            /**
             * @var list<array{op:string, payload:mixed}>
             */
            public array $log = [];

            /**
             * @param list<array<string, mixed>> $rows
             */
            public function __construct(array $rows)
            {
                $this->rows = array_map(static function (array $r): Entity {
                    $entity = new Entity();
                    foreach ($r as $k => $v) {
                        $entity->set($k, $v);
                    }
                    if ($entity->get('id') === null) {
                        $entity->set('id', random_int(1, 100000));
                    }

                    return $entity;
                }, $rows);
            }

            /**
             * @param array<string, mixed> ...$args
             */
            public function find(string $type = 'all', mixed ...$args): SelectQuery
            {
                throw new LogicException('Use the test-specific helpers instead of find().');
            }

            /**
             * @return list<\Cake\ORM\Entity>
             */
            public function selectAllForTest(): array
            {
                return $this->rows;
            }

            /**
             * @param array<string, mixed> $options
             */
            public function saveOrFail(EntityInterface $entity, array $options = []): EntityInterface
            {
                $this->log[] = ['op' => 'save', 'payload' => $entity];

                return $entity;
            }

            /**
             * @param array<string, mixed> $options
             */
            public function delete(EntityInterface $entity, array $options = []): bool
            {
                $this->log[] = ['op' => 'delete', 'payload' => $entity];
                $this->rows = array_values(array_filter(
                    $this->rows,
                    static fn(Entity $r) => $r->get('id') !== $entity->get('id'),
                ));

                return true;
            }
        };
    }

    public function testEnqueueInsertsNewRowWhenMessageIdAbsent(): void
    {
        $table = $this->makeTable();
        $service = new MarkReadQueueService($table);

        $service->enqueue('msg-1', 'transient blip', GmailErrorCategory::TRANSIENT);

        $this->assertCount(1, $table->log);
        $entity = $table->log[0]['payload'];
        $this->assertSame('msg-1', $entity->gmail_message_id);
        $this->assertSame(1, $entity->attempts);
        $this->assertSame('transient blip', $entity->last_error);
        $this->assertSame(GmailErrorCategory::TRANSIENT, $entity->last_category);
    }

    public function testEnqueueIncrementsAttemptsOnDuplicate(): void
    {
        $existing = new Entity([
            'id' => 7,
            'gmail_message_id' => 'msg-1',
            'attempts' => 1,
            'last_error' => 'old',
            'last_category' => GmailErrorCategory::TRANSIENT,
        ]);
        $table = $this->makeTable();
        $table->rows = [$existing];
        $service = new MarkReadQueueService($table);

        $service->enqueue('msg-1', 'second blip', GmailErrorCategory::TRANSIENT);

        $this->assertSame(2, $existing->attempts);
        $this->assertSame('second blip', $existing->last_error);
    }

    public function testProcessPendingMarksAndDeletesOnSuccess(): void
    {
        $table = $this->makeTable([
            ['gmail_message_id' => 'msg-1', 'attempts' => 1, 'last_error' => null, 'last_category' => null],
        ]);
        $gmail = $this->createMock(GmailService::class);
        $gmail->expects($this->once())
            ->method('markAsRead')
            ->with('msg-1')
            ->willReturn(true);

        $service = new MarkReadQueueService($table);
        $counters = $service->processPending($gmail);

        $this->assertSame(['processed' => 1, 'retried' => 1, 'failed' => 0, 'dropped' => 0], $counters);
        $this->assertCount(0, $table->rows);
    }

    public function testProcessPendingDropsOnPermanentCategory(): void
    {
        $table = $this->makeTable([
            ['gmail_message_id' => 'msg-1', 'attempts' => 1, 'last_error' => null, 'last_category' => null],
        ]);
        $gmail = $this->createMock(GmailService::class);
        $gmail->method('markAsRead')->willThrowException(
            new GmailApiException(GmailErrorCategory::PERMANENT, 404, 'not found'),
        );

        $service = new MarkReadQueueService($table);
        $counters = $service->processPending($gmail);

        $this->assertSame(1, $counters['dropped']);
        $this->assertSame(0, $counters['retried']);
        $this->assertCount(0, $table->rows);
    }

    public function testProcessPendingIncrementsAttemptsOnTransientFailure(): void
    {
        $row = new Entity([
            'id' => 1,
            'gmail_message_id' => 'msg-1',
            'attempts' => 1,
            'last_error' => null,
            'last_category' => null,
        ]);
        $table = $this->makeTable();
        $table->rows = [$row];

        $gmail = $this->createMock(GmailService::class);
        $gmail->method('markAsRead')->willThrowException(
            new GmailApiException(GmailErrorCategory::RATE, 429, 'rate limited'),
        );

        $service = new MarkReadQueueService($table);
        $counters = $service->processPending($gmail);

        $this->assertSame(0, $counters['dropped']);
        $this->assertSame(1, $counters['failed']);
        $this->assertSame(2, $row->attempts);
        $this->assertCount(1, $table->rows);
    }

    public function testProcessPendingDropsAfterMaxAttempts(): void
    {
        $row = new Entity([
            'id' => 1,
            'gmail_message_id' => 'msg-1',
            'attempts' => 2,
            'last_error' => null,
            'last_category' => null,
        ]);
        $table = $this->makeTable();
        $table->rows = [$row];

        $gmail = $this->createMock(GmailService::class);
        $gmail->method('markAsRead')->willThrowException(
            new GmailApiException(GmailErrorCategory::TRANSIENT, 503, 'still failing'),
        );

        $service = new MarkReadQueueService($table);
        $counters = $service->processPending($gmail);

        // attempts was 2; this run increments to 3 (MAX_ATTEMPTS), so drop.
        $this->assertSame(1, $counters['dropped']);
        $this->assertSame(0, $counters['failed']);
        $this->assertCount(0, $table->rows);
    }

    public function testProcessPendingHonorsBatchSize(): void
    {
        $rows = [];
        for ($i = 1; $i <= 5; $i++) {
            $rows[] = [
                'gmail_message_id' => "msg-{$i}",
                'attempts' => 0,
                'last_error' => null,
                'last_category' => null,
            ];
        }
        $table = $this->makeTable($rows);

        $gmail = $this->createMock(GmailService::class);
        $gmail->expects($this->exactly(2))->method('markAsRead')->willReturn(true);

        $service = new MarkReadQueueService($table);
        $counters = $service->processPending($gmail, batch: 2);

        $this->assertSame(2, $counters['processed']);
        $this->assertCount(3, $table->rows);
    }
}
