<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ErrorCode;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/**
 * Faz 1'in kaniti: API ayakta, JSON konusuyor ve hata zarfi sozlesmeye uyuyor.
 *
 * T5: metin degil DAVRANIS dogrulanir — kod, durum ve alan adi.
 * Ayrintili aciklama: docs/rehber/tests/Feature/HealthTest.md
 */
final class HealthTest extends TestCase
{
    #[Test]
    public function ping_endpoint_returns_ok(): void
    {
        $this->getJson(route('health.ping'))
            ->assertOk()
            ->assertExactJson(['status' => 'ok']);
    }

    #[Test]
    public function unknown_route_returns_error_envelope(): void
    {
        $this->getJson('/api/olmayan-rota')
            ->assertNotFound()
            ->assertJsonPath('error.code', ErrorCode::ResourceNotFound->value);
    }

    /** ForceJsonResponse'un tek isi: HTML isteyen istemciye de JSON dondurmek. */
    #[Test]
    public function html_request_to_api_still_receives_json(): void
    {
        $this->get('/api/olmayan-rota', ['Accept' => 'text/html'])
            ->assertNotFound()
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonPath('error.code', ErrorCode::ResourceNotFound->value);
    }

    /** H7: yanlis metot da 404 — rotanin varligini dogrulamaz. */
    #[Test]
    public function wrong_http_method_does_not_reveal_route_existence(): void
    {
        $this->postJson(route('health.ping'))
            ->assertNotFound()
            ->assertJsonPath('error.code', ErrorCode::ResourceNotFound->value);
    }

    /** 🔴 H3 SIZINTI TESTI: uretim kipinde debug blogu URETILMEZ. */
    #[Test]
    public function debug_block_is_absent_in_production_mode(): void
    {
        config(['app.debug' => false]);

        $this->getJson('/api/olmayan-rota')
            ->assertNotFound()
            ->assertJsonMissingPath('error.debug');
    }

    /** Ayni yol, yerel kipte: blok var — ama SADECE burada. */
    #[Test]
    public function debug_block_is_present_in_local_mode(): void
    {
        config(['app.debug' => true]);

        $this->getJson('/api/olmayan-rota')
            ->assertNotFound()
            ->assertJsonPath('error.debug.exception', NotFoundHttpException::class);
    }

    /** Kapsam siniri: ForceJsonResponse yalnizca 'api' grubunda kayitli. */
    #[Test]
    public function web_routes_are_not_forced_to_json(): void
    {
        $response = $this->get('/olmayan-web-rotasi', ['Accept' => 'text/html']);

        $response->assertNotFound();

        $this->assertStringContainsString(
            'text/html',
            (string) $response->headers->get('Content-Type'),
        );
    }
}
