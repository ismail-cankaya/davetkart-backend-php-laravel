<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\AssistantController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\EmailChangeController;
use App\Http\Controllers\Api\V1\EmailVerificationController;
use App\Http\Controllers\Api\V1\GiftCodeController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\InvitationController;
use App\Http\Controllers\Api\V1\MediaController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\PasswordController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\PricingController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\PublicContactController;
use App\Http\Controllers\Api\V1\PublicEmailChangeController;
use App\Http\Controllers\Api\V1\PublicInvitationController;
use App\Http\Controllers\Api\V1\PublicMediaController;
use App\Http\Controllers\Api\V1\PublicPaymentWebhookController;
use App\Http\Controllers\Api\V1\PublicRsvpController;
use App\Http\Controllers\Api\V1\ReadinessController;
use App\Http\Controllers\Api\V1\RefundRequestController;
use App\Http\Controllers\Api\V1\RsvpController;
use App\Http\Controllers\Api\V1\SessionController;
use App\Http\Controllers\Api\V1\ShopierCallbackController;
use App\Http\Controllers\Api\V1\ShopierOsbController;
use App\Http\Middleware\SetEtag;
use Illuminate\Support\Facades\Route;

// '/api' oneki bootstrap/app.php'den; K10: surum namespace'te (Api\V1); K12: auth'suz rotalar '/api/public/'.
// Ayrinti: docs/rehber/routes/api.md T1.

// Sozlesme sagligi: API katmani ayakta ve JSON konusuyor mu?
// Closure DEGIL sinif referansi — route:cache closure'lari serilestiremez.
Route::get('/ping', HealthController::class)->name('health.ping');

// Faz 19.7 (OPS-03): hazir olma (readiness) -- veritabani, kuyruk, zamanlayici, disk.
// Dis izleyici dakikada bir vurur; /ping dagitim betigi icin aynen kalir (D1).
Route::get('/health', ReadinessController::class)->middleware('throttle:60,1')->name('health.ready');

// Kimlik (Faz 2): yanit ZARFSIZ {user, token} (K11).
// Ayrinti: docs/rehber/routes/api.md T2.
Route::prefix('auth')->name('auth.')->group(function (): void {
    // Kimlik BILGISI kabul eden uclar: brute-force hedefi, hiz siniri sart (K36).
    Route::middleware('throttle:auth')->group(function (): void {
        Route::post('/register', [AuthController::class, 'register'])->name('register');
        Route::post('/login', [AuthController::class, 'login'])->name('login');

        // Faz 10 (10.35): parola sifirlama. Ayni kova (e-posta + IP basina
        // 5/dk, IP basina 20/dk): ikisi de e-posta alan, kimliksiz uclar.
        // forgot: hesap tarama / mail bombalama; reset: token deneme.
        Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->name('password.forgot');
        Route::post('/reset-password', [AuthController::class, 'resetPassword'])->name('password.reset');
    });

    // Gecerli token gerektiren uclar: tehdit modeli farkli, throttle:auth YOK.
    Route::middleware('auth:sanctum')->group(function (): void {
        Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
        Route::get('/me', [AuthController::class, 'me'])->name('me');

        // Faz 14 (14.7): ad/soyad. E-posta buradan DEĞİŞMEZ (doğrulamalı akış, 14.10).
        Route::patch('/me', [ProfileController::class, 'update'])->name('update');

        // Faz 10 (10.40): hesabi sil (K97). Parola onayi istedigi icin bir
        // TAHMIN hedefi: calinmis bir token'la parola denenmesin diye
        // throttle:auth (IP basina 20/dk, govdede e-posta yok -> 'anonim'|IP 5/dk).
        Route::delete('/me', [AuthController::class, 'destroy'])
            ->middleware('throttle:auth')
            ->name('destroy');

        // Faz 14 (14.8): oturum açıkken parola değiştir. Mevcut parolayı
        // istediği için bir TAHMİN hedefi: hesap silmeyle aynı kova.
        Route::put('/password', [PasswordController::class, 'update'])
            ->middleware('throttle:auth')
            ->name('password.update');

        // Faz 14 (14.9): aktif oturumlar. Kimlik sıralı tamsayı (Sanctum):
        // sahiplik sorguda, başkasınınki 404 (H7). whereNumber: biçimsiz
        // kimlik veritabanına hiç ulaşmaz (O6).
        Route::get('/sessions', [SessionController::class, 'index'])->name('sessions.index');
        Route::delete('/sessions', [SessionController::class, 'destroyOthers'])->name('sessions.destroy-others');
        Route::delete('/sessions/{session}', [SessionController::class, 'destroy'])
            ->whereNumber('session')
            ->name('sessions.destroy');

        // Faz 14 (14.10 · K144): yeni adrese onay bağlantısı. Mevcut parolayı
        // istiyor ve dışarıya mail gönderiyor: tahmin + mail bombalama hedefi,
        // throttle:auth (anahtar = yeni adres + IP).
        Route::post('/email-change', [EmailChangeController::class, 'store'])
            ->middleware('throttle:auth')
            ->name('email-change.store');

        // Faz 19 (19.14 · K191, SEC-09): doğrulama postasını yeniden gönder. Kullanıcı
        // başına saatte 3; hesap zaten doğrulanmışsa posta gitmez (yine 204).
        Route::post('/email-verification', [EmailVerificationController::class, 'resend'])
            ->middleware('throttle:email-verification')
            ->name('email-verification.store');
    });
});

