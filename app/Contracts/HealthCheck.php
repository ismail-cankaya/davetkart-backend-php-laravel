<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * Hazır olma (readiness) kontrollerinden biri. Fırlatırsa başarısız sayılır.
 * Ayrıntılı açıklama: docs/rehber/app/Support/Health/ReadinessProbe.md
 */
interface HealthCheck
{
    /** Yanıtta görünen tek bilgi: kontrolün adı (değer, yol, hata metni değil). */
    public function name(): string;

    public function passes(): bool;
}
