<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Logger as LaravelLogger;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Faz 21.4 (OPS-08, K268): her yanıtta `X-Request-Id`; aynı kimlik o isteğin günlük satırlarında ve kuyruk işinde.
 * Ayrıntılı açıklama: docs/rehber/tests/Feature/RequestIdTest.md
 */
final class RequestIdTest extends TestCase
{
    use RefreshDatabase;

    private const ULID = '01JDQZ3K8G4T6M2N5P7R9S1V3W';

    #[Test]
    public function every_response_carries_a_fresh_request_id(): void
    {
        $ok = $this->getJson('/api/ping')->assertOk();
        $error = $this->getJson('/api/boyle-bir-uc-yok')->assertNotFound();
        $health = $this->get('/up');

        $ids = array_map(fn ($r): string => (string) $r->headers->get('X-Request-Id'), [$ok, $error, $health]);

        foreach ($ids as $id) {
            $this->assertTrue(Str::isUlid($id), "ULID değil: '{$id}'");
        }
        $this->assertCount(3, array_unique($ids));
    }

    /** Ara katman global zincirin EN BAŞINDA: ondan önce dönen bir yanıt (ValidatePostSize → 413) kimliksiz kalırdı. */
    #[Test]
    public function an_early_response_from_the_global_stack_carries_it_too(): void
    {
        $response = $this->call('POST', '/api/auth/login', server: ['CONTENT_LENGTH' => (string) PHP_INT_MAX, 'HTTP_ACCEPT' => 'application/json']);

        $response->assertStatus(413);
        $this->assertTrue(Str::isUlid((string) $response->headers->get('X-Request-Id')));
    }

    /** §A-1: kimlik yalnızca başlıkta; hata zarfı değişmez (K20). */
    #[Test]
    public function the_error_envelope_does_not_grow(): void
    {
        $this->getJson('/api/boyle-bir-uc-yok')
            ->assertNotFound()
            ->assertExactJson(['error' => ['code' => 'RESOURCE_NOT_FOUND']]);
    }

    /** @return array<string, array{string}> */
    public static function wellFormedIds(): array
    {
        return [
            'ULID' => [self::ULID],
            'UUID' => ['3f2504e0-4f89-41d3-9a0c-0305e82c3301'],
        ];
    }

    #[Test]
    #[DataProvider('wellFormedIds')]
    public function a_well_formed_incoming_id_is_kept(string $id): void
    {
        $this->getJson('/api/ping', ['X-Request-Id' => $id])->assertHeader('X-Request-Id', $id);
    }

    /** @return array<string, array{string}> */
    public static function malformedIds(): array
    {
        return [
            'serbest metin' => ['destek-talebi-42'],
            'boş' => [''],
            '64 karakterden uzun' => [str_repeat('a', 65)],
            'UUID + kuyruk' => ['3f2504e0-4f89-41d3-9a0c-0305e82c3301-x'],
            'HTML' => ['<script>alert(1)</script>'],
            'nginx $request_id (32 hex)' => ['4f1c2e7a9b0d3c5e6f708192a3b4c5d6'],
            'ULID, ilk hane 8 (taşar)' => ['81JDQZ3K8G4T6M2N5P7R9S1V3W'],
        ];
    }

    #[Test]
    #[DataProvider('malformedIds')]
    public function a_malformed_incoming_id_is_replaced(string $id): void
    {
        $returned = (string) $this->getJson('/api/ping', ['X-Request-Id' => $id])->headers->get('X-Request-Id');

        $this->assertNotSame($id, $returned);
        $this->assertTrue(Str::isUlid($returned));
    }

    #[Test]
    public function log_lines_written_during_the_request_carry_the_id(): void
    {
        Config::set('logging.channels.capture', ['driver' => 'monolog', 'handler' => TestHandler::class]);
        Route::get('/api/test-request-id-log', function (): array {
            Log::channel('capture')->info('deneme satırı');

            return ['seen' => Context::get('request_id')];
        });

        $response = $this->getJson('/api/test-request-id-log', ['X-Request-Id' => self::ULID])
            ->assertOk()
            ->assertJsonPath('seen', self::ULID);

        $channel = Log::channel('capture');
        $this->assertInstanceOf(LaravelLogger::class, $channel);
        $logger = $channel->getLogger();
        $this->assertInstanceOf(Logger::class, $logger);
        $handler = $logger->getHandlers()[0];
        $this->assertInstanceOf(TestHandler::class, $handler);
        $records = $handler->getRecords();

        $this->assertCount(1, $records);
        $this->assertSame(self::ULID, $records[0]->extra['request_id'] ?? null);
        $this->assertSame(self::ULID, $response->headers->get('X-Request-Id'));
    }

    /** Kuyruğa giden iş, onu doğuran isteğin kimliğini yükünde taşır (Laravel Context, işçide geri yüklenir). */
    #[Test]
    public function a_queued_job_carries_the_request_id(): void
    {
        Config::set('queue.default', 'database');

        $this->postJson(route('auth.register'), [
            'firstName' => 'Deniz',
            'lastName' => 'Kaya',
            'email' => 'deniz@ornek.test',
            'password' => 'uzun-ve-yaygin-olmayan-42',
        ], ['X-Request-Id' => self::ULID])->assertCreated();

        $payloads = DB::table('jobs')->pluck('payload');
        $this->assertNotEmpty($payloads, 'kayıt doğrulama postasını kuyruğa koymalıydı');

        foreach ($payloads as $raw) {
            $payload = json_decode((string) $raw, true, flags: JSON_THROW_ON_ERROR);
            $this->assertIsArray($payload);
            $serialized = $payload['illuminate:log:context']['data']['request_id'] ?? null;
            $this->assertIsString($serialized);
            $this->assertSame(self::ULID, unserialize($serialized, ['allowed_classes' => false]));
        }
    }
}
