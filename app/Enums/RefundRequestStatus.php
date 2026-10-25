<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Bir iade (iptal) talebinin yaşam döngüsü (Faz 14, 14.2 · K138).
 *
 * 🔴 Talep bir SİPARİŞ durumu değil. Sipariş `paid` kalır; para geri
 * gönderildiğinde (yönetici `refunds:approve`) `refunded` olur. Talebi
 * OrderStatus'a bir vaka olarak eklemek, ödeme durum makinesine bir İŞ
 * AKIŞI durumunu karıştırırdı: webhook "paid -> refunded" gördüğünde
 * talep ara durumunu bilmek zorunda kalırdı.
 * Ayrıntılı açıklama: docs/rehber/app/Enums/RefundRequestStatus.md
 */
enum RefundRequestStatus: string
{
    /** Kullanıcı istedi, yönetici karar vermedi. Hak DONDURULUR. */
    case Requested = 'requested';

    /** Para geri gönderildi; sipariş `refunded`. */
    case Approved = 'approved';

    /** Yönetici reddetti; hak kullanıcıya geri döner. */
    case Rejected = 'rejected';

    /** Kullanıcı vazgeçti; hak geri döner. */
    case Withdrawn = 'withdrawn';

    /** Karar bekleniyor mu? Açık talep hakkı dondurur. */
    public function isOpen(): bool
    {
        return $this === self::Requested;
    }

    /**
     * Meşru geçişler: yalnızca açık talep kapanır, kapanan bir daha açılmaz.
     * Yeniden istemek YENİ bir satırdır (geçmiş yazılmaz).
     */
    public function canTransitionTo(self $next): bool
    {
        return $this === self::Requested && $next !== self::Requested;
    }

    /**
     * Veritabanı CHECK kısıtı için ham değerler (K39).
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
