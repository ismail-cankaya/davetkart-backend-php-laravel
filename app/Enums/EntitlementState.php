<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Bir siparişin hakkı ŞU AN nerede? "Planlarım" ekranının kovaları (Faz 14, 14.3 · K140).
 *
 * Saklanmaz, her okumada siparişten TÜRETİLİR: ikinci bir "kredi" kolonu iki
 * doğruluk kaynağı demekti ve bir gün ayrışırdı (E1, K39).
 * Ayrıntılı açıklama: docs/rehber/app/Actions/Account/SummarizeEntitlementsAction.md
 */
enum EntitlementState: string
{
    /** Ödenmiş, hiçbir davetiyeye bağlı değil: bir sonraki yayında kullanılır. */
    case Available = 'available';

    /** Ödenmiş, bir davetiyede (yayında ya da taslakta). */
    case InUse = 'in_use';

    /** Pencere kapandıktan sonra silinen bir davetiyede yandı (K137). */
    case Consumed = 'consumed';

    /** İptal istendi, karar bekleniyor; hak dondurulmuş (K138). */
    case RefundRequested = 'refund_requested';

    /** Para geri ödendi. */
    case Refunded = 'refunded';

    /** Ödeme başlatıldı, onay gelmedi (ör. havale). Hak DEĞİL. */
    case Pending = 'pending';

    /**
     * Sayaç sütunlarının sırası ve API'deki anahtar adı.
     *
     * camelCase dönüşümü Resource'ta yapılır; bu yalnızca enum'un kendi sırası.
     *
     * @return list<self>
     */
    public static function ordered(): array
    {
        return [self::Available, self::InUse, self::RefundRequested, self::Pending, self::Consumed, self::Refunded];
    }
}
