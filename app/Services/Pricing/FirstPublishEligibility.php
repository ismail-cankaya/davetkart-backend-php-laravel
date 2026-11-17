<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Enums\PromotionKind;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * "Bu kullanıcı, BU hedef için şu an ilk yayın indirimine uygun mu?" (Faz 18.3, SEC-01 · K159)
 *
 * 🔴 Kuralın TEK yeri. Fiyat önizlemesi de checkout da buraya sorar; iki kopya
 * olsaydı ekrandaki fiyat ile karttan çekilen fiyat ayrışırdı (K128).
 *
 * İndirim tek kullanımlık bir haktır ve iki şekilde kapanır:
 *   1. Kullanılmış  -> `paid_at` damgalı bir sipariş var (K131).
 *   2. AYRILMIŞ     -> başka bir hedefin ödeme bekleyen siparişi onu tutuyor.
 *
 * Salt okunur: kilit yok, yazma yok. Kilidi alan StartCheckoutAction'dır.
 * Ayrıntılı açıklama: docs/rehber/app/Services/Pricing/FirstPublishEligibility.md
 */
final class FirstPublishEligibility
{
    /**
     * @param  string|null  $invitationId  Fiyatı sorulan HEDEF: davetiye kimliği; null = davetiyesiz (paket)
     */
    public function isEligible(User $user, ?string $invitationId): bool
    {
        return ! Order::query()
            ->where('user_id', $user->id)
            ->where(static function (Builder $closing) use ($invitationId): void {
                $closing
                    // 1. K131: ilk yayın bir kez yaşanır (hediye ve iade dahil).
                    ->whereNotNull('paid_at')
                    // 2. K159: hak BAŞKA bir hedefin açık siparişinde ayrılmış.
                    ->orWhere(static function (Builder $reserved) use ($invitationId): void {
                        $reserved->awaitingPayment()->where('promotion', PromotionKind::FirstPublish->value);

                        self::exceptTarget($reserved, $invitationId);
                    });
            })
            ->exists();
    }

    /**
     * Hedefi `$invitationId` OLMAYAN satırlar. Aynı hedefin siparişi sayılmaz:
     * ödeme sayfasına dönen kullanıcı kendi ayırdığı indirimi kaybetmemeli.
     *
     * @param  Builder<Order>  $query
     */
    private static function exceptTarget(Builder $query, ?string $invitationId): void
    {
        if ($invitationId === null) {
            $query->whereNotNull('invitation_id');

            return;
        }

        // `!=` NULL'ı eşleştirmez: davetiyesiz satır da "başka hedef"tir, ayrıca sorulur.
        $query->where(static function (Builder $other) use ($invitationId): void {
            $other->whereNull('invitation_id')->orWhere('invitation_id', '!=', $invitationId);
        });
    }
}
