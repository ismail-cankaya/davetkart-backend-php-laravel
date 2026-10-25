<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Payment\HandlePaymentCallbackAction;
use App\Exceptions\InvalidWebhookSignatureException;
use App\Http\Controllers\Controller;
use App\Services\Payment\ShopierGateway;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Shopier'in tarayıcı dönüşü — karşıda bir MAKİNE değil bir İNSAN var (Faz 12).
 *
 * Ödeme sonrası Shopier kullanıcının tarayıcısına bu adrese otomatik bir form
 * POST ettirir. Yanıt her zaman 303 ile frontend'e yönlendirmedir (K122):
 *
 *   geçerli imza, success -> /odeme/basarili?order=…
 *   geçerli imza, failed  -> /odeme/hata?order=…
 *   geçersiz/bozuk        -> /odeme/hata            (+ warning log)
 *
 * 🔴 K69'dan bilinçli sapma: imza hatası 404 JSON DEĞİL. 404 bir makineye
 * "burada bir şey yok" der; bir insana boş bir JSON sayfası gösterir. Saldırgan
 * için ikisi aynı bilgi: yönlendirme her durumda aynı iki adresten biri.
 *
 * 🔴 Hedef İSTEKTEN değil config'ten (open redirect). `order` yalnızca ULID ise eklenir.
 * 🔴 'osb' modunda bu uç HİÇBİR siparişi değiştirmez (K117): bildirim nesnesi yok.
 * Ayrıntılı açıklama: docs/rehber/app/Http/Controllers/Api/V1/ShopierCallbackController.md
 */
final class ShopierCallbackController extends Controller
{
    public function __invoke(
        Request $request,
        ShopierGateway $gateway,
        HandlePaymentCallbackAction $action,
    ): RedirectResponse {
        try {
            $callback = $gateway->parseCallback($request);
        } catch (InvalidWebhookSignatureException|BadRequestHttpException $e) {
            // Sebep log'da, yanıtta değil. İmza ve gövde log'a GİRMEZ.
            Log::warning('Shopier callback rejected', ['reason' => $e::class]);

            return $this->redirectTo('failure', null);
        }

        if ($callback->notification !== null) {
            $action->handle($callback->notification);
        }

        return $this->redirectTo($callback->succeeded ? 'success' : 'failure', $callback->orderId);
    }

    /**
     * 303 See Other: "sonucu GET ile şu adreste gör". 302'de bazı tarayıcılar
     * POST'u tekrarlayabilir; 303 yöntemi GET'e çevirmeyi ZORUNLU kılar.
     */
    private function redirectTo(string $kind, ?string $orderId): RedirectResponse
    {
        $url = Config::string("payment.return_urls.{$kind}");

        if ($orderId !== null && Str::isUlid($orderId)) {
            $url .= '?order='.$orderId;
        }

        return redirect()->away($url, RedirectResponse::HTTP_SEE_OTHER);
    }
}
