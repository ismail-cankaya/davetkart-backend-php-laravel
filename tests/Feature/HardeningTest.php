<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Faz 9'un tarayici sertlestirmesi: CORS ve guvenlik basliklari.
 *
 * 🔴 Bu dosya, Faz 9'un "composer check bu fazi goremez" kuralinin ISTISNASI.
 * CORS ve baslik eklemek gercekten test edilebilir seylerdir — Redis'e,
 * S3'e ve gercek kuyruga gecis degildir. Test edilebilen her seyi test
 * etmeden elle dogrulamaya birakmak, elle dogrulamayi degersizlestirir.
 * Ayrintili aciklama: docs/rehber/tests/Feature/HardeningTest.md
 */
final class HardeningTest extends TestCase
{
    private const PROBE = '/api/ping';

    // ------------------------------------------------------------ CORS (9.12)

    #[Test]
    public function an_allowed_origin_receives_the_cors_header(): void
    {
        $origin = $this->firstAllowedOrigin();

        $this->withHeader('Origin', $origin)
            ->getJson(self::PROBE)
            ->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', $origin);
    }

    /** 🔴 Varsayilan `['*']` olsaydi bu test gecmezdi — koruma tam burada. */
    #[Test]
    public function an_unknown_origin_receives_no_cors_header(): void
    {
        $this->withHeader('Origin', 'https://kotu-site.example')
            ->getJson(self::PROBE)
            ->assertOk()
            ->assertHeaderMissing('Access-Control-Allow-Origin');
    }

    /**
     * 🔴 BU TESTIN KORUDUGU SEY FAZ 4 VE FAZ 5'TIR.
     *
     * ETag CORS'un "guvenli liste" basliklarindan degildir: capraz kaynakta
     * JavaScript onu OKUYAMAZ. Okunamazsa `If-None-Match` gonderilemez, 304
     * hic gelmez ve K7/K46'nin polling optimizasyonu sessizce olur.
     */
    #[Test]
    public function the_etag_header_is_exposed_to_cross_origin_readers(): void
    {
        $response = $this->withHeader('Origin', $this->firstAllowedOrigin())
            ->getJson(self::PROBE)
            ->assertOk();

        $this->assertStringContainsStringIgnoringCase(
            'ETag',
            (string) $response->headers->get('Access-Control-Expose-Headers'),
        );
    }

    /** Token modundayiz: cerez gonderme izni ACILMAMALI. */
    #[Test]
    public function credentials_are_not_allowed(): void
    {
        $this->assertFalse(Config::boolean('cors.supports_credentials'));
    }

    // ----------------------------------------------- GUVENLIK BASLIKLARI (9.13)

    #[Test]
    public function every_response_carries_the_hardening_headers(): void
    {
        $this->getJson(self::PROBE)
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('X-XSS-Protection', '0');
    }

    /** Saf JSON API: hicbir kaynak yuklenmemeli, hicbir yere gomulmemeli. */
    #[Test]
    public function the_content_security_policy_denies_everything(): void
    {
        $policy = (string) $this->getJson(self::PROBE)->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("default-src 'none'", $policy);
        $this->assertStringContainsString("frame-ancestors 'none'", $policy);
    }

    /**
     * 🔴 Basliklar HATA yanitlarinda da olmali.
     *
     * Global yigina eklenmelerinin sebebi bu: 'api' grubuna eklenseydi rota
     * ESLESMEYEN istekler (Faz 2, ders 21: grup middleware'i hic calismaz)
     * basliksiz donerdi ve 404 govdesi bir iframe'e gomulebilirdi.
     */
    #[Test]
    public function error_responses_carry_the_headers_too(): void
    {
        $this->getJson('/api/boyle-bir-yol-yok')
            ->assertNotFound()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY');
    }

    /**
     * HSTS yalnizca HTTPS uzerinden gonderilir.
     *
     * Testler http uzerinden kosar; basligin BURADA OLMAMASI dogru davranistir
     * (T6: bir davranisin hem varligi hem yoklugu test edilir). Varliginin
     * kaniti yalnizca gercek bir https istegiyle alinabilir — elle dogrulama.
     */
    #[Test]
    public function hsts_is_absent_over_plain_http(): void
    {
        $this->getJson(self::PROBE)
            ->assertOk()
            ->assertHeaderMissing('Strict-Transport-Security');
    }

    private function firstAllowedOrigin(): string
    {
        /** @var list<string> $origins */
        $origins = Config::array('cors.allowed_origins');

        $this->assertNotEmpty($origins, 'cors.allowed_origins bos olamaz.');

        return $origins[0];
    }
}
