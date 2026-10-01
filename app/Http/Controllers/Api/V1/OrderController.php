<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Kullanicinin kendi siparisleri — YALNIZCA okuma (Faz 10, 10.23).
 *
 * Odeme donus sayfasi (frontend 10.27) `GET /orders/{order}`'i birkac saniye
 * yoklayarak "odendi mi?" sorusunu sorar. Liste ucu ileride bir
 * "siparislerim" ekrani icin (rapor §2.1, Faz 9 acik #9).
 *
 * Action YOK (K15): iki uc da tek bir sorgu ve bir Resource. Sarilacak bir
 * is kurali yok; bir Action toren olurdu.
 * Ayrintili aciklama: docs/rehber/app/Http/Controllers/Api/V1/OrderController.md
 */
final class OrderController extends Controller
{
    /**
     * Sahiplik iki kez korunuyor: Gate karari verir, sorgu KAPSAMI zorlar.
     * Ikincisi olmasa Gate'i unutmak butun siparisleri acardi (P3).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Order::class);

        /** @var User $user auth:sanctum burada null OLAMAYACAGINI garanti eder. */
        $user = $request->user();

        return OrderResource::collection(
            // En yeni ustte. Ayni saniyede acilan iki siparis icin `id`
            // (ULID, zamana gore sirali) esitligi bozar: sira her istekte ayni.
            $user->orders()->latest()->orderByDesc('id')->get(),
        );
    }

    public function show(Order $order): OrderResource
    {
        Gate::authorize('view', $order);

        return new OrderResource($order);
    }
}
