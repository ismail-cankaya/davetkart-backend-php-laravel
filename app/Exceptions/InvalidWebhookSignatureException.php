<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\ErrorCode;
use RuntimeException;

/**
 * Webhook imzasi dogrulanamadi.
 *
 * 🔴 YANIT 404 — ve bu bilincli bir sessizliktir (L2/L6).
 *
 * Alternatifler ve neden reddedildiler:
 *
 * | Kod | Ne der | Neden hayir |
 * |-----|--------|-------------|
 * | 401 | "imzan gecersiz" | Saldirgana imzanin KONTROL EDILDIGINI ve denemeye
 * |     |                  | devam etmesi gerektigini soyler; frontend'in
 * |     |                  | interceptor'i da 401'de oturum dusurur (docs/08 §4)
 * | 403 | "bu uca giremezsin" | Ucun VARLIGINI dogrular (H7'nin ayni gerekcesi)
 * | 400 | "govde bozuk" | Bozuk govde ile sahte imzayi ayirt ettirir
 * | 404 | "boyle bir sey yok" | ✅ Hicbir ayrim vermez
 *
 * Mesru saglayici bu yanitla hic karsilasmaz (imzasi dogrudur); karsilasan
 * yalnizca deneyen taraftir ve ona ogretecek bir sey yoktur. Faz 5'in
 * honeypot'u ile ayni fikir: reddin KENDISI bir bilgi sizintisidir.
 *
 * Gercek sebep KODDA ve LOG'da acik, YANITTA gizli — RegistrationFailed
 * exception'inin Faz 2'de kurdugu desen (K20 §3.1).
 * Ayrintili aciklama: docs/rehber/app/Exceptions/InvalidWebhookSignatureException.md
 */
final class InvalidWebhookSignatureException extends RuntimeException implements HasErrorCode
{
    public function __construct()
    {
        parent::__construct('Webhook rejected: signature verification failed.');
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::ResourceNotFound;
    }

    /**
     * @return array<string, mixed>
     */
    public function errorParams(): array
    {
        return [];
    }
}
