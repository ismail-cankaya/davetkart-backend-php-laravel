<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Bir hakkın neden iade edilemediği (Faz 14, 14.3 · K139).
 *
 * Değerler API sözleşmesine girer: `REFUND_NOT_ALLOWED` hatasının `reason`
 * parametresi ve hak listesindeki `refund.blocker`. Frontend her birine ayrı
 * bir cümle yazar ("bu hak bir davetiyede kullanılıyor").
 *
 * Sızıntı değil: kullanıcı yalnızca KENDİ siparişini soruyor (H9).
 * Ayrıntılı açıklama: docs/rehber/app/Services/Refund/RefundEligibility.md
 */
enum RefundBlocker: string
{
    /** Zaten karar bekleyen bir talep var. */
    case AlreadyRequested = 'already_requested';

    /** Sipariş ödenmemiş ya da zaten iade edilmiş. */
    case NotPaid = 'not_paid';

    /** Hak bir davetiyede (yayında ya da taslakta) duruyor ya da yandı. */
    case InUse = 'in_use';

    /** Hediye koduyla ücretsiz alınmış: geri gönderilecek para yok. */
    case Free = 'free';

    /** Ödemeden bu yana iade süresi geçti. */
    case WindowClosed = 'window_closed';
}
