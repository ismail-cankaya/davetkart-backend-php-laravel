<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Her isteğe kimlik (OPS-08, K268): gelen `X-Request-Id` yalnızca ULID/UUID ise kalır, yoksa yeni ULID.
 * Kimlik `Context`'e girer (günlük satırları, kuyruk işleri) ve yanıta başlık olarak döner. Ayrıntılı açıklama: docs/rehber/app/Http/Middleware/AssignRequestId.md
 */
final class AssignRequestId
{
    public const string HEADER = 'X-Request-Id';

    public const string CONTEXT_KEY = 'request_id';

    public function handle(Request $request, Closure $next): Response
    {
        $id = self::accepted($request->headers->get(self::HEADER)) ?? (string) Str::ulid();

        Context::add(self::CONTEXT_KEY, $id);

        /** @var Response $response */
        $response = $next($request);
        $response->headers->set(self::HEADER, $id);

        return $response;
    }

    /** Biçim uzunluğu da sınırlar: ULID 26, UUID 36 karakter (§A-2'deki "≤64" kendiliğinden sağlanır). */
    private static function accepted(?string $incoming): ?string
    {
        return $incoming !== null && (Str::isUlid($incoming) || Str::isUuid($incoming)) ? $incoming : null;
    }
}
