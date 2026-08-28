<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\ErrorCode;

/**
 * Kendi hata kodunu KENDISI bilen exception.
 *
 * 🔴 Bu arayuz H11'in mekanik yukunu kaldirir. Onceden her yeni exception,
 * ApiExceptionRenderer::resolveCode() icindeki match zincirine bir kol
 * eklemeyi gerektiriyordu; eklenmezse SERVER_ERROR (500) donuyordu, yani
 * bir ISTEMCI hatasi SUNUCU hatasi gibi gorunuyordu.
 *
 * Artik kod exception'in kendi ustunde durur. Renderer tek bir kol tasir:
 * "bu exception kodunu biliyor mu? bilsin."
 *
 * Kural degismedi, TASIYICISI degisti: H11 hatirlanmasi gereken bir adim
 * olmaktan cikip tip sisteminin sordugu bir soruya donustu.
 * Ayrintili aciklama: docs/rehber/app/Exceptions/HasErrorCode.md
 */
interface HasErrorCode
{
    /** Sozlesmedeki hata kodu; HTTP durumu ErrorCode::status()'tan gelir. */
    public function errorCode(): ErrorCode;

    /**
     * Zarfin `params` alanina aday degerler.
     *
     * 🔴 Bu bir IZIN degil, bir ONERIDIR: donen dizi her zaman
     * ErrorCode::filterParams() beyaz listesinden gecirilir (H9/H12).
     * Beyaz listede adi gecmeyen anahtar sessizce duser.
     *
     * @return array<string, mixed>
     */
    public function errorParams(): array;
}
