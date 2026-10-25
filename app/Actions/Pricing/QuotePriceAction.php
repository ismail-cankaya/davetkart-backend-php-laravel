<?php

declare(strict_types=1);

namespace App\Actions\Pricing;

use App\Enums\SubscriptionTier;
use App\Models\Order;
use App\Models\PriceCampaign;
use App\Models\User;
use Illuminate\Support\Facades\Config;
use LogicException;

/**
 * Bir planın fiyatını hesaplar: liste fiyatı + en iyi otomatik indirim (Faz 13, K129–K133).
 *
 * 🔴 Fiyat kuralının TEK yeri. Checkout, hediye kodu önizlemesi ve fiyat uçları
 * buradan geçer; frontend fiyat hesaplamaz, yalnızca bu sonucu gösterir (K128).
 *
 * Kurallar:
 *   1. Liste fiyatı config'ten (`davetkart.tiers.*.price`), istekten ASLA.
 *   2. Adaylar: plana uygun, şu an geçerli kampanyaların en büyüğü ve —
 *      kullanıcının hiç ödenmiş siparişi yoksa — ilk yayın indirimi.
 *   3. Toplanmazlar: büyük olan kazanır; eşitlikte ilk yayın (K133).
 *   4. Hediye kodu burada YOK: çağıran onu kalan fiyata ekler (withGiftCode).
 *
 * Salt okunur: kilit yok, yazma yok.
 * Ayrıntılı açıklama: docs/rehber/app/Actions/Pricing/QuotePriceAction.md
 */
final class QuotePriceAction
{
    /** @param  User|null  $user  null = misafir (fiyat sayfası): ilk yayın adaylığı bilinmez */
    public function handle(?User $user, SubscriptionTier $tier): PriceQuote
    {
        return $this->forTiers($user, [$tier])[$tier->value];
    }

    /**
     * Üç plan birden — fiyat uçları için. Sorgu sayısı plan sayısından bağımsız
     * (bir kampanya sorgusu + bir sipariş sorgusu).
     *
     * @param  list<SubscriptionTier>|null  $tiers  null = hepsi, rank sırasında
     *
     * @return array<string, PriceQuote> plan değeri => fiyat
     */
    public function forTiers(?User $user, ?array $tiers = null): array
    {
        $tiers ??= SubscriptionTier::cases();
        $firstPublish = $this->firstPublishPromotion($user);
        $campaigns = $this->activeCampaigns();
        $quotes = [];

        foreach ($tiers as $tier) {
            $quotes[$tier->value] = new PriceQuote(
                tier: $tier,
                listMinor: $tier->price() * 100,
                promotion: self::best($firstPublish, $this->bestCampaignFor($campaigns, $tier)),
            );
        }

        return $quotes;
    }

    /**
     * İlk yayın indirimi oranı (0 = kapalı). Fiyat sayfası bunu misafire de
     * gösterir: "ilk yayınına %60".
     */
    public static function firstPublishPercent(): int
    {
        $percent = Config::integer('davetkart.pricing.first_publish_percent');

        // Gürültülü: 100 yazılsaydı tutar 0'a iner ve sipariş
        // orders_gift_amount_check'e çarpardı — her checkout'ta anlaşılmaz bir 500.
        if ($percent < 0 || $percent > PriceCampaignSpec::MAX_PERCENT) {
            throw new LogicException('davetkart.pricing.first_publish_percent must be between 0 and '.PriceCampaignSpec::MAX_PERCENT.'.');
        }

        return $percent;
    }

    /**
     * Kullanıcı ilk yayın indirimine uygun mu? (K131)
     *
     * 🔴 "Hiç ödenmiş siparişi yok" = `paid_at` damgalı satır yok. Hediye
     * koduyla bedava açılan sipariş de, sonradan iade edilen ya da başka bir
     * davetiyeye bırakılan sipariş de sayılır: ilk yayın bir kez yaşanır.
     * Bekleyen (pending) sipariş SAYILMAZ: ödeme sayfasından dönen kullanıcı
     * indirimini kaybetmemeli.
     */
    private function firstPublishPromotion(?User $user): ?Promotion
    {
        $percent = self::firstPublishPercent();

        if ($user === null || $percent <= 0) {
            return null;
        }

        $hasPaid = Order::query()
            ->where('user_id', $user->id)
            ->whereNotNull('paid_at')
            ->exists();

        return $hasPaid ? null : Promotion::firstPublish($percent);
    }

    /** @return list<PriceCampaign> */
    private function activeCampaigns(): array
    {
        return array_values(PriceCampaign::query()->activeAt(now()->toImmutable())->get()->all());
    }

    /**
     * Plana uygun en büyük kampanya. Eşitlikte en GEÇ biten kazanır (müşteriye
     * en uzun "şu tarihe kadar" bilgisini verir); süresiz en geç sayılır.
     *
     * @param  list<PriceCampaign>  $campaigns
     */
    private function bestCampaignFor(array $campaigns, SubscriptionTier $tier): ?Promotion
    {
        $best = null;

        foreach ($campaigns as $campaign) {
            if ($campaign->tier !== null && $campaign->tier !== $tier) {
                continue;
            }

            if ($best === null || $campaign->percent > $best->percent
                || ($campaign->percent === $best->percent && self::endsLater($campaign, $best))) {
                $best = $campaign;
            }
        }

        return $best === null ? null : Promotion::campaign($best);
    }

    private static function endsLater(PriceCampaign $a, PriceCampaign $b): bool
    {
        if ($b->ends_at === null) {
            return false;
        }

        return $a->ends_at === null || $a->ends_at > $b->ends_at;
    }

    /** K133: toplanmaz, büyük olan; eşitlikte ilk yayın. */
    private static function best(?Promotion $firstPublish, ?Promotion $campaign): ?Promotion
    {
        if ($firstPublish === null || $campaign === null) {
            return $firstPublish ?? $campaign;
        }

        return $campaign->percent > $firstPublish->percent ? $campaign : $firstPublish;
    }
}
