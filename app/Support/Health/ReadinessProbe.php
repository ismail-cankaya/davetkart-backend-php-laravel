<?php

declare(strict_types=1);

namespace App\Support\Health;

use App\Contracts\HealthCheck;
use Throwable;

/**
 * Kontrolleri toplar (Composite); hangisinin başarısız olduğunu yalnızca adıyla söyler.
 * Fırlatan kontrol başarısızdır (fail-closed). Ayrıntı: docs/rehber/app/Support/Health/ReadinessProbe.md
 */
final readonly class ReadinessProbe
{
    /**
     * @param  list<HealthCheck>  $checks
     */
    public function __construct(private array $checks) {}

    /**
     * Başarısız kontrollerin adları; boş liste = hazır.
     *
     * @return list<string>
     */
    public function failures(): array
    {
        $failed = [];
        foreach ($this->checks as $check) {
            try {
                $ok = $check->passes();
            } catch (Throwable) {
                $ok = false;
            }

            if (! $ok) {
                $failed[] = $check->name();
            }
        }

        return $failed;
    }
}
