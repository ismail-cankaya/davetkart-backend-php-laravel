<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\DiskUsage;
use App\Support\Health\RecordSchedulerHeartbeat;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Faz 19.7 (OPS-03): GET /api/health -- canlilik degil hazir olma. Her kontrol tek basina dusurulur.
 * Ayrintili aciklama: docs/rehber/tests/Feature/ReadinessTest.md
 */
final class ReadinessTest extends TestCase
{
    use RefreshDatabase;

    private const ALLOWED_NAMES = ['database', 'queue', 'scheduler', 'disk'];

    private string $originalConnection = '';

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('queue.default', 'database');
        Config::set('queue.connections.database.connection', null);
        $this->diskAt(50.0);
        (new RecordSchedulerHeartbeat)();
    }

    #[Test]
    public function readiness_is_ok_when_every_check_passes(): void
    {
        $this->getJson(route('health.ready'))
            ->assertOk()
            ->assertExactJson(['status' => 'ok'])
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    #[Test]
    public function readiness_fails_when_the_database_is_unreachable(): void
    {
        Config::set('queue.default', 'sync');

        $this->breakDatabaseName();
        $response = $this->getJson(route('health.ready'));
        $this->restoreDatabaseName();

        $response->assertStatus(503)
            ->assertExactJson(['status' => 'fail', 'failed' => ['database']]);
    }

    #[Test]
    public function readiness_fails_when_the_oldest_job_is_too_old(): void
    {
        $this->pushJob(minutesAgo: 9);
        $this->getJson(route('health.ready'))->assertOk();

        $this->pushJob(minutesAgo: 11);
        $this->getJson(route('health.ready'))
            ->assertStatus(503)
            ->assertExactJson(['status' => 'fail', 'failed' => ['queue']]);
    }

    #[Test]
    public function a_delayed_or_reserved_job_does_not_count_as_waiting(): void
    {
        $this->pushJob(minutesAgo: -30);
        $this->pushJob(minutesAgo: 60, reserved: true);

        $this->getJson(route('health.ready'))->assertOk();
    }

    #[Test]
    public function readiness_fails_when_the_scheduler_is_silent(): void
    {
        Cache::forget(RecordSchedulerHeartbeat::CACHE_KEY);
        $this->getJson(route('health.ready'))
            ->assertStatus(503)
            ->assertExactJson(['status' => 'fail', 'failed' => ['scheduler']]);

        (new RecordSchedulerHeartbeat)();
        $this->travel(6)->minutes();
        $this->getJson(route('health.ready'))
            ->assertStatus(503)
            ->assertExactJson(['status' => 'fail', 'failed' => ['scheduler']]);
    }

    #[Test]
    public function readiness_fails_when_the_disk_is_almost_full(): void
    {
        $this->diskAt(89.9);
        $this->getJson(route('health.ready'))->assertOk();

        $this->diskAt(90.0);
        $this->getJson(route('health.ready'))
            ->assertStatus(503)
            ->assertExactJson(['status' => 'fail', 'failed' => ['disk']]);
    }

    #[Test]
    public function a_check_that_throws_counts_as_failed(): void
    {
        $this->app->instance(DiskUsage::class, new class implements DiskUsage
        {
            public function usedPercent(): float
            {
                throw new RuntimeException('/var/www/storage okunamadi');
            }
        });

        $this->getJson(route('health.ready'))
            ->assertStatus(503)
            ->assertExactJson(['status' => 'fail', 'failed' => ['disk']]);
    }

    #[Test]
    public function the_health_response_leaks_no_details(): void
    {
        Cache::forget(RecordSchedulerHeartbeat::CACHE_KEY);
        $this->diskAt(99.0);

        $this->breakDatabaseName();
        $response = $this->getJson(route('health.ready'));
        $this->restoreDatabaseName();
        $response->assertStatus(503);

        $body = $response->json();
        $this->assertIsArray($body);
        $this->assertSame(['status', 'failed'], array_keys($body));
        $this->assertIsArray($body['failed']);
        $this->assertSame([], array_diff($body['failed'], self::ALLOWED_NAMES));
        $this->assertStringNotContainsString('olmayan-baglanti', (string) $response->getContent());
    }

    #[Test]
    public function the_scheduler_records_a_heartbeat_every_minute(): void
    {
        $this->freezeTime();
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event): bool => $event instanceof CallbackEvent && $event->description === 'health-heartbeat');

        $this->assertNotNull($event);
        $this->assertSame('* * * * *', $event->expression);

        Cache::forget(RecordSchedulerHeartbeat::CACHE_KEY);
        $event->run($this->app);
        $this->assertSame(now()->getTimestamp(), Cache::get(RecordSchedulerHeartbeat::CACHE_KEY));
    }

    #[Test]
    public function a_failed_job_is_reported(): void
    {
        $logger = Log::spy();
        $job = Mockery::mock(Job::class, [
            'resolveName' => 'App\Jobs\SendSomething',
            'getQueue' => 'default',
            'attempts' => 3,
        ]);

        event(new JobFailed('database', $job, new RuntimeException('ayse@example.com icin gonderilemedi')));

        $logger->shouldHaveReceived('critical', ['Queue job failed.', [
            'job' => 'App\Jobs\SendSomething',
            'queue' => 'default',
            'connection' => 'database',
            'attempts' => 3,
            'exception' => RuntimeException::class,
        ]]);
    }

    /**
     * Varsayilan baglanti adini yalnizca istek boyunca bozar. Geri konmazsa RefreshDatabase'in
     * test sonundaki geri alma adimi da ayni ada baglanmaya calisir.
     */
    private function breakDatabaseName(): void
    {
        $this->originalConnection = Config::string('database.default');
        Config::set('database.default', 'olmayan-baglanti');
    }

    private function restoreDatabaseName(): void
    {
        Config::set('database.default', $this->originalConnection);
    }

    private function diskAt(float $percent): void
    {
        $this->app->instance(DiskUsage::class, new readonly class($percent) implements DiskUsage
        {
            public function __construct(private float $percent) {}

            public function usedPercent(): float
            {
                return $this->percent;
            }
        });
    }

    private function pushJob(int $minutesAgo, bool $reserved = false): void
    {
        $at = now()->subMinutes($minutesAgo)->getTimestamp();
        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => $reserved ? $at : null,
            'available_at' => $at,
            'created_at' => $at,
        ]);
    }
}
