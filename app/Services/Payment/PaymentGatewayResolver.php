<?php

declare(strict_types=1);

namespace App\Services\Payment;

use Illuminate\Contracts\Container\Container;

/**
 * Ödeme sağlayıcısını GEREKTİĞİ ANDA kurar (Faz 13, K136).
 *
 * 🔴 Neden? StartCheckoutAction sağlayıcıyı kurucuda isteseydi, sürücü HER
 * checkout'ta kurulurdu — hediye kodunun tamamen karşıladığı siparişte de.
 * Bugün kart kapalı (`PAYMENT_METHODS=gift_code`) ve Shopier sırları boş;
 * ShopierGateway'in kurucusu boş sırda 503 fırlatır (K123). Sonuç: kart
 * hiç kullanılmadığı hâlde BEDAVA siparişler de 503 alırdı.
 *
 * Bu sınıf bağlamanın (AppServiceProvider::resolvePaymentGateway) yerine
 * GEÇMEZ, onu erteler: hangi sürücü, eksik sır, bilinmeyen sürücü — bütün
 * kurallar orada kalır. Testlerin `bind(PaymentGateway::class, ...)` ile
 * kurduğu sahte sağlayıcı da aynen çalışır.
 * Ayrıntılı açıklama: docs/rehber/app/Services/Payment/PaymentGatewayResolver.md
 */
final class PaymentGatewayResolver
{
    public function __construct(
        private readonly Container $container,
    ) {}

    /**
     * Bağlamayı çözer. Sürücü yapılandırılmamışsa bağlama 503 fırlatır
     * (PaymentProviderException::unavailable) — burada değil, orada.
     */
    public function resolve(): PaymentGateway
    {
        return $this->container->make(PaymentGateway::class);
    }
}
