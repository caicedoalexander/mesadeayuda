<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\Dto\SystemConfig;
use App\Service\TicketIngestionService;
use PHPUnit\Framework\TestCase;

final class TicketIngestionServiceTest extends TestCase
{
    /**
     * Regression guard for the 2026-05-16 notification refactor: the
     * constructor used to fall back to `new TicketNotificationService($config)`,
     * which after the refactor takes (array $strategies, array $channels) —
     * passing a SystemConfig as first arg throws TypeError. Every Gmail
     * ingest call went through this path because GmailImportService::fromSettings
     * never injects the optional dependency.
     */
    public function testConstructsWithoutOptionalDependencies(): void
    {
        $service = new TicketIngestionService(SystemConfig::empty());

        self::assertInstanceOf(TicketIngestionService::class, $service);
    }

    public function testConstructsWithNullConfig(): void
    {
        $service = new TicketIngestionService();

        self::assertInstanceOf(TicketIngestionService::class, $service);
    }
}
