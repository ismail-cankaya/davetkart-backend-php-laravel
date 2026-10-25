<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Payment\HandlePaymentCallbackAction;
use App\Http\Controllers\Controller;
use App\Services\Payment\ShopierGateway;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Shopier'in Otomatik Sipariş Bildirimi (OSB) — sunucudan sunucuya (Faz 12).
 *
 * 'osb' modunda (varsayılan, K117) siparişi 'paid' yapan TEK kanal budur.
 * Karşıda bir makine var: K69 geçerli (imza hatası -> 404).
 *
 * Başarı yanıtı düz metin `success`: Shopier'in beklediği gövde (topluluk
 * örnekleri; §A-c resmi dokümanla doğrulanmadı). Bilinmeyen sipariş, tutar
 * uyuşmazlığı ve tekrar da `success` alır: "aldım, bir daha gönderme".
 * Tutarsızlık log'da (critical), yanıtta değil.
 * Ayrıntılı açıklama: docs/rehber/app/Http/Controllers/Api/V1/ShopierOsbController.md
 */
final class ShopierOsbController extends Controller
{
    private const ACKNOWLEDGEMENT = 'success';

    public function __invoke(
        Request $request,
        ShopierGateway $gateway,
        HandlePaymentCallbackAction $action,
    ): Response {
        // null = imzası doğru ama bizim siparişimizle eşlenemedi (critical log).
        $notification = $gateway->parseOsb($request);

        if ($notification !== null) {
            $action->handle($notification);
        }

        return response(self::ACKNOWLEDGEMENT, Response::HTTP_OK, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
