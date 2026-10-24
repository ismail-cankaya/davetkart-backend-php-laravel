<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\GiftCode\PreviewGiftCodeAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\GiftCode\PreviewGiftCodeRequest;
use App\Http\Resources\GiftCodeQuoteResource;
use App\Models\User;

/**
 * Hediye kodu uçları (Faz 11) — yalnızca yönlendirir (K3).
 *
 * Kodu KULLANMAK ayrı bir uç değil: checkout gövdesindeki `giftCode` alanı.
 * Ayrıntılı açıklama: docs/rehber/app/Http/Controllers/Api/V1/GiftCodeController.md
 */
final class GiftCodeController extends Controller
{
    /** POST /api/gift-codes/preview — 200, hiçbir şey ayrılmaz. */
    public function preview(PreviewGiftCodeRequest $request, PreviewGiftCodeAction $action): GiftCodeQuoteResource
    {
        /** @var User $user auth:sanctum null olmadığını garanti eder */
        $user = $request->user();

        return new GiftCodeQuoteResource($action->handle($user, $request->code(), $request->tier()));
    }
}
