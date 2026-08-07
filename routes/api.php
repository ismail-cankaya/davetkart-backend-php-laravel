<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\HealthController;
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
