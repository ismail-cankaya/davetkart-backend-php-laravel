<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\InvitationController;
use App\Http\Controllers\Api\V1\MediaController;
use App\Http\Controllers\Api\V1\PublicInvitationController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\PublicMediaController;
use App\Http\Controllers\Api\V1\PublicPaymentWebhookController;
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

    /*
    | Galeri yuklemesi (Faz 6) — davetiye SAHIBI.
    |
    | Ic ice kaynak: bir dosya her zaman bir davetiyeye aittir ve bu aidiyet
    | URL'nin YAPISINDA durur. Duz bir /media/upload ucu olsaydi davetiye
    | kimligi govdeden gelirdi — yani istemcinin sozune kalirdi (N1).
    |
    | ⚠️ docs/09 "frontend kazanir, POST /media/upload" diyordu; o not misafir
    | yuklemesini hesaba katmadan yazilmisti. Frontend uyarlanacak (Faz 6 §8).
    |
    | Ayri bir throttle YOK: uc auth arkasinda ve grubun throttle:api tavani
    | zaten gecerli. Tehdit modeli misafir ucundakiyle ayni degil.
    */
    Route::post('/invitations/{invitation}/media', [MediaController::class, 'store'])
        ->whereUlid('invitation')
        ->name('invitations.media.store');

    /*
    | Yayinlama (Faz 7) — PAYWALL KAPISI.
    |
    | 🔴 Bu rota Faz 3'te acilmadi ve gerekcesi K47 olarak kaydedildi:
    | "simdi yazilirsa paywall'siz bir bedava yayin yolu acilir". Kapiyi
    | kilitleyecek anahtarlar (TierResolver, PublishEntitlementResolver) ancak
    | bugun var — rota da bugun aciliyor.
    |
    | POST, PUT degil: yayin bir DURUM GECISIDIR, bir alan guncellemesi degil.
    | PUT idempotan olmali (ayni istek ayni sonucu vermeli) ama ikinci yayin
    | istegi bilerek 409 doner (7.12 §4).
    */
    Route::post('/invitations/{invitation}/publish', [InvitationController::class, 'publish'])
        ->whereUlid('invitation')
        ->name('invitations.publish');

    /*
    | Odeme baslatma (Faz 7) — K42'nin iki kolu, iki rota.
    |
    | 🔴 Ic ice kaynak: TEKIL alimda davetiye kimligi URL'nin YAPISINDA durur.
    | docs/09 duz bir POST /api/payments/checkout ongormustu ve govdede
    | invitationId tasiyacakti; Faz 6 ayni karari medya uclarinda zaten
    | degistirmisti (N1) — kimlik govdeden gelseydi aidiyet ISTEMCININ SOZUNE
    | kalirdi. whereUlid ayrica bicimsiz kimligi veritabanina hic ulastirmaz (O6).
    |
    | ⚠️ Sabit segmentli /payments/checkout, apiResource'un {invitation}
    | parametresiyle CAKISMAZ: farkli onek altinda.
    */
    Route::post('/invitations/{invitation}/checkout', [PaymentController::class, 'forInvitation'])
        ->whereUlid('invitation')
        ->name('invitations.checkout');

    // PAKET alim: hesabin tamami icin plan. Davetiye kimligi YOK (K42).
    Route::post('/payments/checkout', [PaymentController::class, 'forAccount'])
        ->name('payments.checkout');
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

    /*
    | 🔴 Sistemin IKINCI auth'suz yazma yolu (Faz 6) — ve daha pahalisi.
    |
    | LCV metni birkac yuz bayt yazar; bu uc onlarca MEGABAYT dosya yazar.
    | Ustelik Faz 5'in en ucuz katmani olan HONEYPOT burada YOK: gorunmez bir
    | dosya alani diye bir sey yok. Bu yuzden throttle:media, throttle:rsvp'den
    | DAHA DAR (dakikada 5 / saatte 40).
    |
    | SetEtag bu ucta anlamsiz ama zararsiz: grup middleware'i olarak geliyor,
    | POST yanitlarinda ETag uretimi 304 dongusune girmez.
    */
    Route::post('/invitations/{invitation}/media', [PublicMediaController::class, 'store'])
        ->whereUlid('invitation')
        ->middleware('throttle:media')
        ->name('invitations.media.store');

    /*
    | 🔴 Sistemin UCUNCU auth'suz yazma yolu (Faz 7) — ve tehdit modeli
    | oncekilerden farkli: yazan anonim bir MISAFIR degil, bilinen bir MAKINE.
    |
    | Savunma TEK KATMAN: imza dogrulamasi (FakeGateway::parseNotification).
    | Honeypot YOK (gorunmez alan diye bir sey yok), kota YOK (mesru bildirim
    | sayisi onceden bilinemez). Bu bir eksiklik degil: imza, gonderenin kim
    | oldugunu kriptografik olarak kanitlar — digerlerinde boyle bir kanit
    | hic yoktu.
    |
    | 🔴 docs/09 bu ucu /api/payments/webhook diye planlamisti; K12 onu buraya
    | tasidi. Auth'suz her rota TEK yerde toplanir ki 'auth:sanctum unutuldu mu'
    | sorusu bir hatirlama meselesi olmasin (fail-safe).
    |
    | 🔴 CSRF muafiyeti icin YAZILACAK BIR SATIR YOK: Laravel 11+ iskeletinde
    | VerifyCsrfToken yalnizca 'web' grubunda. Muafiyet yapisal.
    |
    | Ozel bir throttle kovasi YOK: mesru bildirim hacmi ongorulemez ve dar bir
    | limit GERCEK odemeleri dusururdu. Grubun throttle:api tavani (60/dk, IP)
    | gecerli — saglayici tek IP'den yogun gonderirse 429 alir ve retry eder
    | (Faz 9: saglayici IP'leri muaf tutulacak).
    |
    | SetEtag bu ucta anlamsiz ama zararsiz: POST yanitlarinda 304 dongusune
    | girmez; 204 yanitinin govdesi zaten yok.
    */
    Route::post('/payments/webhook', PublicPaymentWebhookController::class)
        ->name('payments.webhook');
});
