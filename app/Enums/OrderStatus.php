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
 *
 * 🔴 Faz 10 (K89): `expired` eklendi. Faz 9'dan beri `failed` IKI gercegi
 * anlatiyordu: "saglayici reddetti" (kesin) ve "biz beklemekten vazgectik"
 * (tahmin). Ikincisine gec gelen imzali bir `paid` sessizce yutuluyordu (E12).
 * Ayrintili aciklama: docs/rehber/app/Enums/OrderStatus.md
 */
enum OrderStatus: string
{
    /** Odeme baslatildi, saglayicidan sonuc gelmedi. Yayin hakki VERMEZ. */
    case Pending = 'pending';

    /** Saglayici odemeyi onayladi. Yayin hakki veren TEK durum. */
    case Paid = 'paid';

    /** Saglayici reddetti ya da odeme hic baslatilamadi. KESIN: bir daha degismez. */
    case Failed = 'failed';

    /**
     * Odeme penceresi doldu, saglayicidan SONUC gelmedi (orders:expire yazar).
     *
     * Kesin degil, bir TAHMINDIR: kullanici son dakikada odemis ve bildirim
     * gecikmis olabilir. Bu yuzden `paid`'e donebilir; `failed` donemez.
     * Yayin hakki VERMEZ, para alinmis SAYILMAZ.
     */
    case Expired = 'expired';

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

    /**
     * Bu duruma gelmis bir siparis icin PARA GERCEKTEN ALINDI mi?
     *
     * `refunded` de true doner: iade edilmis bir siparis bir zamanlar
     * odenmisti ve `paid_at` damgasi silinmez — muhasebe gecmisi geri
     * yazilamaz. Bu ayrimi grantsPublishRight() ile karistirma: para alindi
     * olmasi HAK verildigi anlamina gelmez (iade hakki geri alir).
     *
     * Kullanildigi yer: orders_paid_at_check kisiti (7.2).
     */
    public function hasBeenPaid(): bool
    {
        return $this === self::Paid || $this === self::Refunded;
    }

    /**
     * Durum bir daha DEGISEBILIR mi?
     *
     * 🔴 Elle yazilmaz, durum makinesinden TURETILIR: gidebilecegi hicbir
     * durum yoksa sonludur. Eski govde (`!== Pending`) `expired`'i sonlu
     * sayardi — oysa `expired -> paid` mesru. Liste elle tutulsaydi her yeni
     * durumda bu metot sessizce yalan soylerdi (K39'un metot hali).
     */
    public function isFinal(): bool
    {
        foreach (self::cases() as $next) {
            if ($this->canTransitionTo($next)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Bu gecis mesru mu?
     *
     * Kucuk ama gercek bir durum makinesi:
     *   pending -> paid | failed | expired
     *   expired -> paid                      (Faz 10, K89: gec gelen odeme)
     *   paid    -> refunded
     *   failed / refunded -> (hicbir yere)
     *
     * 🔴 `paid -> paid` BILEREK YASAK. Odeme webhook'u ayni bildirimi birden
     * cok kez gonderir; ikinci bildirim "gecis mesru degil" diyerek elenir ve
     * yan etki (published_at damgasi, e-posta, muhasebe kaydi) IKI KEZ
     * uygulanmaz. Idempotansin uygulama katmanindaki yarisi budur; veritabani
     * katmanindaki yarisi `orders.provider_ref` UNIQUE kisitidir.
     *
     * 🔴 `expired -> paid` ACIK, `failed -> paid` KAPALI. Ikisinin farki
     * kimin karar verdigidir: `failed` saglayicinin sozu, `expired` bizim
     * sabrimizin sonu. Saglayici sonradan "odendi" derse onun sozu gecer.
     */
    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::Pending => in_array($next, [self::Paid, self::Failed, self::Expired], true),
            self::Expired => $next === self::Paid,
            self::Paid => $next === self::Refunded,
            self::Failed, self::Refunded => false,
        };
    }

    /**
     * `paid_at` damgasi ZORUNLU olan durumlar — CHECK kisitini besler.
     *
     * hasBeenPaid()'ten TURETILIR: liste elle yazilsaydi enum degisince
     * kisit sessizce eskirdi (K39, MediaKind::guestUploadableValues() deseni).
     *
     * @return list<string>
     */
    public static function paidValues(): array
    {
        $values = [];

        foreach (self::cases() as $case) {
            if ($case->hasBeenPaid()) {
                $values[] = $case->value;
            }
        }

        return $values;
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
