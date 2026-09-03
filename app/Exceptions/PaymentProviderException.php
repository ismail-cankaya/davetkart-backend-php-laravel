<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\ErrorCode;
use RuntimeException;
use Throwable;

/**
 * Odeme saglayicisi tarafinda bir sorun var.
 *
 * 🔴 IKI adlandirilmis kurucu, IKI FARKLI 5xx — ve ayrim RFC 9110'a gore
 * yapilir (K27). Fark izleme (monitoring) alarmini yonlendirdigi icin
 * onemlidir: biri "onlarda", digeri "bizde/gecici" der.
 *
 * | Kurucu        | Kod                    | Durum | Sorun nerede |
 * |---------------|------------------------|-------|--------------|
 * | rejected()    | PAYMENT_PROVIDER_ERROR | 502   | Yukari akis CEVAP VERDI ama hatali |
 * | unavailable() | PROVIDER_UNAVAILABLE   | 503   | Bu serviste: saglayici yapilandirilmamis / erisilemiyor |
 *
 * 500 DEGIL: 500 "bizim kodumuzda yakalanmamis bir hata" demektir ve
 * gecerlidir — ama burada hatanin kaynagini BILIYORUZ. Bilinen bir hatayi
 * 500 ile bildirmek, izleme grafiginde gercek 500'lerin arasina karisir.
 *
 * 🔴 H8: saglayicinin HAM hatasi yanita GIRMEZ. Orijinal exception `previous`
 * olarak tasinir (log'a ve yerel `debug` blogundaki zincire gider), zarfa
 * yalnizca kod cikar.
 * Ayrintili aciklama: docs/rehber/app/Exceptions/PaymentProviderException.md
 */
final class PaymentProviderException extends RuntimeException implements HasErrorCode
{
    /**
     * Istemciye onerilen bekleme suresi (saniye).
     *
     * Config'te DEGIL sinif sabiti: bu bir is ayari degil, bir HTTP nezaket
     * degeri. config'e konsaydi "ayarlanmasi gereken bir sey" gibi gorunur ve
     * kimse dokunmayacagi icin olu bir anahtar olurdu (ders 46'nin tersi
     * yonu: kalici olani kalici goruncen yere koy).
     */
    private const RETRY_AFTER_SECONDS = 60;

    private function __construct(
        private readonly ErrorCode $code,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /** Saglayici cevap verdi ama islemi reddetti / hatali yanit dondu -> 502. */
    public static function rejected(?Throwable $previous = null): self
    {
        return new self(
            ErrorCode::PaymentProviderError,
            'Payment provider returned an error response.',
            $previous,
        );
    }

    /** Saglayici yapilandirilmamis ya da hic erisilemiyor -> 503. */
    public static function unavailable(string $driver, ?Throwable $previous = null): self
    {
        return new self(
            ErrorCode::ProviderUnavailable,
            "Payment provider '{$driver}' is not available.",
            $previous,
        );
    }

    public function errorCode(): ErrorCode
    {
        return $this->code;
    }

    /**
     * @return array<string, mixed>
     */
    public function errorParams(): array
    {
        // H12: beyaz liste yolun uzerinde. 502'nin allowedParams()'i BOS
        // oldugu icin bu deger yalnizca 503'te disari cikar — yani "ne zaman
        // tekrar dene" bilgisi tam da RFC 9110 §10.2.3'un istedigi yerde
        // (Retry-After 429 ve 503 ile gonderilir), digerinde sessizce duser.
        return ['retryAfter' => self::RETRY_AFTER_SECONDS];
    }
}
