<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\InvitationController;
use App\Http\Controllers\Api\V1\PublicInvitationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Rotalari
|--------------------------------------------------------------------------
| '/api' oneki bootstrap/app.php icindeki withRouting() tarafindan eklenir.
| K10: surum URL'de DEGIL, controller namespace'inde (Api\V1\).
| K12: auth gerektirmeyen rotalar '/api/public/' altinda gruplanir (Faz 4).
| Ayrintili aciklama: docs/rehber/routes/api.md
*/

// Sozlesme sagligi: API katmani ayakta ve JSON konusuyor mu?
// Closure DEGIL sinif referansi — route:cache closure'lari serilestiremez.
Route::get('/ping', HealthController::class)->name('health.ping');

/*
| Kimlik (Faz 2)
| Yanit ZARFSIZ: {user, token} — {data: ...} YOK (K11).
| Not: group() closure'i R1'i ihlal etmez; R1 rota EYLEMI icin gecerlidir.
| Grup closure'i kayit aninda calisir, Route nesnesinde saklanmaz.
*/
Route::prefix('auth')->name('auth.')->group(function (): void {
    // Kimlik BILGISI kabul eden uclar: brute-force hedefi, hiz siniri sart (K36).
    Route::middleware('throttle:auth')->group(function (): void {
        Route::post('/register', [AuthController::class, 'register'])->name('register');
        Route::post('/login', [AuthController::class, 'login'])->name('login');
    });

    // Gecerli token gerektiren uclar: tehdit modeli farkli, throttle:auth YOK.
    Route::middleware('auth:sanctum')->group(function (): void {
        Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
        Route::get('/me', [AuthController::class, 'me'])->name('me');
    });
});

/*
| Davetiyeler (Faz 3) — K37: tam REST koleksiyonu.
|
| ⚠️ Sabit segmentli rotalar (ornek: /invitations/count) buraya, apiResource'un
| USTUNE yazilmali; aksi halde {invitation} onlari yutar. whereUlid kisiti bu
| riski ayrica azaltir: {invitation} yalnizca ULID bicimine eslesir.
|
| 🔴 R6: kisit ELLE YAZILMAZ. HasUlids::newUniqueId() strtolower() uyguluyor;
| elle yazilan buyuk-harf regex hicbir istegi eslestirmedi ve Policy hic
| calismadi. Gerekce: docs/rehber/routes/api.md §5.
*/
Route::middleware('auth:sanctum')->group(function (): void {
    Route::apiResource('invitations', InvitationController::class)
        ->whereUlid('invitation');
});

/*
| Public davetiye (Faz 4) — 🔴 K12: auth GEREKTIRMEYEN rotalarin TEK yeri.
|
| Bu oneki ayirmanin sebebi kolaylik degil, fail-safe tasarim: 'auth:sanctum'
| unutulursa bir davetiye herkese acilir. Onek, "acik olmak"i bir UNUTMANIN
| sonucu olmaktan cikarip ACIKCA ISARETLENMIS bir istisna yapar.
|
| ⚠️ Buraya bir rota eklemek, onu internete acmaktir. Once "bu veriyi kimligi
| bilinmeyen biri gorebilir mi?" sorusu cevaplanir.
| Ayrintili aciklama: docs/rehber/routes/api.md §0.3
*/
Route::prefix('public')->name('public.')->group(function (): void {
    Route::get('/invitations/{id}', [PublicInvitationController::class, 'show'])
        ->whereUlid('id')
        ->name('invitations.show');
});
