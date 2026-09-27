<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

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
