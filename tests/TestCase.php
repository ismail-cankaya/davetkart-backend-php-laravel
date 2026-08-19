<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
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
