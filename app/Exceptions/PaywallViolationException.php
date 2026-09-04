<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\ErrorCode;
use App\Enums\SubscriptionTier;
use RuntimeException;

/**
 * Yayin hakki yok: ya hic odeme yapilmadi ya da alinan plan yetmiyor.
 *
 * 🔴 IKI ADLANDIRILMIS KURUCU, iki AYRI hata kodu — cunku kullanicinin
 * onundeki eylem farkli:
 *
 *   noPurchase()      -> PAYMENT_REQUIRED (402)          "once bir plan al"
 *   insufficientTier()-> PAYWALL_TIER_INSUFFICIENT (402) "planin yetmiyor, yukselt"
 *
 * Ayni durum kodunu paylasirlar (402) ama frontend'in cizecegi ekran farkli:
 * birinde plan kartlari, digerinde yalnizca gerekli plan vurgulanir. Durum
 * kodu KABA siniflandirma, `code` INCE ayrimdir (docs/08 §4) — bu iki kod tam
 * olarak o ayrimin ne ise yaradiginin ornegi.
 *
 * `requiredTier` IKISINDE de gider ve bu bir sizinti degil: plan fiyat sayfasi
 * zaten herkese acik (docs/08 §3.4). Kullanicinin hangi plani almasi
 * gerektigini ogrenmesi savunmayi zayiflatmaz — tam tersine, ogrenmemesi
 * odemeyi imkansizlastirirdi.
 *
 * 🔴 Kurucu private: disaridan `new` ile keyfi bir kod/plan ciftini
 * birlestirmek MUMKUN DEGIL. Kural yorumla degil sinifin sekliyle korunuyor
 * (MediaQuotaExceededException'daki ayni desen, A2 ailesi).
 * Ayrintili aciklama: docs/rehber/app/Exceptions/PaywallViolationException.md
 */
final class PaywallViolationException extends RuntimeException implements HasErrorCode
{
    /**
     * 🔴 Ozellik adi `$errorCode`, `$code` DEGIL.
     *
     * `Exception` sinifinin zaten `protected int $code` ozelligi var (PHP'nin
     * kendi hata kodu). Ayni adi kullanmak onu GOLGELER ve PHPStan uc ayri
     * ihlal bildirir: gorunurluk daraltma (private < protected), ust siniftaki
     * tipsiz ozellige native tip ekleme, ve readwrite bir ozelligi readonly
     * yapma. Ucu de LSP (Liskov) ihlalidir: alt sinif ust sinifin sozunu
     * daraltamaz.
     */
    private function __construct(
        private readonly ErrorCode $errorCode,
        private readonly SubscriptionTier $requiredTier,
        string $message,
    ) {
        parent::__construct($message);
    }

    /** Kullanicinin bu davetiye icin gecerli HICBIR odemesi yok. */
    public static function noPurchase(SubscriptionTier $required): self
    {
        return new self(
            ErrorCode::PaymentRequired,
            $required,
            "Publish rejected: no paid order covers this invitation (requires '{$required->value}').",
        );
    }

    /** Odeme var ama alinan plan gereken moduleri kapsamiyor. */
    public static function insufficientTier(SubscriptionTier $required, SubscriptionTier $owned): self
    {
        return new self(
            ErrorCode::PaywallTierInsufficient,
            $required,
            "Publish rejected: owned tier '{$owned->value}' does not cover '{$required->value}'.",
        );
    }

    public function errorCode(): ErrorCode
    {
        return $this->errorCode;
    }

    /**
     * @return array<string, mixed>
     */
    public function errorParams(): array
    {
        // H12 yine de yolun uzerinde: donen her sey filterParams()'tan gecer.
        // Enum degil DEGERI gonderiliyor — zarf JSON'a serilestirilir ve
        // sozlesme string bekler (types.ts -> SubscriptionTier).
        return ['requiredTier' => $this->requiredTier->value];
    }
}
