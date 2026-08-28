<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\ErrorCode;
use RuntimeException;

/**
 * Davetiyenin bu turden dosya kotasi doldu.
 *
 * 403 doner, 413 DEGIL: 413 (FILE_TOO_LARGE) tek bir dosyanin BOYUTU icindir.
 * Buradaki sinir ADET — dosya kucuk olsa da reddedilir. Ve 429 da degil
 * (K28): kota bir KAPASITE sinirdir, bekleyerek asilamaz.
 *
 * 🔴 IKI ADLANDIRILMIS KURUCU, cunku iki ayri okuyucu var (H9):
 *   forOwner() -> sahibin galeri yuklemesi; `limit` verilebilir, zaten kendi plani
 *   forGuest() -> misafirin LCV yuklemesi; ic sayac VERILMEZ
 *
 * Faz 5'te RsvpQuotaExceededException'in kurucusu parametresizdi cunku tek
 * firlatma yeri anonimdi. Burada iki yol da var ve IKISININ DE cagirani var —
 * bu yuzden iki kurucu olu kod degil (ders 26).
 * Ayrintili aciklama: docs/rehber/app/Exceptions/MediaQuotaExceededException.md
 */
final class MediaQuotaExceededException extends RuntimeException implements HasErrorCode
{
    /**
     * @param  int|null  $limit  Yalnizca sahibe verilir; misafirde null.
     */
    private function __construct(private readonly ?int $limit)
    {
        parent::__construct('Media upload rejected: the invitation has reached its file quota.');
    }

    /** Davetiye sahibi: kendi planinin sinirini ogrenebilir. */
    public static function forOwner(int $limit): self
    {
        return new self($limit);
    }

    /**
     * Anonim misafir: kota doldugunu ogrenir, KAC oldugunu ogrenmez (H9).
     *
     * Kurucu `private`; disaridan `new` ile limit gecirmek MUMKUN DEGIL.
     * Kural yorumla degil sinifin sekliyle korunuyor (A2 ailesi).
     */
    public static function forGuest(): self
    {
        return new self(null);
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::MediaQuotaExceeded;
    }

    /**
     * @return array<string, mixed>
     */
    public function errorParams(): array
    {
        // H12 yine de yolun uzerinde: donen her sey filterParams()'tan gecer.
        return $this->limit === null ? [] : ['limit' => $this->limit];
    }
}
