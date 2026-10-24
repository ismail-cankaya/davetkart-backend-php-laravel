<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Payment\HandlePaymentCallbackAction;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Services\Payment\PaymentNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * HandlePaymentCallbackAction'in Faz 12 eklemeleri (12.4 · K121, K126).
 *
 * Action doğrudan çağrılıyor: imza ve ayrıştırma sürücünün işi, burada
 * sınanan yalnızca "doğrulanmış bir bildirim gelince sipariş ne olur?".
 * T14: yanıt değil ETKİ — her test veritabanındaki satıra bakar.
 */
final class PaymentNotificationAmountTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_matching_amount_marks_the_order_paid_and_records_the_payment_id(): void
    {
        $order = Order::factory()->create(['provider_ref' => 'ref-1']);

        $this->handle(new PaymentNotification(
            'ref-1',
            OrderStatus::Paid,
            providerPaymentId: 'shp-778',
            amountMinor: $order->amount_minor,
            currency: $order->currency,
        ));

        $order->refresh();
        $this->assertSame(OrderStatus::Paid, $order->status);
        $this->assertSame('shp-778', $order->provider_payment_id);
        $this->assertNotNull($order->paid_at);
    }

    /** 🔴 Ucuz bir ödemenin bildirimi pahalı bir siparişi açamaz. */
    #[Test]
    public function a_different_amount_is_rejected_loudly(): void
    {
        $order = Order::factory()->create(['provider_ref' => 'ref-1']);
        $logger = Log::spy();

        $this->handle(new PaymentNotification(
            'ref-1',
            OrderStatus::Paid,
            amountMinor: $order->amount_minor - 1,
            currency: $order->currency,
        ));

        $order->refresh();
        $this->assertSame(OrderStatus::Pending, $order->status);
        $this->assertNull($order->paid_at);
        $this->assertNull($order->provider_payment_id);
        $logger->shouldHaveReceived('critical', [
            'Payment notification rejected: amount or currency mismatch',
            Mockery::on(fn (array $context): bool => $context['order_id'] === $order->id
                && $context['notified_minor'] === $order->amount_minor - 1),
        ]);
    }

    #[Test]
    public function a_different_currency_is_rejected(): void
    {
        $order = Order::factory()->create(['provider_ref' => 'ref-1']);

        $this->handle(new PaymentNotification(
            'ref-1',
            OrderStatus::Paid,
            amountMinor: $order->amount_minor,
            currency: 'USD',
        ));

        $this->assertSame(OrderStatus::Pending, $order->refresh()->status);
    }

    /** Tutar taşımayan bildirim (Fake, Shopier callback'i) eskisi gibi işlenir. */
    #[Test]
    public function a_notification_without_an_amount_is_not_compared(): void
    {
        $order = Order::factory()->create(['provider_ref' => 'ref-1']);

        $this->handle(new PaymentNotification('ref-1', OrderStatus::Paid));

        $order->refresh();
        $this->assertSame(OrderStatus::Paid, $order->status);
        $this->assertNull($order->provider_payment_id);
    }

    /** İlk yazılan kimlik kalır: iade bildirimi aynı ödemeyi anlatır. */
    #[Test]
    public function the_first_payment_id_is_kept(): void
    {
        $order = Order::factory()->paid()->create([
            'provider_ref' => 'ref-1',
            'provider_payment_id' => 'shp-1',
        ]);

        $this->handle(new PaymentNotification('ref-1', OrderStatus::Refunded, providerPaymentId: 'shp-2'));

        $order->refresh();
        $this->assertSame(OrderStatus::Refunded, $order->status);
        $this->assertSame('shp-1', $order->provider_payment_id);
    }

    private function handle(PaymentNotification $notification): void
    {
        $this->app->make(HandlePaymentCallbackAction::class)->handle($notification);
    }
}
