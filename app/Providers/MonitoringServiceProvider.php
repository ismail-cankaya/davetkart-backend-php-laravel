<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\DiskUsage;
use App\Support\Health\DatabaseCheck;
use App\Support\Health\DiskSpaceCheck;
use App\Support\Health\LocalDiskUsage;
use App\Support\Health\QueueBacklogCheck;
use App\Support\Health\ReadinessProbe;
use App\Support\Health\SchedulerHeartbeatCheck;
use App\Support\Health\SchemaCheck;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * İzleme (Faz 19.7, OPS-03): hazır olma kontrolleri. Kalp atışı zamanlaması routes/console.php'de.
 * Ayrıntılı açıklama: docs/rehber/app/Providers/MonitoringServiceProvider.md
 */
final class MonitoringServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(DiskUsage::class, LocalDiskUsage::class);

        $this->app->bind(ReadinessProbe::class, fn (Application $app): ReadinessProbe => new ReadinessProbe([
            $app->make(DatabaseCheck::class),
            $app->make(SchemaCheck::class),
            $app->make(QueueBacklogCheck::class),
            $app->make(SchedulerHeartbeatCheck::class),
            $app->make(DiskSpaceCheck::class),
        ]));
    }
}
