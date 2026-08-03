<?php

declare(strict_types=1);

use App\Exceptions\ApiExceptionRenderer;
use App\Http\Middleware\ForceJsonResponse;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

// Not: `use Throwable;` YOK. Bu dosyanin namespace'i yok, yani zaten global
// isim alanindayiz; global bir sinifi global alana ithal etmek etkisizdir ve
// PHP uyari verir. Throwable asagida ithalsiz calisir.

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // prepend: throttle gibi erken firlatanlardan ONCE calismali,
        // yoksa o hatalar HTML doner. Bkz. kilavuz §2.2.
        $middleware->prependToGroup('api', ForceJsonResponse::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // null donerse Laravel varsayilan akisina duser (web rotalari).
        // API istekleri ForceJsonResponse sayesinde her zaman JSON bekler.
        $exceptions->render(
            fn (Throwable $e, Request $request) => $request->expectsJson()
                ? app(ApiExceptionRenderer::class)->render($e)
                : null,
        );
    })->create();
