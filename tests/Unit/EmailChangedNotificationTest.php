<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Notifications\EmailChangedNotification;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Eski adrese giden mailde yeni adres maskeli (Faz 14, 14.10). */
final class EmailChangedNotificationTest extends TestCase
{
    #[Test]
    public function the_new_address_is_masked(): void
    {
        $this->assertSame('y***@gmail.com', EmailChangedNotification::mask('yeni.adres@gmail.com'));
        $this->assertSame('İ***@example.com', EmailChangedNotification::mask('İsmail@example.com'));
        $this->assertSame('***', EmailChangedNotification::mask('bozuk'));
    }
}
