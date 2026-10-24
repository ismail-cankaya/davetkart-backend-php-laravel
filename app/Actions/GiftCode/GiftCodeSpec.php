<?php

declare(strict_types=1);

namespace App\Actions\GiftCode;

use App\Enums\GiftCodeBenefit;
use App\Enums\SubscriptionTier;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Üretilecek kodun özellikleri (Faz 11) — komut seçeneklerinden kurulur, doğrulanmış gelir.
 *
 * Kurucu private: geçersiz bir birleşim (free + percent, plansız free) nesne
 * olarak VAR OLAMAZ. Veritabanı CHECK'leri aynı kuralı ikinci kez korur.
 * Ayrıntılı açıklama: docs/rehber/app/Actions/GiftCode/GiftCodeSpec.md
 */
final readonly class GiftCodeSpec
{
    /** Komutta kabul edilen en büyük sabit indirim (₺); yazım hatasına karşı. */
    public const MAX_AMOUNT_TL = 100_000;

    private function __construct(
        public GiftCodeBenefit $benefit,
        public ?SubscriptionTier $tier,
        public ?int $percentOff,
        public ?int $amountOffMinor,
        public int $maxRedemptions,
        public ?CarbonImmutable $expiresAt,
        public ?string $label,
    ) {}

    /**
     * Komut seçeneklerinden spesifikasyon. Hatalar operatöre gösterilecek Türkçe mesajlardır.
     *
     * @param  string|null  $amount  ₺ cinsinden: "150" ya da "150.50" / "150,50"
     * @param  string|null  $expires  YYYY-MM-DD; o günün sonu, $timezone'da
     *
     * @throws InvalidArgumentException
     */
    public static function fromOptions(
        ?string $benefit,
        ?string $tier,
        ?string $percent,
        ?string $amount,
        ?string $maxUses,
        ?string $expires,
        ?string $label,
        string $timezone,
    ): self {
        $benefitCase = GiftCodeBenefit::tryFrom((string) $benefit)
            ?? throw new InvalidArgumentException('--benefit su degerlerden biri olmali: '.implode(', ', GiftCodeBenefit::values()).'.');

        $tierCase = self::parseTier($tier);

        $percentOff = null;
        $amountOffMinor = null;

        switch ($benefitCase) {
            case GiftCodeBenefit::Free:
                self::reject($percent, '--percent yalnizca --benefit=percent ile kullanilir.');
                self::reject($amount, '--amount yalnizca --benefit=fixed ile kullanilir.');

                if ($tierCase === null) {
                    throw new InvalidArgumentException('--benefit=free hangi plani actigini bilmeli: --tier zorunlu.');
                }
                break;

            case GiftCodeBenefit::Percent:
                self::reject($amount, '--amount yalnizca --benefit=fixed ile kullanilir.');
                $percentOff = self::parsePercent($percent);
                break;

            case GiftCodeBenefit::Fixed:
                self::reject($percent, '--percent yalnizca --benefit=percent ile kullanilir.');
                $amountOffMinor = self::parseAmountMinor($amount);
                break;
        }

        return new self(
            benefit: $benefitCase,
            tier: $tierCase,
            percentOff: $percentOff,
            amountOffMinor: $amountOffMinor,
            maxRedemptions: self::parseMaxUses($maxUses),
            expiresAt: self::parseExpiry($expires, $timezone),
            label: self::parseLabel($label),
        );
    }

    /** Tek kullanımlık toplu üretim için: kullanım sayısı her zaman 1 (D9). */
    public function singleUse(): self
    {
        return new self(
            $this->benefit,
            $this->tier,
            $this->percentOff,
            $this->amountOffMinor,
            1,
            $this->expiresAt,
            $this->label,
        );
    }

    private static function parseTier(?string $tier): ?SubscriptionTier
    {
        if ($tier === null || $tier === '') {
            return null;
        }

        return SubscriptionTier::tryFrom($tier)
            ?? throw new InvalidArgumentException('--tier su degerlerden biri olmali: '.implode(', ', SubscriptionTier::values()).'.');
    }

    private static function parsePercent(?string $percent): int
    {
        if ($percent === null || preg_match('/^\d{1,3}$/', $percent) !== 1 || (int) $percent < 1 || (int) $percent > 100) {
            throw new InvalidArgumentException('--percent 1 ile 100 arasinda bir tam sayi olmali.');
        }

        return (int) $percent;
    }

    /**
     * "150" → 15000, "150.5" → 15050, "150,50" → 15050. Float yok: tam ve kesir
     * kısımları ayrı ayrı tam sayıya çevrilir (0.1 + 0.2 sorunu hiç doğmaz).
     */
    private static function parseAmountMinor(?string $amount): int
    {
        if ($amount === null || preg_match('/^(\d{1,6})(?:[.,](\d{1,2}))?$/', $amount, $parts) !== 1) {
            throw new InvalidArgumentException('--amount TL cinsinden olmali: 150 ya da 150.50 (en fazla 2 ondalik).');
        }

        $lira = (int) $parts[1];
        $kurus = (int) str_pad($parts[2] ?? '0', 2, '0');
        $minor = $lira * 100 + $kurus;

        if ($minor < 1 || $lira > self::MAX_AMOUNT_TL) {
            throw new InvalidArgumentException('--amount 0,01 ile '.self::MAX_AMOUNT_TL.' TL arasinda olmali.');
        }

        return $minor;
    }

    private static function parseMaxUses(?string $maxUses): int
    {
        if ($maxUses === null || preg_match('/^\d{1,7}$/', $maxUses) !== 1 || (int) $maxUses < 1) {
            throw new InvalidArgumentException('--max-uses 1 veya daha buyuk bir tam sayi olmali.');
        }

        return (int) $maxUses;
    }

    /** Günün SONU, operatörün saat diliminde; UTC'ye çevrilerek saklanır. */
    private static function parseExpiry(?string $expires, string $timezone): ?CarbonImmutable
    {
        if ($expires === null || $expires === '') {
            return null;
        }

        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $expires) === 1
            ? CarbonImmutable::createFromFormat('!Y-m-d', $expires, $timezone)
            : null;

        // createFromFormat 2026-02-31'i 3 Mart'a taşır; geri yazınca aynı mı?
        if (! $date instanceof CarbonImmutable || $date->format('Y-m-d') !== $expires) {
            throw new InvalidArgumentException('--expires YYYY-MM-DD biciminde gecerli bir tarih olmali.');
        }

        $end = $date->endOfDay();

        if ($end->isPast()) {
            throw new InvalidArgumentException('--expires gecmiste kaliyor.');
        }

        return $end->utc();
    }

    private static function parseLabel(?string $label): ?string
    {
        $label = trim((string) $label);

        if (mb_strlen($label) > 120) {
            throw new InvalidArgumentException('--label en fazla 120 karakter olmali.');
        }

        return $label === '' ? null : $label;
    }

    private static function reject(?string $value, string $message): void
    {
        if ($value !== null && $value !== '') {
            throw new InvalidArgumentException($message);
        }
    }
}
