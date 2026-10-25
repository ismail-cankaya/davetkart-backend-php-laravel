<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\DeviceLabel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Kaba cihaz etiketi (Faz 14, 14.9): Edge/Opera Chrome değil, Chrome Safari değil. */
final class DeviceLabelTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function agents(): iterable
    {
        yield 'Chrome Windows' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36', 'Chrome', 'Windows'];
        yield 'Edge Windows' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36 Edg/130.0', 'Edge', 'Windows'];
        yield 'Safari iPhone' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1', 'Safari', 'iOS'];
        yield 'Chrome Android' => ['Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Mobile Safari/537.36', 'Chrome', 'Android'];
        yield 'Firefox macOS' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 14.5; rv:131.0) Gecko/20100101 Firefox/131.0', 'Firefox', 'macOS'];
        yield 'Opera Linux' => ['Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36 OPR/115.0', 'Opera', 'Linux'];
    }

    #[Test]
    #[DataProvider('agents')]
    public function it_names_common_browsers_and_systems(string $agent, string $browser, string $system): void
    {
        $this->assertSame($browser, DeviceLabel::browser($agent));
        $this->assertSame($system, DeviceLabel::system($agent));
    }

    #[Test]
    public function an_unknown_or_missing_agent_is_null(): void
    {
        $this->assertNull(DeviceLabel::browser(null));
        $this->assertNull(DeviceLabel::system(''));
        $this->assertNull(DeviceLabel::browser('curl/8.0'));
    }
}
