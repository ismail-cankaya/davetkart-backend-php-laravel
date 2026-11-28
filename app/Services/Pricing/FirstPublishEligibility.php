<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Enums\PromotionKind;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * "Bu kullanıcı, BU hedef için şu an ilk yayın indirimine uygun mu?" (Faz 18.3, SEC-01 · K159)
 * 🔴 Kuralın TEK yeri. Fiyat önizlemesi de checkout da buraya sorar; iki kopya
 * Ayrıntı: docs/rehber/app/Services/Pricing/FirstPublishEligibility.md §K18-1
 */
final class FirstPublishEligibility
{
    /**
     * @param  string|null  $invitationId  Fiyatı sorulan HEDEF: davetiye kimliği; null = davetiyesiz (paket)
     */
    public function isEligible(User $user, ?string $invitationId): bool
    {
        // K191, K276'da korundu: indirim hesaba bağlı; hesap ancak doğrulanmış adresle kanıtlanır.
        if (! $user->hasVerifiedEmail()) {
            return false;
        }

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
