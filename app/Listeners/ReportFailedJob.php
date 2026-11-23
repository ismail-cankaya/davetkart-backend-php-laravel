<?php

declare(strict_types=1);

namespace App\Listeners;

use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Log;

/**
 * Denemeleri biten iş: `critical` (Sentry kanalının eşiği). Yalnızca iş adı ve istisna SINIFI;
 * yük ve istisna metni kişisel veri taşıyabilir. Ayrıntı: docs/rehber/app/Listeners/ReportFailedJob.md
 */
final class ReportFailedJob
{
    public function handle(JobFailed $event): void
    {
        Log::critical('Queue job failed.', [
            'job' => $event->job->resolveName(),
            'queue' => $event->job->getQueue(),
            'connection' => $event->connectionName,
            'attempts' => $event->job->attempts(),
            'exception' => $event->exception::class,
        ]);
    }
}
