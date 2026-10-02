<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\IpBucket;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Faz 10 (10.60 · K104): hız sınırı kovasının IP anahtarı.
 * Ayrıntılı açıklama: docs/rehber/app/Support/IpBucket.md
 */
final class IpBucketTest extends TestCase
{
    /** @return array<string, array{?string, string}> */
    public static function addresses(): array
    {
        return [
            'IPv4 olduğu gibi' => ['78.180.45.12', '78.180.45.12'],
            'IPv6 /64 önekine iner' => ['2a02:ff0:3:1a2b:9c4d:11:22:33', '2a02:ff0:3:1a2b::/64'],
            'aynı /64 içindeki başka adres aynı kova' => ['2a02:ff0:3:1a2b:ffff:ffff:ffff:ffff', '2a02:ff0:3:1a2b::/64'],
            'açık yazım ile kısa yazım aynı kova' => ['2a02:0ff0:0003:1a2b:0000:0000:0000:0001', '2a02:ff0:3:1a2b::/64'],
            'IPv6 içine gömülü IPv4, IPv4 sayılır' => ['::ffff:78.180.45.12', '78.180.45.12'],
            'boş adres' => [null, 'bilinmeyen'],
            'geçersiz değer olduğu gibi' => ['bir-ip-degil', 'bir-ip-degil'],
        ];
    }

    #[Test]
    #[DataProvider('addresses')]
    public function it_derives_the_bucket_key(?string $ip, string $expected): void
    {
        $this->assertSame($expected, IpBucket::of($ip));
    }

    /** Komşu /64 ayrı bir hat: aynı kovaya düşmemeli. */
    #[Test]
    public function a_neighbouring_slash_64_is_a_different_bucket(): void
    {
        $this->assertNotSame(
            IpBucket::of('2a02:ff0:3:1a2b::1'),
            IpBucket::of('2a02:ff0:3:1a2c::1'),
        );
    }

    /** Gömülü IPv4'ler önek alınsaydı hepsi '::/64' kovasına düşerdi. */
    #[Test]
    public function embedded_ipv4_addresses_do_not_share_one_bucket(): void
    {
        $this->assertNotSame(
            IpBucket::of('::ffff:78.180.45.12'),
            IpBucket::of('::ffff:85.105.1.2'),
        );
    }
}
