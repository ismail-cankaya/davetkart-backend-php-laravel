<?php

declare(strict_types=1);

namespace App\Http\Requests\Media;

use App\Enums\MediaKind;

/**
 * POST /api/media/upload — davetiye SAHIBININ yuklemesi.
 *
 * 🔴 Yalnizca `gallery`. "Sahip her seyi yukleyebilir" demedik: bugun sahibin
 * arayuzunde LCV medyasi yukleyecegi bir yer yok, dolayisiyla o turleri kabul
 * etmek kullanilmayan bir yetki acmak olurdu (en az ayricalik).
 *
 * Yeni bir sahip-turu geldigi gun buraya eklenir — ve o an "bunu sahip mi
 * yukluyor?" sorusu bilincli olarak cevaplanmis olur.
 * Ayrintili aciklama: docs/rehber/app/Http/Requests/Media/StoreMediaRequest.md
 */
final class StoreMediaRequest extends MediaRequest
{
    /** @return list<string> */
    protected function allowedKinds(): array
    {
        return [MediaKind::Gallery->value];
    }
}
