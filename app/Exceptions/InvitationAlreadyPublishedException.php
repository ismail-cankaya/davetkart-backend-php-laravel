<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\ErrorCode;
use RuntimeException;

/**
 * Davetiye zaten yayinda — ikinci yayin istegi bir DURUM CATISMASIDIR.
 *
 * 409 doner, 403 degil: 403 "yetkin yok" der ve kullaniciya yapacak bir sey
 * birakmaz; 409 "istegin gecerli ama kaynak zaten o durumda" der. Frontend
 * ikincisinde ekrani tazeleyip yayinlanmis hali gosterebilir (docs/08 §4).
 *
 * 🔴 Neden sessizce basarili donmuyoruz (idempotans)?
 * Yayin ucretlidir ve bir yan etkisi vardir (published_at damgasi, cache
 * temizligi, ilerideki bildirim). "Zaten yayinda" durumunu 200 ile gecistirmek,
 * istemcinin iki kez yayinladigini SANMASINA ve kullanicinin ikinci bir odeme
 * yaptigini dusunmesine yol acar. Ayrimi acikca sunmak dogru olan.
 *
 * Parametresiz: disari verecek bir sey yok. `publishedAt` gonderilmedi cunku
 * davetiye zaten sahibinin — o bilgi GET ile geliyor, hata zarfinda tekrar
 * edilmesi ikinci bir doğruluk kaynağı olurdu.
 * Ayrintili aciklama: docs/rehber/app/Exceptions/InvitationAlreadyPublishedException.md
 */
final class InvitationAlreadyPublishedException extends RuntimeException implements HasErrorCode
{
    public function __construct()
    {
        parent::__construct('Publish rejected: the invitation is already published.');
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::InvitationAlreadyPublished;
    }

    /**
     * @return array<string, mixed>
     */
    public function errorParams(): array
    {
        return [];
    }
}
