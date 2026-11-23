<?php

declare(strict_types=1);

namespace App\Support\Health;

use Illuminate\Support\Facades\Cache;

/**
 * Dakikada bir zamanlayıcıdan çağrılır; `SchedulerHeartbeatCheck` bu damgayı okur.
 * Ayrıntılı açıklama: docs/rehber/app/Providers/MonitoringServiceProvider.md
 */
final class RecordSchedulerHeartbeat
{
    public const string CACHE_KEY = 'health:scheduler-heartbeat';

    public function __invoke(): void
    {
        Cache::forever(self::CACHE_KEY, now()->getTimestamp());
    }
}
