<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Exceptions\InvalidWebhookSignatureException;
use App\Models\Order;

/**
 * Odeme saglayicisinin arkasina saklandigi arayuz — K8, Strategy Pattern.
 *
 * 🔴 Neden arayuz? Iyzico/PayTR anlasmasi BEKLENMEDEN dogru akis kurulabilsin
 * diye. Bugun FakeGateway bagli, yarin IyzicoGateway baglanir (Faz 9) ve
 * degisen TEK sey AppServiceProvider'daki bir satirdir:
 *
 *   StartCheckoutAction · HandlePaymentCallbackAction · PaymentController
 *   · Order modeli · PaywallTest  -> hicbiri degismez
 *
 * Bu, Faz 5'te RsvpQuotaResolver ile ogrenilen DIKIS YERI (seam) fikrinin
 * ayni faz icinde ikinci uygulamasi; farki, orada eksik olan bir VERI
 * KAYNAGI idi, burada eksik olan bir DIS SERVIS.
 *
 * SOLID:
 *   - D (Dependency Inversion): Action somut saglayiciya degil bu arayuze bagli
 *   - O (Open/Closed): yeni saglayici EKLENIR, var olan kod DEGISMEZ
 *   - I (Interface Segregation): uc metot; "iade" gibi bugun kullanilmayan
 *     yetenekler BILEREK yok — yazilsalardi her surucu bos govde yazardi
 *
 * CLAUDE.md §1: dis servislerle iletisim arayuzler uzerinden app/Services/'te.
 * Ayrintili aciklama: docs/rehber/app/Services/Payment/PaymentGateway.md
 */
interface PaymentGateway
{
    /**
     * Surucunun adi — orders.provider kolonuna yazilir (F4).
     *
     * config('payment.default') "SIMDI hangi saglayici" sorusunun cevabidir;
     * kolon "O SIPARIS hangisiyle odendi" sorusunun. Surucu kendi adini
     * soyledigi icin ikisi asla ayrisamaz.
     */
    public function name(): string;

    /**
     * Saglayicida bir odeme oturumu acar.
     *
     * 🔴 Siparis ONCE veritabaninda olusur, sonra buraya gelir. Tersi
     * olsaydi saglayici oturumu acildiktan sonra veritabani yazimi hata
     * verirse ODENMIS AMA KAYDI OLMAYAN bir odeme kalirdi.
     *
     * Firlattigi her hata StartCheckoutAction'da yakalanir ve
     * PaymentProviderException'a cevrilir (H8: saglayicinin ham hatasi
     * yanita GIRMEZ, yalnizca log'a gider).
     */
    public function startCheckout(Order $order): CheckoutSession;

    /**
     * Webhook govdesini DOGRULAR ve anlamli bir bildirime cevirir.
     *
     * 🔴 Bu metodun asil isi cevirmek degil DOGRULAMAKTIR. Webhook ucu
     * auth'suzdur; imza kontrolu olmasaydi "odeme basarili" POST'unu herkes
     * atabilirdi. Savunma tek katmandir — honeypot yok (gorunmez alan diye
     * bir sey yok), kota yok (mesru bildirim sayisi belirsiz).
     *
     * @param  string  $payload  Ham govde — JSON olarak DEGIL, imza neyin
     *                           uzerinden hesaplandiysa o haliyle
     * @param  string  $signature  Imza basligi (config: payment.webhook.signature_header)
     *
     * @throws InvalidWebhookSignatureException Imza gecersiz -> 404 (bkz. kilavuz §6)
     */
    public function parseNotification(string $payload, string $signature): PaymentNotification;
}
