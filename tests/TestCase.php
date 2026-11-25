<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

abstract class TestCase extends BaseTestCase
{
    /**
     * 🔴 Faz 10 (10.17): sahtesi kurulmamis HTTP cagrisi aga CIKAMAZ,
     * StrayRequestException firlatir.
     *
     * Bugun disari cikan tek istemci GeminiProvider (Http facade'i) ve
     * AssistantTest her testinde Http::fake() kuruyor. Koruma yarini icin:
     * sahteyi unutan bir test gercek API'ye (ve gercek anahtarla, faturaya)
     * gitmek yerine kirmizi yanar. Http::fake() kuran testler etkilenmez.
     *
     * Kapsami yalnizca Laravel'in Http istemcisi (B6): Sentry SDK'nin kendi
     * tasiyicisi, ham Guzzle ya da file_get_contents bunu GORMEZ.
     * Ayrintili aciklama: docs/rehber/tests/TestCase.md §8
     */
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        // Faz 13 (K130): ilk yayın indirimi KAPALI başlar. Faz 7–12 testleri
        // "yeni kullanıcı liste fiyatını öder" dünyasında yazıldı; o dünyada
        // %60 her checkout'un tutarını değiştirirdi. İndirimi sınayan test
        // onu kendisi açar (QuotePriceActionTest, FirstPublishCheckoutTest).
        // Ayrıntılı açıklama: docs/rehber/tests/TestCase.md §9
        Config::set('davetkart.pricing.first_publish_percent', 0);

        // K233 (B-E1): silmeler RecordDeletion ile günlüğe yazılır; testte ayrı, sahte bir disk.
        Config::set('deletion_log.disk', 'deletion-log-test');
        Storage::fake('deletion-log-test');
    }

    /**
     * Kimlik onbellegini bosaltir — ayni test metodunda ikinci bir kimlikli
     * istek yapilmadan ONCE cagrilir.
     *
     * 🔴 T13: Illuminate\Auth\RequestGuard cozdugu kullaniciyi ozellikte tutar
     * ve setRequest() onu TEMIZLEMEZ. Laravel'in test altyapisi de guard'lari
     * sifirlamaz. Cagrilmazsa ikinci istek token'a hic bakmadan ilk kullaniciyi
     * doner — iptal edilmis token gecerli, baskasinin token'i "sahibin" gorunur.
     * Ayrintili aciklama: docs/rehber/tests/TestCase.md
     */
    protected function forgetAuthState(): void
    {
        $this->app['auth']->forgetGuards();
    }
}
