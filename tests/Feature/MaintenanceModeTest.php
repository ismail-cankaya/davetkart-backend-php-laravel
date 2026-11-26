<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Faz 20.4 (OPS-06, K264): deploy bakim kipinde yapilir. API o sirada frontend'in anladigi zarfla 503 doner.
 * Ayrintili aciklama: docs/rehber/tests/Feature/MaintenanceModeTest.md
 */
final class MaintenanceModeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Dosya surucusu storage/framework/down yazardi: test cokerse yerel sunucu da bakimda kalirdi.
        Config::set('app.maintenance.driver', 'cache');
        Config::set('app.maintenance.store', 'array');
    }

    #[Test]
    public function the_api_answers_with_the_error_envelope_while_the_site_is_down(): void
    {
        $this->goDown();

        $this->getJson(route('health.ping'))
            ->assertStatus(503)
            ->assertExactJson(['error' => ['code' => 'PROVIDER_UNAVAILABLE']]);
    }

    #[Test]
    public function the_api_answers_normally_once_the_site_is_up_again(): void
    {
        $this->goDown();
        $this->app->maintenanceMode()->deactivate();

        $this->getJson(route('health.ping'))->assertOk();
    }

    /** `php artisan down --retry=60` ile ayni yuk (deploy.sh). */
    private function goDown(): void
    {
        Config::set('app.debug', false);
        $this->app->maintenanceMode()->activate([
            'except' => [],
            'redirect' => null,
            'retry' => 60,
            'refresh' => null,
            'secret' => null,
            'status' => 503,
            'template' => null,
        ]);
        $this->assertTrue($this->app->isDownForMaintenance());
    }
}
