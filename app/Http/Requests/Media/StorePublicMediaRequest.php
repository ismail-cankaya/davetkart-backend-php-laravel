<?php

declare(strict_types=1);

namespace App\Http\Requests\Media;

use App\Enums\MediaKind;

/**
 * POST /api/public/invitations/{invitation}/media — MISAFIRIN yuklemesi.
 *
 * 🔴 Kabul edilen turler ELLE yazilmaz, MediaKind::isGuestUploadable()'dan
 * TURETILIR. Iki liste olsaydi biri degisip digeri unutuldugunda misafir
 * galeriye yukleyebilirdi — ve testler de ayni listeye baktigi icin hicbir
 * test bunu soylemezdi (C3).
 * Ayrintili aciklama: docs/rehber/app/Http/Requests/Media/StorePublicMediaRequest.md
 */
final class StorePublicMediaRequest extends MediaRequest
{
    /** @return list<string> */
    protected function allowedKinds(): array
    {
        return MediaKind::guestUploadableValues();
    }
}
