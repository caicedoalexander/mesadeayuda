<?php
declare(strict_types=1);

namespace App\Test\TestCase\Notification\Email;

use App\Notification\Email\RenderedEmail;
use PHPUnit\Framework\TestCase;

final class RenderedEmailTest extends TestCase
{
    public function testExposesSubjectAndBodyHtml(): void
    {
        $email = new RenderedEmail('Subject line', '<p>Body</p>');

        self::assertSame('Subject line', $email->subject);
        self::assertSame('<p>Body</p>', $email->bodyHtml);
    }
}
