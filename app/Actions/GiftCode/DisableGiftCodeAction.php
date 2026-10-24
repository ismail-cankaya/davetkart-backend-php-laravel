<?php

declare(strict_types=1);

namespace App\Actions\GiftCode;

use App\Models\GiftCode;

/**
 * Kodu kapatır (Faz 11). Silmez: kullanım satırları koda bağlı (denetim izi).
 *
 * Yeni kullanım açılmaz; var olan siparişler etkilenmez (ödenmiş hediye
 * siparişi geri alınmaz, bekleyen ödeme tamamlanabilir).
 * Ayrıntılı açıklama: docs/rehber/app/Actions/GiftCode/DisableGiftCodeAction.md
 */
final class DisableGiftCodeAction
{
    /** @return bool false = zaten kapalıydı; damga değişmez */
    public function handle(GiftCode $giftCode): bool
    {
        // Koşul UPDATE'in içinde (E2): iki operatör aynı anda kapatsa da ilk damga kalır.
        $affected = GiftCode::query()
            ->whereKey($giftCode->getKey())
            ->whereNull('disabled_at')
            ->update(['disabled_at' => now(), 'updated_at' => now()]);

        $giftCode->refresh();

        return $affected === 1;
    }
}
