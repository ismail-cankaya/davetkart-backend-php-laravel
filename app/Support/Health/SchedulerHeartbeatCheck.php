<?php

declare(strict_types=1);

namespace App\Support\Health;

use App\Contracts\HealthCheck;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;

/** Zamanlayıcının dakikalık kalp atışı (`RecordSchedulerHeartbeat`) taze mi. */
final class SchedulerHeartbeatCheck implements HealthCheck
{
    public function name(): string
    {
        return 'scheduler';
    }

    public function passes(): bool
    {
        $last = Cache::get(RecordSchedulerHeartbeat::CACHE_KEY);

        return is_int($last)
            && now()->getTimestamp() - $last <= Config::integer('health.scheduler_max_silence_minutes') * 60;
    }
}
