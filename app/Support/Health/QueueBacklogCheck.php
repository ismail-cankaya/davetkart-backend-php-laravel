<?php

declare(strict_types=1);

namespace App\Support\Health;

use App\Contracts\HealthCheck;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * Bekleyen en eski işin yaşı eşiği aştı mı (işçi takıldı ya da hiç çalışmıyor).
 * Sürücü `database` değilse (testte `sync`) ölçülecek tablo yok: geçer.
 */
final class QueueBacklogCheck implements HealthCheck
{
    public function name(): string
    {
        return 'queue';
    }

    public function passes(): bool
    {
        $connection = Config::array('queue.connections.'.Config::string('queue.default'));
        if (($connection['driver'] ?? null) !== 'database') {
            return true;
        }

        $now = now()->getTimestamp();
        $oldest = DB::connection(is_string($connection['connection'] ?? null) ? $connection['connection'] : null)
            ->table(is_string($connection['table'] ?? null) ? $connection['table'] : 'jobs')
            ->whereNull('reserved_at')
            ->where('available_at', '<=', $now)
            ->min('available_at');

        return $oldest === null
            || $now - (int) $oldest <= Config::integer('health.queue_max_age_minutes') * 60;
    }
}