// Davetiyeler (Faz 3, K37): sabit segmentli rotalar apiResource'un USTUNE; ULID kisiti elle yazilmaz (R6).
// Ayrinti: docs/rehber/routes/api.md T3.
Route::middleware('auth:sanctum')->group(function (): void {
    Route::apiResource('invitations', InvitationController::class)
        ->whereUlid('invitation');

    // LCV paneli (Faz 5): 15 sn'de bir sorgulanan liste; ETag (K46) burada, yazma kovasi throttle:rsvp degil.
    // Ayrinti: docs/rehber/routes/api.md T4.
    Route::get('/invitations/{invitation}/rsvps', [RsvpController::class, 'index'])
        ->whereUlid('invitation')
        ->middleware(SetEtag::class)
        ->name('invitations.rsvps.index');

    // K52: rsvps.id ULID oldugu icin burada da whereUlid kullanilabiliyor —
    // bicimsiz kimlik veritabanina hic ulasmaz (O6).
    Route::delete('/rsvps/{rsvp}', [RsvpController::class, 'destroy'])
        ->whereUlid('rsvp')
        ->name('rsvps.destroy');

    // Galeri yuklemesi (Faz 6) — davetiye SAHIBI.
    // Ayrinti: docs/rehber/routes/api.md T5.
    Route::post('/invitations/{invitation}/media', [MediaController::class, 'store'])
        ->whereUlid('invitation')
        ->name('invitations.media.store');

    // Galeriden fotograf silme — davetiye SAHIBI.
    // Ayrinti: docs/rehber/routes/api.md T6.
    Route::delete('/invitations/{invitation}/media/{media}', [MediaController::class, 'destroy'])
        ->whereUlid(['invitation', 'media'])
        ->name('invitations.media.destroy');

    // Yayinlama (Faz 7) — PAYWALL KAPISI.
    // Ayrinti: docs/rehber/routes/api.md T7.
    Route::post('/invitations/{invitation}/publish', [InvitationController::class, 'publish'])
        ->whereUlid('invitation')
        ->name('invitations.publish');

    // Odeme baslatma (Faz 7) — K42'nin iki kolu, iki rota.
    // Ayrinti: docs/rehber/routes/api.md T8.
    Route::post('/invitations/{invitation}/checkout', [PaymentController::class, 'forInvitation'])
        ->whereUlid('invitation')
        ->middleware('throttle:gift-code')
        ->name('invitations.checkout');

    // PAKET alim: hesabin tamami icin plan. Davetiye kimligi YOK (K42).
    Route::post('/payments/checkout', [PaymentController::class, 'forAccount'])
        ->middleware('throttle:gift-code')
        ->name('payments.checkout');

    /*
    | Fiyat katalogu, KISIYE OZEL (Faz 13, K128): ilk yayin indirimi dahil.
    | Frontend'de fiyat yok; odeme sayfasi ve paywall sayilari buradan okur.
    | Misafir surumu /api/public/plans.
    */
    Route::get('/pricing', [PricingController::class, 'forUser'])
        ->middleware(SetEtag::class)
        ->name('pricing.show');

    // Hediye kodu onizleme (Faz 11).
    // Ayrinti: docs/rehber/routes/api.md T9.
    Route::post('/gift-codes/preview', [GiftCodeController::class, 'preview'])
        ->middleware('throttle:gift-code')
        ->name('gift-codes.preview');

    // Siparisler (Faz 10, 10.23) — YALNIZCA okuma.
    // Ayrinti: docs/rehber/routes/api.md T10.
    Route::apiResource('orders', OrderController::class)
        ->only(['index', 'show'])
        ->whereUlid('order');

    // Hesap ayarları — "Planlarım" (Faz 14, 14.4 · K140).
    // Ayrinti: docs/rehber/routes/api.md T11.
    Route::get('/account/entitlements', [AccountController::class, 'entitlements'])
        ->middleware(SetEtag::class)
        ->name('account.entitlements');

    // Hakkı iptal et / vazgeç (Faz 14, 14.5 · K138).
    // Ayrinti: docs/rehber/routes/api.md T12.
    Route::post('/orders/{order}/refund-request', [RefundRequestController::class, 'store'])
        ->whereUlid('order')
        ->name('orders.refund-request.store');

    Route::delete('/orders/{order}/refund-request', [RefundRequestController::class, 'destroy'])
        ->whereUlid('order')
        ->name('orders.refund-request.destroy');

    // AI asistan (Faz 8) — sistemin PARA HARCAYAN tek ucu.
    // Ayrinti: docs/rehber/routes/api.md T13.
    Route::post('/assistant/chat', AssistantController::class)
        ->middleware('throttle:assistant')
        ->name('assistant.chat');
});

