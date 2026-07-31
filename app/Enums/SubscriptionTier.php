<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Satın alınabilir plan seviyeleri.
 *
 * Değerler frontend sözleşmesiyle birebir aynıdır (src/types.ts → SubscriptionTier).
 * Fiyat/kota/sıra bilgisi burada gömülü değil, config/davetkart.php'den okunur.
 * Ayrıntılı açıklama: docs/rehber/app/Enums/SubscriptionTier.md
 */
enum SubscriptionTier: string
{
    case Standart = 'standart';
    case Gold = 'gold';
    case Elit = 'elit';

    /** Kapsama karşılaştırması için sayısal sıra (standart=0 < gold=1 < elit=2). */
    public function rank(): int
    {
        return (int) $this->config('rank');
    }

    /** Tek seferlik yayın ücreti (TL). Sunucu tarafındaki tek doğru kaynak. */
    public function price(): int
    {
        return (int) $this->config('price');
    }

    /** İzin verilen toplam misafir sayısı; null = sınırsız. */
    public function rsvpLimit(): ?int
    {
        $limit = $this->config('rsvp_limit');

        return $limit === null ? null : (int) $limit;
    }

    /** Bu plan, verilen planın gerektirdiklerini karşılıyor mu? */
    public function covers(self $required): bool
    {
        return $this->rank() >= $required->rank();
    }

    /** Arayüzde gösterilecek ad. */
    public function label(): string
    {
        return match ($this) {
            self::Standart => 'Standart',
            self::Gold => 'Gold',
            self::Elit => 'Elit',
        };
    }

    /** Rank sırasına göre en düşük plan — karşılaştırmalarda başlangıç değeri. */
    public static function lowest(): self
    {
        return self::Standart;
    }

    private function config(string $key): mixed
    {
        return config("davetkart.tiers.{$this->value}.{$key}");
    }
}
