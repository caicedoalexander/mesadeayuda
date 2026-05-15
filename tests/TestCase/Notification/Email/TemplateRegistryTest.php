<?php
declare(strict_types=1);

namespace App\Test\TestCase\Notification\Email;

use App\Notification\Email\TemplateRegistry;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class TemplateRegistryTest extends TestCase
{
    public function testResolvesAllFourTicketKeys(): void
    {
        $registry = new TemplateRegistry();

        self::assertSame('ticket_created', $registry->get('ticket_created')->key());
        self::assertSame('ticket_status_changed', $registry->get('ticket_status_changed')->key());
        self::assertSame('ticket_comment_added', $registry->get('ticket_comment_added')->key());
        self::assertSame('ticket_updated', $registry->get('ticket_updated')->key());
    }

    public function testAllReturnsFourTemplates(): void
    {
        $registry = new TemplateRegistry();
        self::assertCount(4, $registry->all());
    }

    public function testUnknownKeyThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new TemplateRegistry())->get('does_not_exist');
    }
}
