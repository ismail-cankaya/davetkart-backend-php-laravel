<?php

declare(strict_types=1);

namespace App\Actions\GiftCode;

use App\Models\GiftCode;
use App\Support\GiftCodes\GiftCodeFormat;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Elle belirlenen, N kez kullanılabilir bir kod yazar (Faz 11): `DUGUN2026` gibi.
 *
 * Düz kod hiçbir yere yazılmaz; yalnızca özet ve son 4 karakter (D3).
 * Ayrıntılı açıklama: docs/rehber/app/Actions/GiftCode/CreateGiftCodeAction.md
 */
final class CreateGiftCodeAction
{
    /** @throws InvalidArgumentException Kod biçimsiz ya da zaten var */
    public function handle(string $code, GiftCodeSpec $spec, ?string $batchId = null): GiftCode
    {
        if (! GiftCodeFormat::isValidCustom($code)) {
            throw new InvalidArgumentException(sprintf(
                'Kod normalize edildikten sonra %d-%d karakter olmali ve yalnizca A-Z, 0-9 icermeli (bosluk ve tire atilir).',
                GiftCodeFormat::CUSTOM_MIN,
                GiftCodeFormat::CUSTOM_MAX,
            ));
        }

        $giftCode = self::fill(new GiftCode, $code, $spec, $batchId);

        // Ön kontrol yok, kısıt var (E2): iki komut aynı anda koşsa da ikinci satır doğamaz.
        // Transaction = savepoint: dışarıda bir transaction varsa ihlal onu zehirlemesin.
        try {
            DB::transaction(static fn (): bool => $giftCode->save());
        } catch (UniqueConstraintViolationException) {
            throw new InvalidArgumentException('Bu kod zaten var (yazilisi farkli olsa bile: buyuk/kucuk harf, bosluk ve tire ayni sayilir).');
        }

        return $giftCode;
    }

    /**
     * Alanlar açıkça atanır (#[Fillable] boş, E7). Toplu üretim de bunu kullanır.
     *
     * @internal GenerateGiftCodeBatchAction için
     */
    public static function fill(GiftCode $giftCode, string $code, GiftCodeSpec $spec, ?string $batchId): GiftCode
    {
        $giftCode->code_hash = GiftCodeFormat::hash($code);
        $giftCode->code_hint = GiftCodeFormat::hint($code);
        $giftCode->label = $spec->label;
        $giftCode->batch_id = $batchId;
        $giftCode->benefit = $spec->benefit;
        $giftCode->tier = $spec->tier;
        $giftCode->percent_off = $spec->percentOff;
        $giftCode->amount_off_minor = $spec->amountOffMinor;
        $giftCode->max_redemptions = $spec->maxRedemptions;
        $giftCode->expires_at = $spec->expiresAt;

        return $giftCode;
    }
}
