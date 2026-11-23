<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\Health\ReadinessProbe;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/health: iş yapabiliyor mu (veritabanı, kuyruk, zamanlayıcı, disk). /api/ping canlılıktır.
 * Gövde yalnızca durum + başarısız kontrolün adı. Ayrıntı: docs/rehber/app/Support/Health/ReadinessProbe.md
 */
final class ReadinessController extends Controller
{
    public function __invoke(ReadinessProbe $probe): JsonResponse
    {
        $failed = $probe->failures();

        $response = $failed === []
            ? response()->json(['status' => 'ok'])
            : response()->json(['status' => 'fail', 'failed' => $failed], 503);

        return $response->header('Cache-Control', 'no-store');
    }
}
