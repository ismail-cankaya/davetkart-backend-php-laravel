<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Enums\OrderStatus;

/**
 * Saglayicidan gelen, IMZASI DOGRULANMIS odeme bildirimi — saf veri kabi.
 *
 * 🔴 Bu nesnenin var olmasi tek basina bir GUVENLIK IDDIASIDIR: bir
 * PaymentNotification elinde tutan kod, imzanin dogrulandigini varsayabilir.
 * Dogrulama yapilmadan uretilemez, cunku onu ureten tek yer Gateway'in
 * parseNotification() metodudur ve orada imza kontrolu cagri yolunun
 * uzerindedir (H12'nin filterParams'taki ayni fikri).
 *
 * Bu yuzden ham govde (payload) BILEREK tasinmaz: tasinsaydi asagi katmanlar
 * "bir kez daha bakayim" diye ayrisan ikinci bir yorum uretebilirdi (C3).
 * Ayrintili aciklama: docs/rehber/app/Services/Payment/PaymentGateway.md §5
 */
final readonly class PaymentNotification
{
    public function __construct(
        /** Hangi odeme? orders.provider_ref ile eslesir (UNIQUE). */
        public string $providerRef,

        /**
         * Odeme ne oldu?
         *
         * Tip OrderStatus — sihirli string degil. Saglayicinin kendi
         * sozlugunu ('SUCCESS', 'CAPTURED', 'basarili') BIZIM dilimize
         * cevirmek surucunun isidir; bu sinirdan sonra tek bir sozluk var.
         */
        public OrderStatus $status,

        /**
         * Saglayicinin odemeye verdigi kimlik (Faz 12, K121). Shopier'de
         * `payment_id`; destek ve iade icin orders.provider_payment_id'ye yazilir.
         */
        public ?string $providerPaymentId = null,

        /**
         * 🔴 Saglayicinin TAHSIL ETTIGINI soyledigi tutar (kurus) ve para birimi
         * (Faz 12, K126). Doluysa HandlePaymentCallbackAction siparisin
         * tutariyla karsilastirir; uyusmazsa gecis REDDEDILIR.
         *
         * null = bu bildirim tutar tasimiyor (Fake, Shopier tarayici callback'i).
         * "Tasimiyor" ile "0" ayni sey degil (N4).
         */
        public ?int $amountMinor = null,
        public ?string $currency = null,
    ) {}
}
