<?php

declare(strict_types=1);

use App\Exceptions\ApiExceptionRenderer;
use App\Exceptions\HasErrorCode;
use App\Http\Middleware\ForceJsonResponse;
use App\Http\Middleware\RejectMalformedInput;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Sentry\Laravel\Integration;

// Not: `use Throwable;` YOK. Bu dosyanin namespace'i yok, yani zaten global
// isim alanindayiz; global bir sinifi global alana ithal etmek etkisizdir ve
// PHP uyari verir. Throwable asagida ithalsiz calisir.

// basePath: dirname(__DIR__): Uygulamanın kök dizinini belirler.
// api, commands, health: Rotaları ve sağlık denetimi yolunu yapılandırır.
return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        // web: YOK. Saf bir API projesidir;
        // HTML sayfası olmadığı için Session ve
        // CSRF gibi gereksiz sunucu yükleri tamamen kapatılmıştır.
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Accept başlığını zincirin en başında application/json yapar;
        // böylece hemen ardından gelecek hız sınırı (rate limit) gibi
        // kontroller hata fırlatırsa yanıt HTML değil JSON döner.
        $middleware->prependToGroup('api', ForceJsonResponse::class);

        // API hız sınırını (Rate Limiter) devreye sokar.
        $middleware->throttleApi();

        // API'ye gönderilen verilerin (özellikle JSON formatının veya metin karakterlerinin)
        // bozuk, hatalı veya biçimsiz olup olmadığını denetleyen bir güvenlik filtresidir.
        $middleware->appendToGroup('api', RejectMalformedInput::class);
        // RejectMalformedInput kontrolünü, Laravel'in öncelik listesinde SubstituteBindings'in hemen ÖNÜNE alır.
        $middleware->prependToPriorityList(SubstituteBindings::class, RejectMalformedInput::class);

        // Güvenlik başlıklarını (Security Headers) yanıtın başlıklarına ekler.
        $middleware->append(SecurityHeaders::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Sentry entegrasyonunu etkinleştirir;
        // Sentry, uygulama hatalarını ve istisnaları izlemek için kullanılan bir hata izleme ve raporlama platformudur.
        Integration::handles($exceptions);

        // 4xx iş hatalarını (yanlış parola, 402, kota…) Sentry'ye ve log'a göndermez; 5xx'ler gitmeye devam eder.
        $exceptions->dontReportWhen(
            fn (Throwable $e): bool => $e instanceof HasErrorCode && $e->errorCode()->status() < 500,
        );

        // null donerse Laravel varsayilan akisina duser (web rotalari).
        // API rotalari icin, istisnalar JSON formatinda dondurulur.
        $exceptions->render(
            fn (Throwable $e, Request $request) => $request->is('api/*') || $request->expectsJson()
                ? app(ApiExceptionRenderer::class)->render($e)
                : null,
        );
    })->create();
