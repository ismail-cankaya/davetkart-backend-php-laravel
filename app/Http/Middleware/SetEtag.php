<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Yanit govdesinin parmak izini ETag olarak isler; istemcide ayni surum varsa
 * 304 Not Modified doner.
 *
 * Cache (4.3) VERITABANINA GITMEYI onler, bu katman GOVDEYI GONDERMEYI onler.
 * Ikisi birbirinin yerine gecmez, ust uste biner.
 * Ayrintili aciklama: docs/rehber/app/Http/Middleware/SetEtag.md
 */
final class SetEtag
{
    /**
     * ETag bir GUVENLIK ozeti degil, bir esitlik parmak izidir: tek sorulan
     * "govde degisti mi?". Kriptografik dayaniklilik gerekmez, hiz gerekir.
     */
    private const HASH_ALGORITHM = 'xxh128';

    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        // Yalnizca basarili OKUMALAR dogrulanabilir. 201/204/4xx/5xx'te ETag
        // anlamsizdir; istemcinin elinde saklayacagi bir surum yoktur.
        if (! $request->isMethodCacheable() || $response->getStatusCode() !== Response::HTTP_OK) {
            return $response;
        }

        $content = $response->getContent();

        // Akan (streamed) yanitin govdesi bellekte yok; ozetlenemez.
        if ($content === false) {
            return $response;
        }

        $response->setEtag(hash(self::HASH_ALGORITHM, $content));

        // Eslesirse: 304, govde bosaltilir, RFC 7232'nin yasakladigi basliklar
        // silinir. Karsilastirma mantigini ELLE yazmiyoruz (R6).
        $response->isNotModified($request);

        return $response;
    }
}
