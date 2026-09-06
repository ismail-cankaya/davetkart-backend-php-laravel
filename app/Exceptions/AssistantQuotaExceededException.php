<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\ErrorCode;
use RuntimeException;

/**
 * Kullanicinin GUNLUK asistan mesaj butcesi doldu.
 *
 * 🔴 429 doner, 403 DEGIL — ve bu, K28'in bilincli bir istisnasi degil,
 * K28'in ayni testinin farkli cevap vermesidir. K28 soruyordu: "kullanici
 * bu siniri BEKLEYEREK asabilir mi?" LCV kotasinda cevap HAYIR'di (satin
 * alinan planin kapasitesi neyse odur) ve 403 dogruydu. Asistan kotasinda
 * cevap EVET: butce gece yarisi yenilenir. Kural degismedi, CAGIRAN degisti
 * (ders 51'in ayni kalibi).
 *
 * 🔴 RsvpQuotaExceededException'dan ikinci farki: o sinif parametre ALMIYOR
 * cunku kotayi asan taraf ANONIM bir misafirdi ve kota durumu yalnizca
 * davetiye sahibinindi (H9). Burada asan taraf hesabin SAHIBIDIR — kendi
 * hakkini ogrenmesi bir sizinti degil, dogru davranistir.
 *
 * Ders 55: ozellik adlari `$retryAfterSeconds` ve `$dailyLimit`. `$code`,
 * `$message`, `$file`, `$line` Exception'in kendi ozellikleridir ve alt
 * sinifta YASAKLI adlardir.
 * Ayrintili aciklama: docs/rehber/app/Exceptions/AssistantQuotaExceededException.md
 */
final class AssistantQuotaExceededException extends RuntimeException implements HasErrorCode
{
    public function __construct(
        /** Butcenin yenilenmesine kalan saniye — gun sinirina kadar. */
        private readonly int $retryAfterSeconds,

        /** Gunluk hak (config: davetkart.assistant.daily_message_limit_per_user). */
        private readonly int $dailyLimit,
    ) {
        parent::__construct('Assistant rejected: the daily message budget is spent.');
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::AssistantQuotaExceeded;
    }

    /**
     * @return array<string, mixed>
     */
    public function errorParams(): array
    {
        return [
            'retryAfter' => $this->retryAfterSeconds,
            'limit' => $this->dailyLimit,
        ];
    }
}
