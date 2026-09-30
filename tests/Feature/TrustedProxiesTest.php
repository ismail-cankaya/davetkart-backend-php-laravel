<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Guvenilen vekiller (Faz 10, 10.18): $request->ip() KIMI gosteriyor?
 *
 * Laravel'in global TrustProxies middleware'i `trustedproxy.proxies`
 * anahtarini her istekte okur; bu yuzden testler config()'i degistirip
 * dogrudan istek atabilir, statik durum kalmaz.
 *
 * Adresler RFC 5737 belgeleme araliklarindan (203.0.113.x, 198.51.100.x)
 * ve ozel agdan (10.x): hicbiri gercek bir makineye ait degil.
 * Ayrintili aciklama: docs/rehber/tests/Feature/TrustedProxiesTest.md
 */
final class TrustedProxiesTest extends TestCase
{
    use RefreshDatabase;

    /** Yuk dengeleyicinin (ALB) adresi: baglantiyi acan taraf. */
    private const PROXY = '10.0.0.5';

    /** Dengeleyicinin arkasindaki gercek ziyaretciler. */
    private const CLIENT_A = '203.0.113.9';

    private const CLIENT_B = '203.0.113.10';

    /** Dengeleyiciyi atlayip sunucuya DOGRUDAN baglanan biri. */
    private const OUTSIDER = '198.51.100.7';

    private const PROBE = '/_probe/ip';

    protected function setUp(): void
    {
        parent::setUp();

        // Yalnizca bu dosyanin gordugu yoklama rotasi: ip()'yi oldugu gibi doner.
        Route::get(self::PROBE, fn (Request $request): array => ['ip' => $request->ip()]);
    }

    // ------------------------------------------------------------ ip() ne doner?

    /**
     * 🔴 Guvenli varsayilan: hicbir vekile guvenilmiyor. Sahte bir
     * X-Forwarded-For hicbir sey degistirmez.
     */
    #[Test]
    public function forwarded_for_is_ignored_when_no_proxy_is_trusted(): void
    {
        $this->assertNull(Config::get('trustedproxy.proxies'));

        $this->probe(from: self::PROXY, forwardedFor: self::CLIENT_A)
            ->assertJsonPath('ip', self::PROXY);
    }

    #[Test]
    public function a_trusted_proxy_passes_the_client_ip_through(): void
    {
        Config::set('trustedproxy.proxies', self::PROXY);

        $this->probe(from: self::PROXY, forwardedFor: self::CLIENT_A)
            ->assertJsonPath('ip', self::CLIENT_A);
    }

    /**
     * 🔴 Guvenilen vekil tanimliyken bile, o vekil OLMAYAN biri basligi
     * uyduramaz. Tuzak #7'nin ters yuzu: '*' yazilsaydi bu test kirilirdi.
     */
    #[Test]
    public function a_caller_that_is_not_the_trusted_proxy_cannot_spoof_its_ip(): void
    {
        Config::set('trustedproxy.proxies', self::PROXY);

        $this->probe(from: self::OUTSIDER, forwardedFor: self::CLIENT_A)
            ->assertJsonPath('ip', self::OUTSIDER);
    }

    /** ALB'nin adresi sabit degil: gercek yapilandirma bir CIDR araligidir. */
    #[Test]
    public function a_cidr_range_can_be_trusted(): void
    {
        Config::set('trustedproxy.proxies', '10.0.0.0/8, 172.16.0.0/12');

        $this->probe(from: self::PROXY, forwardedFor: self::CLIENT_A)
            ->assertJsonPath('ip', self::CLIENT_A);
    }

    /**
     * Ortam degiskeni -> config anahtari bagi. Yukaridaki testler anahtari
     * Config::set() ile KENDILERI yaziyor; config dosyasi silinse de yesil
     * kalirlardi (10.18'de denendi). Uretimde TRUSTED_PROXIES'i middleware'e
     * tasiyan TEK sey bu dosya.
     *
     * env() $_SERVER'i her cagrida yeniden okur; dosya dogrudan require
     * ediliyor, boylece yuklu config'e dokunulmuyor.
     */
    #[Test]
    public function the_env_variable_reaches_the_config_key_and_empty_means_nobody(): void
    {
        $this->assertSame('10.0.0.0/8', $this->configWithEnv('10.0.0.0/8')['proxies']);
        $this->assertNull($this->configWithEnv('')['proxies']);
    }

    // ------------------------------------------- neden onemli: hiz siniri kovalari

    /**
     * 🔴 Raporun (§2.3) sorunu, birebir: dengeleyici guvenilir degilse
     * arkasindaki BUTUN ziyaretciler ayni kovayi paylasir. A'nin bes yanlis
     * denemesi, B'yi (baska bir insan) kilitler.
     */
    #[Test]
    public function without_trust_every_client_behind_the_proxy_shares_one_bucket(): void
    {
        $this->exhaustLoginBucket(forwardedFor: self::CLIENT_A);

        $this->failedLogin(forwardedFor: self::CLIENT_B)->assertStatus(429);
    }

    /** Ayni senaryo, vekil guvenilir: B'nin kovasi bos. */
    #[Test]
    public function with_trust_each_client_gets_its_own_bucket(): void
    {
        Config::set('trustedproxy.proxies', self::PROXY);

        $this->exhaustLoginBucket(forwardedFor: self::CLIENT_A);

        $this->failedLogin(forwardedFor: self::CLIENT_A)->assertStatus(429);
        $this->failedLogin(forwardedFor: self::CLIENT_B)->assertUnauthorized();
    }

    /**
     * @return TestResponse<Response>
     */
    private function probe(string $from, string $forwardedFor): TestResponse
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $from])
            ->withHeader('X-Forwarded-For', $forwardedFor)
            ->getJson(self::PROBE)
            ->assertOk();
    }

    /**
     * @return array<string, mixed>
     */
    private function configWithEnv(string $value): array
    {
        $_SERVER['TRUSTED_PROXIES'] = $value;

        try {
            /** @var array<string, mixed> $config */
            $config = require config_path('trustedproxy.php');
        } finally {
            unset($_SERVER['TRUSTED_PROXIES']);
        }

        return $config;
    }

    /** Auth kovasi: e-posta + IP basina dakikada 5 (K36). */
    private function exhaustLoginBucket(string $forwardedFor): void
    {
        foreach (range(1, 5) as $ignored) {
            $this->failedLogin($forwardedFor)->assertUnauthorized();
        }
    }

    /**
     * Istek her zaman DENGELEYICIDEN gelir; ziyaretciyi yalnizca baslik soyler.
     *
     * @return TestResponse<Response>
     */
    private function failedLogin(string $forwardedFor): TestResponse
    {
        return $this->withServerVariables(['REMOTE_ADDR' => self::PROXY])
            ->withHeader('X-Forwarded-For', $forwardedFor)
            ->postJson(route('auth.login'), [
                'email' => 'ayse@ornek.test',
                'password' => 'yanlis-parola',
            ]);
    }
}
