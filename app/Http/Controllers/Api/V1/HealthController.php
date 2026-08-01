<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Sozlesme sagligi sondasi: API katmani ayakta ve JSON konusuyor mu?
 *
 * Tek eylemi oldugu icin invokable (__invoke). Closure yerine sinif olmasinin
 * sebebi route:cache — closure'lar serilestirilemez (Faz 9).
 * Ayrintili aciklama: docs/rehber/app/Http/Controllers/Api/V1/HealthController.md
 */
final class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json(['status' => 'ok']);
    }
}
