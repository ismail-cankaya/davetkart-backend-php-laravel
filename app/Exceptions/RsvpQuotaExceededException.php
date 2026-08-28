<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\ErrorCode;
use RuntimeException;

/**
 * Davetiyenin LCV kotasi doldu.
 *
 * 403 doner, 429 DEGIL (K28): 429 bir HIZ sinirdir ve "yavasla, sonra tekrar
 * dene" demektir. Bizimki bir KAPASITE siniri — misafir bekleyerek asamaz,
 * `Retry-After` yaniltici olurdu.
 *
 * 🔴 KURUCU PARAMETRE ALMAZ ve errorParams() BOS DONER.
 *
 * `remaining` / `limit` degerleri `ErrorCode::allowedParams()` beyaz listesinde
 * duruyor, ama 08 §3.4 onlari YALNIZCA DAVETIYE SAHIBINE veriyor (H9). Bu
 * exception'in bugunku tek firlatma yeri ANONIM misafirin gonderim ucudur.
 *
 * Kurali yorumla degil YAPIYLA korumak icin sinif o degerleri tasiyamaz hale
 * getirildi — InvalidCredentialsException'daki ayni desen (A2). Sahibe donuk
 * bir kota ucu dogdugunda (Faz 7) ikinci bir adlandirilmis kurucu eklenir;
 * bugun eklenirse hicbir yerden cagrilmayan olu kod olur (ders 26).
 * Ayrintili aciklama: docs/rehber/app/Exceptions/RsvpQuotaExceededException.md
 */
final class RsvpQuotaExceededException extends RuntimeException implements HasErrorCode
{
    public function __construct()
    {
        parent::__construct('RSVP rejected: the invitation has reached its guest quota.');
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::RsvpQuotaExceeded;
    }

    /**
     * Bos: anonim misafir kota durumunu OGRENMEMELI (H9).
     *
     * @return array<string, mixed>
     */
    public function errorParams(): array
    {
        return [];
    }
}
