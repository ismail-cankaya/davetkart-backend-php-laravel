<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Payment\HandlePaymentCallbackAction;
use App\Http\Controllers\Controller;
use App\Services\Payment\PaymentGateway;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Odeme saglayicisinin bildirim ucu — sistemin UCUNCU auth'suz yazma yolu.
 *
 * 🔴 Tehdit modeli oncekilerden FARKLI:
 *   Faz 5 (LCV)   -> honeypot + hiz siniri + kota. Yazan: anonim MISAFIR
 *   Faz 6 (medya) -> hiz siniri + kota + MIME. Yazan: anonim MISAFIR
 *   Faz 7 (bu uc) -> yalnizca IMZA. Yazan: bilinen bir MAKINE
 *
 * Honeypot yok (gorunmez alan diye bir sey yok), kota yok (mesru bildirim
 * sayisi onceden bilinemez). Savunma tek katmandir ve o katman
 * PaymentGateway::parseNotification() icindedir.
 *
 * 🔴 CSRF muafiyeti YAPILANDIRILMADI, YAPISALDIR: Laravel 11+ iskeletinde
 * VerifyCsrfToken yalnizca 'web' middleware grubunda; 'api' grubu onu hic
 * tasimaz. Yani burada unutulabilecek bir ayar yok — K12'nin fail-safe
 * fikrinin CSRF eksenindeki karsiligi.
 * Ayrintili aciklama: docs/rehber/app/Http/Controllers/Api/V1/PublicPaymentWebhookController.md
 */
final class PublicPaymentWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        PaymentGateway $gateway,
        HandlePaymentCallbackAction $action,
    ): Response {
        // Faz 12 (K118): imzanin NEREDE durdugu (baslik mi, form alani mi)
        // surucunun bilgisi. Controller artik istegi oldugu gibi verir.
        // Imza gecersizse InvalidWebhookSignatureException -> 404 (sessiz red).
        $action->handle($gateway->parseNotification($request));

        // 🔴 HER ZAMAN 204 — siparis bulunsa da bulunmasa da.
        //
        // Webhook uclarinin evrensel kurali: "aldim, bir daha gonderme".
        // Bilinmeyen bir referansta 404 donmek saglayiciyi SONSUZA KADAR
        // retry ettirir ve kuyrugunu doldurur. Ayrica yanit farkindan
        // "bu referans bizde var / yok" bilgisi sizardi (L2).
        return response()->noContent();
    }
}
