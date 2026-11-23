<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * Diskin doluluk yüzdesi. Arayüz, testin gerçek diski doldurmadan "dolu disk" kurabilmesi için.
 * Ayrıntılı açıklama: docs/rehber/app/Support/Health/ReadinessProbe.md
 */
interface DiskUsage
{
    public function usedPercent(): float;
}
