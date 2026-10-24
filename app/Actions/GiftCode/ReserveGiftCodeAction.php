<?php

declare(strict_types=1);

namespace App\Actions\GiftCode;

use App\Enums\SubscriptionTier;
use App\Exceptions\GiftCodeException;
use App\Models\GiftCode;
use App\Models\User;
use App\Support\GiftCodes\GiftCodeFormat;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Kodu siparişe ayırır: satırı KİLİTLEYEREK bulur, kuralları uygular (Faz 11).
 *
 * 🔴 Çağıranın açtığı transaction içinde çalışır. Kilit, kullanım satırı aynı
 * transaction'da yazılana kadar tutulmalı; aksi halde iki istek aynı son
 * hakkı görür (E9 check-then-act). Kullanım satırını yazmak çağıranın işi.
 * Ayrıntılı açıklama: docs/rehber/app/Actions/GiftCode/ReserveGiftCodeAction.md
 */
final class ReserveGiftCodeAction
{
    public function __construct(
        private readonly QuoteGiftCodeAction $quote,
    ) {}

    /**
     * @param  string  $code  Kullanıcının yazdığı haliyle; normalize edilip özetlenir
     *
     * @throws GiftCodeException
     * @throws LogicException Transaction dışında çağrıldı (programlama hatası)
     */
    public function handle(User $user, string $code, SubscriptionTier $tier): GiftCodeQuote
    {
        // Transaction yoksa lockForUpdate sorgu bitince bırakılır: koruma yok.
        if (DB::transactionLevel() === 0) {
            throw new LogicException('ReserveGiftCodeAction must run inside a database transaction.');
        }

        $giftCode = GiftCode::query()
            ->where('code_hash', GiftCodeFormat::hash($code))
            ->lockForUpdate()
            ->first();

        return $this->quote->handle($giftCode, $user, $tier);
    }
}
