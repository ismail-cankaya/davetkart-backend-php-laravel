<?php

declare(strict_types=1);

namespace App\Actions\Pricing;

use App\Actions\GiftCode\GiftCodeQuote;
use App\Enums\SubscriptionTier;
use LogicException;

/**
 * Bir planın bu kullanıcı için fiyat dökümü (Faz 13, K129). Saf veri, yan etkisiz.
 *
 *   liste fiyatı                      listMinor
 *   − otomatik indirim (kampanya/ilk) promotionDiscountMinor
 *   = indirimli fiyat                 afterPromotionMinor()
 *   − hediye kodu (kalan fiyata)      giftDiscountMinor()
 *   = ödenecek                        totalMinor()
 *
 * Tutarlar kuruş (int); hiçbiri eksi olamaz. Checkout, hediye kodu önizlemesi
 * ve fiyat uçları bu nesneyi kullanır: ekrandaki sayı ile karttan çekilen sayı
 * aynı hesaptan çıkar.
 * Ayrıntılı açıklama: docs/rehber/app/Actions/Pricing/QuotePriceAction.md
 */
final readonly class PriceQuote
{
    public function __construct(
        public SubscriptionTier $tier,
        public int $listMinor,
        public ?Promotion $promotion,
        public ?GiftCodeQuote $giftCode = null,
    ) {}

    /** Otomatik indirimin tutarı; indirim yoksa 0. */
    public function promotionDiscountMinor(): int
    {
        return $this->promotion?->discountFor($this->listMinor) ?? 0;
    }

    /** Hediye kodunun uygulanacağı taban (K133: kod KALAN fiyata uygulanır). */
    public function afterPromotionMinor(): int
    {
        return $this->listMinor - $this->promotionDiscountMinor();
    }

    public function giftDiscountMinor(): int
    {
        return $this->giftCode === null ? 0 : $this->giftCode->discountMinor;
    }

    /** Toplam indirim: siparişin `discount_minor` kolonu. */
    public function discountMinor(): int
    {
        return $this->promotionDiscountMinor() + $this->giftDiscountMinor();
    }

    public function totalMinor(): int
    {
        return $this->listMinor - $this->discountMinor();
    }

    /** Ödenecek tutar 0: sipariş sağlayıcıya gitmeden hediye siparişi olarak açılır (D1). */
    public function coversEverything(): bool
    {
        return $this->totalMinor() === 0;
    }

    /**
     * Hediye kodu eklenmiş kopya.
     *
     * @throws LogicException Kod başka bir taban üzerinden hesaplanmışsa (programlama hatası)
     */
    public function withGiftCode(GiftCodeQuote $giftCode): self
    {
        // Kod, indirimli fiyat üzerinden hesaplanmış olmalı. Liste fiyatı
        // üzerinden hesaplanmış bir kod toplamı eksiye düşürebilirdi.
        if ($giftCode->tier !== $this->tier || $giftCode->priceMinor !== $this->afterPromotionMinor()) {
            throw new LogicException('Gift code quote must be computed on the discounted price of the same tier.');
        }

        return new self($this->tier, $this->listMinor, $this->promotion, $giftCode);
    }
}
