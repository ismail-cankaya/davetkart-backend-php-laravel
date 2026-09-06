<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Assistant\AskAssistantAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Assistant\AskAssistantRequest;
use Illuminate\Http\JsonResponse;

/**
 * AI asistan vekili — sistemin PARA HARCAYAN tek ucu.
 *
 * 🔴 Uc AUTH'LUDUR ve bu, frontend ile arasindaki bir SOZLESME
 * CELISKISININ bilincli cozumudur: `AssistantWidget` bugun `AppLayout`
 * icinde, yani giris yapmamis ziyaretcide de goruntuleniyor.
 *
 * Gerekce: her cagri paradir ve bir maliyet kontrolunun calismasi icin
 * harcamanin bir KIMLIGE yazilabilmesi gerekir. Kimliksiz cagrida elimizdeki
 * tek anahtar IP'dir ve IP iki yonde birden basarisizdir:
 *   - Cok genis: CGNAT arkasindaki on binlerce abone tek IP'dir; gunluk
 *     kotayi ilk kullanan tuketir, geri kalan herkes kapida kalir.
 *   - Cok dar: IP degistirmek saldirgan icin saatlik birkac kurustur.
 * Yani IP anahtari kotayi mesru kullanici icin siki, saldirgan icin gevsek
 * yapar — bir guvenlik kontrolunun olabilecegi en kotu hali. Ustelik ticari
 * modelde ucretsiz katman YOK: parayla ilgisi olan herkesin hesabi var.
 *
 * Celiskinin maliyeti frontend'dedir ve oraya aittir: widget, giris
 * yapmamis ziyaretcide render EDILMEMELI ya da "sohbet icin giris yap"
 * demeli. Backend, bir widget'in yerlesimini duzeltmek icin olculemeyen bir
 * para muslugunu internete acamaz (K12'nin ayni fail-safe refleksi).
 * Ayrintili aciklama: docs/rehber/app/Http/Controllers/Api/V1/AssistantController.md
 */
final class AssistantController extends Controller
{
    public function __invoke(AskAssistantRequest $request, AskAssistantAction $action): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();   // auth:sanctum garantiledi; null olamaz

        $reply = $action->handle($user, $request->prompt());

        // 🔴 ZARF KORUNUYOR: {data: {...}}. Zarfsiz donmek frontend'in tek
        // dikis yerini (generateReply) bir satir kisaltirdi ama C2 zarf
        // istisnasini AD AD tanimliyor ve o listede yalnizca auth uclari var.
        // Bir istisnayi "kolay oldugu icin" buyutmek, sozlesmeyi kuralsiz
        // birakmanin ilk adimidir.
        //
        // Resource DEGIL duz JsonResponse: ortada bir MODEL yok. Yanit tek
        // bir metin ve o metnin beyaz listelenecek alani, gizlenecek kolonu,
        // camelCase'e cevrilecek adi yok — bir Resource burada yalnizca bos
        // bir tabaka olurdu (K15: soyutlama butcesi).
        return response()->json(['data' => ['reply' => $reply]]);
    }
}
