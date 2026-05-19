<?php
declare(strict_types=1);

namespace App\Service\Gmail;

use App\Service\Exception\GmailApiException;
use App\Service\GmailService;
use Cake\Datasource\EntityInterface;
use Cake\Log\Log;
use Cake\ORM\Entity;
use Cake\ORM\Table;

/**
 * Retry queue for Gmail::markAsRead failures.
 *
 * Drained at the start of every GmailImportService::run(). Failures during
 * the run are enqueued; success/permanent failures delete the row; transient
 * failures increment attempts up to MAX_ATTEMPTS, then drop with a log.
 *
 * Takes the Table as a constructor arg (rather than using LocatorAwareTrait)
 * so unit tests can drive the service with an anonymous-class test double
 * without booting the CakePHP ORM fixture machinery.
 */
final class MarkReadQueueService
{
    public const MAX_ATTEMPTS = 3;
    public const DEFAULT_BATCH = 20;

    public function __construct(private readonly Table $table)
    {
    }

    public function enqueue(string $gmailMessageId, ?string $error, string $category): void
    {
        $existing = $this->findByMessageId($gmailMessageId);
        if ($existing !== null) {
            $existing->attempts = ((int)($existing->attempts ?? 0)) + 1;
            $existing->last_error = $this->truncateError($error);
            $existing->last_category = $category;
            $this->table->saveOrFail($existing);

            return;
        }

        // The seam (property_exists 'rows') distinguishes the test anonymous
        // Table from a real Cake ORM Table. Real Tables go through newEntity()
        // so persistence wires the entity to the source. Tests pass a bare
        // Entity instance (its anonymous Table override accepts it directly).
        $row = property_exists($this->table, 'rows')
            ? new Entity()
            : $this->table->newEntity([]);
        $row->gmail_message_id = $gmailMessageId;
        $row->attempts = 1;
        $row->last_error = $this->truncateError($error);
        $row->last_category = $category;
        $this->table->saveOrFail($row);
    }

    /**
     * @return array{processed:int, retried:int, failed:int, dropped:int}
     */
    public function processPending(GmailService $gmail, int $batch = self::DEFAULT_BATCH): array
    {
        $rows = $this->selectPending($batch);

        $processed = 0;
        $retried = 0;
        $failed = 0;
        $dropped = 0;

        foreach ($rows as $row) {
            try {
                $gmail->markAsRead($row->gmail_message_id);
                $this->table->delete($row);
                $processed++;
                $retried++;
            } catch (GmailApiException $e) {
                if ($e->getCategory() === GmailErrorCategory::PERMANENT) {
                    $this->table->delete($row);
                    $dropped++;
                    Log::info('Gmail mark-read dropped (permanent)', [
                        'message_id' => $row->gmail_message_id,
                        'error' => $e->getMessage(),
                    ]);
                    continue;
                }

                $row->attempts = ((int)($row->attempts ?? 0)) + 1;
                $row->last_error = $this->truncateError($e->getMessage());
                $row->last_category = $e->getCategory();
                if ($row->attempts >= self::MAX_ATTEMPTS) {
                    $this->table->delete($row);
                    $dropped++;
                    Log::warning('Gmail mark-read dropped after max attempts', [
                        'message_id' => $row->gmail_message_id,
                        'attempts' => $row->attempts,
                    ]);
                } else {
                    $this->table->saveOrFail($row);
                    $failed++;
                }
            }
        }

        return ['processed' => $processed, 'retried' => $retried, 'failed' => $failed, 'dropped' => $dropped];
    }

    private function findByMessageId(string $gmailMessageId): ?object
    {
        // Production path uses ORM; the test-only anonymous Table holds rows in a public array.
        if (property_exists($this->table, 'rows')) {
            foreach ($this->table->rows as $row) {
                if (($row->gmail_message_id ?? null) === $gmailMessageId) {
                    return $row;
                }
            }

            return null;
        }
        $found = $this->table->find()->where(['gmail_message_id' => $gmailMessageId])->first();

        return $found instanceof EntityInterface ? $found : null;
    }

    /**
     * @return list<object>
     */
    private function selectPending(int $batch): array
    {
        if (method_exists($this->table, 'selectAllForTest')) {
            return array_slice($this->table->selectAllForTest(), 0, $batch);
        }
        $iter = $this->table->find()
            ->order(['created' => 'ASC'])
            ->limit($batch)
            ->all();
        $rows = [];
        foreach ($iter as $entity) {
            $rows[] = $entity;
        }

        return $rows;
    }

    private function truncateError(?string $error): ?string
    {
        if ($error === null) {
            return null;
        }

        return mb_substr($error, 0, 255);
    }
}
