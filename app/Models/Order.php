<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrderScope;
use App\Enums\OrderStatus;
use App\Enums\PromotionKind;
use App\Enums\SubscriptionTier;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Bir plan satin alma kaydi — projenin ticari cekirdegi.
 *
 * 🔴 #[Fillable] listesi BOS. Media modelindeki (Faz 6) ayni gerekce, daha
 * agir sonuclusu: bu tablodaki hicbir alan istemcinin mali degildir.
 *   - tier            -> istemci "gold" der ama KARARI StartCheckoutAction verir
 *   - amount_minor    -> fiyat SUNUCUDAKI config'ten okunur (asla govdeden)
 *   - status/paid_at  -> yalnizca odeme saglayicisinin bildirimi degistirir
 *   - provider_ref    -> saglayici uretir
 *   - provider_payment_id -> saglayicinin bildirimi yazar (Faz 12, K121)
 *
 * Toplu atama acik olsaydi `{"tier":"elit","status":"paid"}` govdesi bir
 * odemeyi bedava yapardi. Alanlarin tamami acikca atanir (E7 ailesi).
 * Ayrintili aciklama: docs/rehber/app/Models/Order.md
 */
#[Fillable([])]
class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory, HasUlids;

    /**
     * Hediye kodunun tamamen karşıladığı siparişin `provider` değeri (Faz 11, D1).
     *
     * Sağlayıcıya gidilmez; sipariş doğrudan `paid` yazılır. `amount_minor = 0`
     * ile birlikte gelir (orders_gift_amount_check kısıtı iki yönü bağlar).
     */
    public const GIFT_PROVIDER = 'gift';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // 🔴 Policy/kaynak karsilastirmalari kati (===) yapiyor; HasUlids
            // yuzunden getIncrementing() false, yani anahtar cast'i otomatik
            // gelmiyor (Faz 3, ders 29). Invitation'daki ayni satir.
            'user_id' => 'integer',

            'tier' => SubscriptionTier::class,
            'status' => OrderStatus::class,

            // 🔴 Siparisin NE SATIN ALDIGI. invitation_id ile karistirma:
            // bu kolon DEGISMEZ, o kolon hakkin su an nerede durdugunu soyler.
            'scope' => OrderScope::class,

            // PostgreSQL surucusu integer'i duruma gore string dondurebilir;
            // karsilastirma buna guvenemez (P4).
            'amount_minor' => 'integer',

            // Faz 11: fiyattan düşülen indirim (kuruş). Fiyat = amount + discount.
            // Faz 13: TOPLAM indirim — otomatik indirim + hediye kodu.
            'discount_minor' => 'integer',

            // Faz 13 (K133): otomatik indirimin kaynağı ve tutarı.
            'promotion' => PromotionKind::class,
            'promotion_discount_minor' => 'integer',

            // K23: degismez tarih.
            'paid_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',

            // Faz 14 (K137): hak yandı. Bağ (invitation_id) kopsa da kalır.
            'consumed_at' => 'immutable_datetime',
        ];
    }

    /**
     * Yayin hakki VEREN siparisler.
     *
     * 🔴 Kural sorguda degil enum'da: hangi durumun hak verdigini
     * OrderStatus::grantsPublishRight() soyler, bu kapsam yalnizca onu
     * SQL'e cevirir. `where('status', 'paid')` yazsaydik kural uc dosyaya
     * dagilirdi (K50'nin Faz 5'te kurdugu desen).
     *
     * @param  Builder<Order>  $query
     */
    public function scopeGrantingPublishRight(Builder $query): void
    {
        $granting = array_values(array_filter(
            OrderStatus::cases(),
            static fn (OrderStatus $status): bool => $status->grantsPublishRight(),
        ));

        $query->whereIn('status', array_column($granting, 'value'));
    }

    /**
     * Hakki bir davetiyeden GERI ALINABILIR siparisler.
     *
     * Kural enum'da (OrderScope::isReleasable()), bu kapsam yalnizca SQL'e
     * ceviriyor.
     *
     * @param  Builder<Order>  $query
     */
    public function scopeReleasable(Builder $query): void
    {
        $releasable = array_values(array_filter(
            OrderScope::cases(),
            static fn (OrderScope $scope): bool => $scope->isReleasable(),
        ));

        $query->whereIn('scope', array_column($releasable, 'value'));
    }

    /**
     * Hakkı YANMAMIŞ siparişler (Faz 14, K137).
     *
     * 🔴 Serbest hak sorgusu bunu da sormak ZORUNDA: yanmış bir hakkın
     * davetiyesi kalıcı silinince `invitation_id` NULL olur ve sipariş
     * serbest bırakılmış bir hakla aynı görünür. Ayırt eden tek şey bu damga.
     *
     * @param  Builder<Order>  $query
     */
    public function scopeUnconsumed(Builder $query): void
    {
        $query->whereNull('consumed_at');
    }

    /** Hak yandı mı? (Faz 14, K137) */
    public function isConsumed(): bool
    {
        return $this->consumed_at !== null;
    }

    /**
     * Siparis, odeme penceresi dolmus mu?
     *
     * `expires_at` NULL ise sure sinirsizdir (saglayici penceresi olmayan
     * akislar icin). null-safe karsilastirma bilerek yok: iki durum farkli
     * bilgidir, `??` ile birlestirmek onlari sessizce esitlerdi (N4).
     */
    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /** Liste fiyatı (kuruş): ödenen + toplam indirim. Saklanmaz, türetilir (Faz 13, E1). */
    public function listPriceMinor(): int
    {
        return $this->amount_minor + $this->discount_minor;
    }

    /** Hediye kodunun tamamen karşıladığı sipariş mi? (Faz 11, D1) */
    public function isGift(): bool
    {
        return $this->provider === self::GIFT_PROVIDER;
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Tekil alimda davetiye; PAKET aliminda null (K42).
     *
     * @return BelongsTo<Invitation, $this>
     */
    public function invitation(): BelongsTo
    {
        return $this->belongsTo(Invitation::class);
    }

    /**
     * İndirimin geldiği kampanya (Faz 13); yalnızca promotion = campaign iken dolu.
     *
     * @return BelongsTo<PriceCampaign, $this>
     */
    public function priceCampaign(): BelongsTo
    {
        return $this->belongsTo(PriceCampaign::class);
    }

    /**
     * Bu siparişte kullanılan hediye kodu (Faz 11). Sipariş başına en fazla bir (UNIQUE).
     *
     * @return HasOne<GiftCodeRedemption, $this>
     */
    public function giftCodeRedemption(): HasOne
    {
        return $this->hasOne(GiftCodeRedemption::class);
    }

    /**
     * Bu siparişin iade talepleri, kapanmışlar dahil (Faz 14, K138).
     *
     * @return HasMany<RefundRequest, $this>
     */
    public function refundRequests(): HasMany
    {
        return $this->hasMany(RefundRequest::class);
    }

    /**
     * Karar bekleyen TEK talep — kısmi UNIQUE indeks en fazla bir tane olmasını garanti eder.
     *
     * @return HasOne<RefundRequest, $this>
     */
    public function openRefundRequest(): HasOne
    {
        return $this->hasOne(RefundRequest::class)->open();
    }

    /**
     * Açık iade talebi OLMAYAN siparişler (Faz 14, K138): talep hakkı dondurur.
     *
     * @param  Builder<Order>  $query
     */
    public function scopeWithoutOpenRefundRequest(Builder $query): void
    {
        $query->whereDoesntHave('openRefundRequest');
    }
}
