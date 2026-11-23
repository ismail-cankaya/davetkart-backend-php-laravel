<?php

declare(strict_types=1);

namespace App\Support\Health;

use App\Contracts\DiskUsage;
use RuntimeException;

/** Gerçek ölçüm: `storage/`'ın bulunduğu bölüm (yüklemeler, loglar, cache orada büyür). */
final class LocalDiskUsage implements DiskUsage
{
    public function usedPercent(): float
    {
        $path = storage_path();
        $total = disk_total_space($path);
        $free = disk_free_space($path);

        if ($total === false || $free === false || $total <= 0) {
            throw new RuntimeException('Disk space could not be measured.');
        }

        return ($total - $free) / $total * 100;
    }
}
