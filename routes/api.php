<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\InvitationController;
use App\Http\Controllers\Api\V1\PublicInvitationController;
use App\Http\Controllers\Api\V1\PublicRsvpController;
use App\Http\Controllers\Api\V1\RsvpController;
use App\Http\Middleware\SetEtag;
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

    /*
    | LCV paneli (Faz 5).
    |
    | Liste ucu 15 saniyede bir cagrilir (config: poll_interval_seconds), yani
    | sistemin en sik istenen auth'lu ucudur. SetEtag burada K46'nin karsiligini
    | aliyor: Faz 4'te ETag'i ayri bir middleware yapmamizin gerekcesi
    | "Faz 5'in polling ucu ayni katmani yeniden kullanacak" idi (C3).
    |
    | ⚠️ throttle:rsvp BURAYA KONMAZ — o kova YAZMA icindir (dakikada 10).
    | Okuma polling'i 15 sn'de bir gelir ve o kovada bogulurdu.
    */
    Route::get('/invitations/{invitation}/rsvps', [RsvpController::class, 'index'])
        ->whereUlid('invitation')
        ->middleware(SetEtag::class)
        ->name('invitations.rsvps.index');

    // K52: rsvps.id ULID oldugu icin burada da whereUlid kullanilabiliyor —
    // bicimsiz kimlik veritabanina hic ulasmaz (O6).
    Route::delete('/rsvps/{rsvp}', [RsvpController::class, 'destroy'])
        ->whereUlid('rsvp')
        ->name('rsvps.destroy');
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
Route::prefix('public')->name('public.')->middleware(SetEtag::class)->group(function (): void {
    Route::get('/invitations/{id}', [PublicInvitationController::class, 'show'])
        ->whereUlid('id')
        ->name('invitations.show');

    /*
    | 🔴 Sistemin TEK auth'suz YAZMA yolu (Faz 5).
    |
    | Ic ice kaynak: bir LCV yaniti her zaman bir davetiyeye aittir ve bu
    | aidiyet URL'nin YAPISINDA durur. Duz bir /api/public/rsvps ucu olsaydi
    | davetiye kimligi govdeden gelirdi — yani istemcinin sozune kalirdi.
    |
    | throttle:rsvp iki kova birden uygular (5.11): IP basina dakikada 10,
    | davetiye basina saatte 60. Ikisi iki farkli saldiriyi kapatir.
    */
    Route::post('/invitations/{invitation}/rsvps', [PublicRsvpController::class, 'store'])
        ->whereUlid('invitation')
        ->middleware('throttle:rsvp')
        ->name('invitations.rsvps.store');
});
