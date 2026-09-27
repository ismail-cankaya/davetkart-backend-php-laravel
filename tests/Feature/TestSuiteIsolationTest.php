<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Sentry\SentrySdk;
use Tests\TestCase;

/**
 * Test paketi dis dunyaya ULASAMAZ (Faz 10, 10.17).
 *
 * Bu dosya bir ozelligi degil, test altyapisinin iki GARANTISINI sinar:
 *   1. Hicbir test Sentry'ye olay gondermez  (phpunit.xml)
 *   2. Hicbir test sahtesi kurulmamis bir HTTP cagrisi yapamaz (TestCase)
 *
 * Ikisi de bugun "dogru" — gelistiricinin .env'inde DSN yok, sahte
 * saglayici baglamayi unutan test yok. Bu dosya yarini korur: biri .env'ine
 * gercek bir DSN yazdiginda ya da AssistantTest'e yeni bir test eklerken
 * Http::fake()'i unuttugunda.
 * Ayrintili aciklama: docs/rehber/tests/Feature/TestSuiteIsolationTest.md
 */
final class TestSuiteIsolationTest extends TestCase
{
    /**
     * 🔴 Guvence "istemci yok" DEGIL: DSN bosken de bir Sentry\Client kurulur
     * (10.17'de denendi). Guvence, istemcinin DSN'inin null olmasi:
     * HttpTransport::send() o durumda olayi ATLAR ("Skipping …, because no
     * DSN is set").
     *
     * Iki iddia, iki kaynak: config (bizim anahtarimiz) ve SDK'nin kendi
     * varsayilani ($_SERVER['SENTRY_DSN']). Ikincisi config'i hic okumaz.
     */
    #[Test]
    public function the_test_suite_has_no_sentry_dsn(): void
    {
        $this->assertEmpty(Config::get('sentry.dsn'));
        $this->assertNull(SentrySdk::getCurrentHub()->getClient()?->getOptions()->getDsn());
    }

    /** Sahtesi kurulmamis bir cagri aga degil HATAYA duser. */
    #[Test]
    public function an_http_call_without_a_fake_fails_instead_of_reaching_the_network(): void
    {
        $this->expectException(StrayRequestException::class);

        Http::get('https://example.invalid/');
    }

    /**
     * T6'nin varlik yarisi: koruma sahte kurulmus cagriyi ENGELLEMEZ.
     * Yoksa AssistantTest'in Http::fake() kuran testleri birden kirilirdi ve koruma kaldirilirdi.
     */
    #[Test]
    public function a_faked_http_call_still_works(): void
    {
        Http::fake(['*' => Http::response('tamam')]);

        $this->assertSame('tamam', Http::get('https://example.invalid/')->body());
    }
}