// 🔴 K12: auth gerektirmeyen rotalarin TEK yeri. Buraya rota eklemek onu internete acmaktir.
// Ayrinti: docs/rehber/routes/api.md T14.
Route::prefix('public')->name('public.')->middleware(SetEtag::class)->group(function (): void {
    // Fiyat katalogu (Faz 13, K128): herkese acik; kisiye ozel ilk yayin indirimi /api/pricing'de.
    // Ayrinti: docs/rehber/routes/api.md T15.
    Route::get('/plans', [PricingController::class, 'publicCatalog'])
        ->name('plans.index');

    // K186 (SEC-06): misafir okuması genel IP kovasından çıkar, kendi geniş kovasında.
    Route::get('/invitations/{id}', [PublicInvitationController::class, 'show'])
        ->whereUlid('id')
        ->withoutMiddleware('throttle:api')
        ->middleware('throttle:public-read')
        ->name('invitations.show');

    // 🔴 Sistemin TEK auth'suz YAZMA yolu (Faz 5).
    // Ayrinti: docs/rehber/routes/api.md T16.
    Route::post('/invitations/{invitation}/rsvps', [PublicRsvpController::class, 'store'])
        ->whereUlid('invitation')
        ->middleware('throttle:rsvp')
        ->name('invitations.rsvps.store');

    // Faz 10 (10.59 · K101): misafir kendi yanıtını düzenleme koduyla günceller.
    // Aynı hız kovası: güncelleme de bir yazma.
    Route::put('/invitations/{invitation}/rsvps/{rsvp}', [PublicRsvpController::class, 'update'])
        ->whereUlid(['invitation', 'rsvp'])
        ->middleware('throttle:rsvp')
        ->name('invitations.rsvps.update');

    // 🔴 Sistemin IKINCI auth'suz yazma yolu (Faz 6) — ve daha pahalisi.
    // Ayrinti: docs/rehber/routes/api.md T17.
    Route::post('/invitations/{invitation}/media', [PublicMediaController::class, 'store'])
        ->whereUlid('invitation')
        ->middleware('throttle:media')
        ->name('invitations.media.store');

    // 🔴 Ucuncu auth'suz yazma yolu (Faz 7): yazan bilinen bir makine; tek savunma imza dogrulamasi.
    // Ayrinti: docs/rehber/routes/api.md T18.
    Route::post('/payments/webhook', PublicPaymentWebhookController::class)
        ->name('payments.webhook');

    // Faz 12 (K117, K122): Shopier'in iki dönüş kanalı ayrı uçlarda; callback tarayıcıdan, osb sunucudan gelir.
    // Ayrinti: docs/rehber/routes/api.md T19.
    Route::post('/payments/shopier/callback', ShopierCallbackController::class)
        ->name('payments.shopier.callback');

    Route::post('/payments/shopier/osb', ShopierOsbController::class)
        ->name('payments.shopier.osb');

    // 🔴 Dorduncu auth'suz yazma yolu (Faz 8): throttle:contact + honeypot + ContactRequest.
    // Ayrinti: docs/rehber/routes/api.md T20.
    Route::post('/contact', PublicContactController::class)
        ->middleware('throttle:contact')
        ->name('contact.store');

    // E-posta değişikliği onayı (Faz 14, 14.10 · K144).
    // Ayrinti: docs/rehber/routes/api.md T21.
    Route::post('/email-change/confirm', PublicEmailChangeController::class)
        ->middleware('throttle:auth')
        ->name('email-change.confirm');

    // Faz 19 (19.14 · K191, SEC-09): kayıt sonrası doğrulama bağlantısı. Yetkinin kanıtı
    // imza (EmailVerificationLink); throttle:auth ucuz bir tavan.
    Route::post('/email-verification/confirm', [EmailVerificationController::class, 'confirm'])
        ->middleware('throttle:auth')
        ->name('email-verification.confirm');
});
