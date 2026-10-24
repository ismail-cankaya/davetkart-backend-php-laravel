<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrderStatus;
use Database\Factories\GiftCodeRedemptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bir hediye kodunun bir siparişte kullanımı (Faz 11).
 *
 * 🔴 #[Fillable] boş (E7): satırı yalnızca StartCheckoutAction yazar, alanları açıkça atar.
 * Kullanımın aktif olup olmadığı burada değil SİPARİŞİN durumunda yazar (D4).
 * Ayrıntılı açıklama: docs/rehber/app/Models/GiftCodeRedemption.md
 */
#[Fillable([])]
class GiftCodeRedemption extends Model
{
    /** @use HasFactory<GiftCodeRedemptionFactory> */
    use HasFactory, HasUlids;

    /** Satır değişmez; yalnızca oluşturulma anı var. */
    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // HasUlids yüzünden anahtar cast'i otomatik gelmiyor (Faz 3, ders 29).
            'user_id' => 'integer',
            'discount_minor' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * Kullanımı KODA İADE EDEN sipariş durumları.
     *
     * 🔴 Bilerek "aktif olanlar" değil "iade edenler" listesi: yarın eklenen
     * yeni bir durum varsayılan olarak kullanımı TUTAR. Yanlış yöndeki hata
     * fazladan bir kullanım hakkı, yani para olurdu.
     *
     * @return list<OrderStatus>
     */
    public static function releasingStatuses(): array
    {
        return [OrderStatus::Failed, OrderStatus::Expired];
    }

    /**
     * Aktif kullanımlar: bağlı sipariş pending, paid ya da refunded (D4, D8).
     *
     * @param  Builder<GiftCodeRedemption>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereHas('order', static function (Builder $order): void {
            $order->whereNotIn('status', array_column(self::releasingStatuses(), 'value'));
        });
    }

    /** @return BelongsTo<GiftCode, $this> */
    public function giftCode(): BelongsTo
    {
        return $this->belongsTo(GiftCode::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
