<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\ErrorCode;
use RuntimeException;
use Throwable;

/**
 * AI saglayicisi tarafinda bir sorun var — dis dunyaya TEK bir kod cikar.
 *
 * 🔴 H8: saglayicinin HAM hatasi yanita GIRMEZ. Gemini'nin dondurdugu
 * mesaj model adini, kota durumunu, hatta anahtarin bir parcasini
 * tasiyabilir. Orijinal exception `previous` olarak tasinir (log'a ve yerel
 * `debug` zincirine gider); zarfa yalnizca PROVIDER_UNAVAILABLE cikar.
 *
 * 🔴 PaymentProviderException'dan farki: orada IKI kod vardi (502/503) cunku
 * K27 gerekçesi "yukari akis cevap verdi ama hatali" ile "hic ulasilamadi"
 * ayrimini IZLEME ALARMI icin anlamli kiliyordu — odeme akisinda biz bir
 * gateway'iz. Burada ayni ayrimi YAPMIYORUZ ve bu bilincli bir karardir:
 * kullanicinin onundeki eylem her iki halde de aynidir ("asistan simdi
 * cevap veremiyor, birazdan dene"). Frontend'in ayirt edip FARKLI bir sey
 * yapacagi bir durum dogdugunda ikinci kod eklenir — bugun eklenirse hicbir
 * yerden okunmayan olu bir sozlesme maddesi olur (ders 26).
 *
 * 🔴 Ders 55'e dikkat: bu sinifta `$code` diye bir OZELLIK YOK. Tek bir kod
 * dondurdugu icin alana hic ihtiyac olmadi — Exception::$code'u golgeleyecek
 * bir yuzey bastan olusmadi. Kural, hatirlanmasi gereken bir adim olmaktan
 * cikip yapinin bir sonucu oldu.
 * Ayrintili aciklama: docs/rehber/app/Exceptions/AiProviderException.md
 */
final class AiProviderException extends RuntimeException implements HasErrorCode
{
    /**
     * Istemciye onerilen bekleme suresi (saniye).
     *
     * PaymentProviderException 60 sn oneriyor; burada 30 sn, cunku bir AI
     * cagrisinin gecici arizasi (zaman asimi, anlik 429) tipik olarak bir
     * odeme saglayicisinin kesintisinden kisa surer. Config'te DEGIL sinif
     * sabiti: bu bir is ayari degil, bir HTTP nezaket degeri.
     */
    private const RETRY_AFTER_SECONDS = 30;

    private function __construct(string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Surucu YAPILANDIRILMAMIS: bilinmeyen `ai.default`, eksik API anahtari.
     *
     * K70'in AI eksenindeki hali. Sessiz bir varsayilana ("anahtar yoksa
     * NullProvider kullan") DUSMUYORUZ: o durumda uretimde GEMINI_API_KEY
     * unutuldugu gun asistan sahte cevaplar dondurur ve kimse fark etmez.
     */
    public static function unavailable(string $driver): self
    {
        return new self("AI provider '{$driver}' is not available.");
    }

    /**
     * Cagri YAPILDI ama kullanilabilir bir cevap alinamadi.
     *
     * Kapsami bilerek genis: baglanti hatasi, zaman asimi, 4xx/5xx yanit,
     * bos veya guvenlik filtresine takilmis cevap. Dordu de kullanici icin
     * ayni sonucu dogurur; ayrimi LOG tasir, sozlesme degil.
     */
    public static function unreachable(string $driver, ?Throwable $previous = null): self
    {
        return new self("AI provider '{$driver}' did not return a usable reply.", $previous);
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::ProviderUnavailable;
    }

    /**
     * @return array<string, mixed>
     */
    public function errorParams(): array
    {
        // H12: beyaz liste cagri yolunun uzerinde. PROVIDER_UNAVAILABLE'in
        // allowedParams()'i 'retryAfter' iceriyor (RFC 9110 §10.2.3: Retry-After
        // 429 ve 503 ile gonderilir).
        return ['retryAfter' => self::RETRY_AFTER_SECONDS];
    }
}
