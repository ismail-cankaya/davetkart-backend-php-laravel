<?php

declare(strict_types=1);

namespace App\Support\Health;

use App\Contracts\DiskUsage;
use App\Contracts\HealthCheck;
use Illuminate\Support\Facades\Config;

/** `storage/` bölümünün doluluğu eşiğin altında mı. */
final readonly class DiskSpaceCheck implements HealthCheck
{
    public function __construct(private DiskUsage $disk) {}

    public function name(): string
    {
        return 'disk';
    }

    public function passes(): bool
    {
        return $this->disk->usedPercent() < Config::integer('health.disk_max_used_percent');
    }
}
