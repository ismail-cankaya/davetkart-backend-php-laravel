<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * API istegini "JSON istiyorum" diye isaretler.
 *
 * Laravel yanit bicimine Accept basligina bakarak karar verir. Tarayici adres
 * cubugundan gelen istek "text/html" gonderir; onlem alinmazsa hata durumunda
 * JSON yerine HTML sayfasi doner ve yigin izi sizar.
 *
 * Yalnizca Accept degistirilir; Content-Type'a DOKUNULMAZ (bkz. kilavuz §3.3).
 * Ayrintili aciklama: docs/rehber/app/Http/Middleware/ForceJsonResponse.md
 */
final class ForceJsonResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }
}
