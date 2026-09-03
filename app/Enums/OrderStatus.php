<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Bir odeme siparisinin (order) yasam dongusu.
 *
 * 🔴 Bu enum UC soruya birden cevap verir ve ucu de TEK yerde durur:
 *   1. Hangi degerler gecerli?      -> values()  (migration'daki CHECK kisiti)
 *   2. Hangi durum YAYIN HAKKI verir? -> grantsPublishRight()
 *   3. Hangi gecis mesru?            -> canTransitionTo()
 *
 * Ucuncusu bir DURUM MAKINESIDIR ve bilerek buraya konuldu: webhook'u isleyen
 * kod "zaten paid mi?" diye elle sormak yerine gecisin mesru olup olmadigini
 * soruyor. Kural koda degil TIPE bagli oldugu icin ikinci bir cagiran (iade
 * ucu, admin paneli) ayni kurali yeniden yazmak zorunda kalmaz (C3).
 * Ayrintili aciklama: docs/rehber/app/Enums/OrderStatus.md
 */
enum OrderStatus: string
{
    /** Odeme baslatildi, saglayicidan sonuc gelmedi. Yayin hakki VERMEZ. */
    case Pending = 'pending';

    /** Saglayici odemeyi onayladi. Yayin hakki veren TEK durum. */
    case Paid = 'paid';

    /** Saglayici reddetti ya da sure doldu. */
    case Failed = 'failed';

    /** Odeme geri odendi; hak GERI ALINIR. */
    case Refunded = 'refunded';

    /**
     * Bu durum yayin hakki veriyor mu?
     *
     * 🔴 Faz 5'in K50'siyle ayni desen: "hangi durumlar sayilir" sorusu SQL
     * sorgusunda degil enum'da cevaplanir. Sorguya `where('status', 'paid')`
     * yazsaydik, yarin "kismi odeme" gibi bir durum eklendiginde kuralin
     * kopyalari uc ayri dosyada aranirdi.
     */
    public function grantsPublishRight(): bool
    {
        return $this === self::Paid;
    }

    /** Durum bir daha degisebilir mi? `pending` disindaki her sey durulmustur. */
    public function isFinal(): bool
    {
        return $this !== self::Pending;
    }

    /**
     * Bu gecis mesru mu?
     *
     * Kucuk ama gercek bir durum makinesi:
     *   pending -> paid | failed
     *   paid    -> refunded
     *   failed / refunded -> (hicbir yere)
     *
     * 🔴 `paid -> paid` BILEREK YASAK. Odeme webhook'u ayni bildirimi birden
     * cok kez gonderir; ikinci bildirim "gecis mesru degil" diyerek elenir ve
     * yan etki (published_at damgasi, e-posta, muhasebe kaydi) IKI KEZ
     * uygulanmaz. Idempotansin uygulama katmanindaki yarisi budur; veritabani
     * katmanindaki yarisi `orders.provider_ref` UNIQUE kisitidir.
     */
    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::Pending => $next === self::Paid || $next === self::Failed,
            self::Paid => $next === self::Refunded,
            self::Failed, self::Refunded => false,
        };
    }

    /** Yeni bir siparisin baslangic durumu. */
    public static function default(): self
    {
        return self::Pending;
    }

    /**
     * Veritabani CHECK kisiti ve dogrulama kurallari icin ham degerler.
     *
     * Elle yazilmaz: enum degisince kisit sessizce eskimesin (K39).
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
