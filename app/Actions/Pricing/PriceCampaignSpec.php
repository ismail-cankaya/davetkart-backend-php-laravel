<?php

declare(strict_types=1);

namespace App\Actions\Pricing;

use App\Enums\SubscriptionTier;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * `pricing:campaign:start` seçeneklerinin doğrulanmış hâli (Faz 13). Saf veri.
 *
 * GiftCodeSpec'in kalıbı: komut ham metni verir, burada tipli ve sınırlı bir
 * değere dönüşür; hata mesajları operatörün okuyacağı Türkçe cümlelerdir.
 * Ayrıntılı açıklama: docs/rehber/app/Actions/Pricing/StartPriceCampaignAction.md §2
 */
final readonly class PriceCampaignSpec
{
    /** Veritabanı kısıtıyla aynı (price_campaigns_percent_check). */
    public const MAX_PERCENT = 90;

    private function __construct(
        public int $percent,
        public ?SubscriptionTier $tier,
        public CarbonImmutable $startsAt,
        public ?CarbonImmutable $endsAt,
        public ?string $label,
    ) {}

    /**
     * @param  string|null  $from  YYYY-MM-DD, o günün başı; boş = şimdi
     * @param  string|null  $until  YYYY-MM-DD, o günün sonu; boş = durdurulana kadar
     *
     * @throws InvalidArgumentException
     */
    public static function fromOptions(
        ?string $percent,
        ?string $tier,
        ?string $from,
        ?string $until,
        ?string $label,
        string $timezone,
    ): self {
        $startsAt = self::parseDate($from, $timezone, '--from')?->startOfDay() ?? CarbonImmutable::now();
        $endsAt = self::parseDate($until, $timezone, '--until')?->endOfDay();

        if ($startsAt->lt(CarbonImmutable::now()->setTimezone($timezone)->startOfDay())) {
            throw new InvalidArgumentException('--from gecmiste kaliyor (en erken bugun).');
        }

        if ($endsAt !== null && $endsAt->isPast()) {
            throw new InvalidArgumentException('--until gecmiste kaliyor.');
        }

        if ($endsAt !== null && $endsAt->lte($startsAt)) {
            throw new InvalidArgumentException('--until, --from tarihinden sonra olmali.');
        }

        return new self(
            percent: self::parsePercent($percent),
            tier: self::parseTier($tier),
            startsAt: $startsAt->utc(),
            endsAt: $endsAt?->utc(),
            label: self::parseLabel($label),
        );
    }

    private static function parsePercent(?string $percent): int
    {
        if ($percent === null || preg_match('/^\d{1,3}$/', $percent) !== 1 || (int) $percent < 1 || (int) $percent > self::MAX_PERCENT) {
            throw new InvalidArgumentException(sprintf(
                'Yuzde 1 ile %d arasinda bir tam sayi olmali. Ucretsiz yayin icin hediye kodu kullan (gift-codes:create --benefit=free).',
                self::MAX_PERCENT,
            ));
        }

        return (int) $percent;
    }

    private static function parseTier(?string $tier): ?SubscriptionTier
    {
        if ($tier === null || $tier === '') {
            return null;
        }

        return SubscriptionTier::tryFrom($tier)
            ?? throw new InvalidArgumentException('--tier su degerlerden biri olmali: '.implode(', ', SubscriptionTier::values()).'.');
    }

    private static function parseDate(?string $value, string $timezone, string $option): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1
            ? CarbonImmutable::createFromFormat('!Y-m-d', $value, $timezone)
            : null;

        // createFromFormat 2026-02-31'i 3 Mart'a taşır; geri yazınca aynı mı?
        if (! $date instanceof CarbonImmutable || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException("{$option} YYYY-MM-DD biciminde gecerli bir tarih olmali.");
        }

        return $date;
    }

    private static function parseLabel(?string $label): ?string
    {
        $label = trim((string) $label);

        if (mb_strlen($label) > 120) {
            throw new InvalidArgumentException('--label en fazla 120 karakter olmali.');
        }

        return $label === '' ? null : $label;
    }
}
