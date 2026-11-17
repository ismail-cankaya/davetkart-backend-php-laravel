<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Payment\HandlePaymentCallbackAction;
use App\Enums\OrderStatus;
use App\Models\Invitation;
use App\Models\Order;
use App\Models\User;
use App\Services\Payment\PaymentNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Faz 18.3 (SEC-01, K158 — 3. katman): ödeme anındaki son kapı.
 *
 * İlk yayın indirimi iki siparişte ödenirse geçiş YİNE yapılır (para alındı)
 * ama bir insanın bakması için `Log::critical` düşer.
 * Ayrıntılı açıklama: docs/rehber/app/Actions/Payment/HandlePaymentCallbackAction.md
 */
final class FirstPublishFinalGateTest extends TestCase
{
    use RefreshDatabase;

    private const MESSAGE = 'First publish discount paid on more than one order';

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('davetkart.pricing.first_publish_percent', 60);
    }

    /** @return array<string, array{string}> */
    public static function ordersThatAlreadySpentTheDiscount(): array
    {
        // İade edilen sipariş de sayılır: ilk yayın bir kez yaşanır (K131).
        return ['paid' => ['paid'], 'refunded' => ['refunded']];
    }

    #[Test]
    #[DataProvider('ordersThatAlreadySpentTheDiscount')]
    public function a_second_paid_first_publish_order_is_logged_as_critical(string $state): void
    {
        $user = User::factory()->create();
        $first = $state === 'paid'
            ? Order::factory()->for($user)->firstPublish()->paid()->create()
            : Order::factory()->for($user)->firstPublish()->refunded()->create();
        $second = Order::factory()->forInvitation(Invitation::factory()->for($user)->create())->firstPublish()->create();
        $logger = Log::spy();

        $this->notifyPaid($second);

        // Para alındı: geçiş reddedilmez.
        $this->assertSame(OrderStatus::Paid, $second->refresh()->status);
        $this->assertNotNull($second->paid_at);

        $logger->shouldHaveReceived('critical', [
            self::MESSAGE,
            Mockery::on(static fn (array $context): bool => $context['order_id'] === $second->id
                && $context['user_id'] === $user->id
                && $context['other_order_ids'] === [$first->id]),
        ]);
    }

    /** Kapatılmış (expired) siparişe geç gelen ödeme: K89 kabul eder, son kapı haber verir. */
    #[Test]
    public function a_late_payment_for_a_closed_first_publish_order_is_accepted_and_reported(): void
    {
        $user = User::factory()->create();
        $closed = Order::factory()->for($user)->firstPublish()->create(['status' => OrderStatus::Expired]);
        Order::factory()->forInvitation(Invitation::factory()->for($user)->create())->firstPublish()->paid()->create();
        $logger = Log::spy();

        $this->notifyPaid($closed);

        $this->assertSame(OrderStatus::Paid, $closed->refresh()->status);
        $logger->shouldHaveReceived('critical', [
            self::MESSAGE,
            Mockery::on(static fn (array $context): bool => $context['order_id'] === $closed->id),
        ]);
    }

    #[Test]
    public function the_only_paid_first_publish_order_is_not_reported(): void
    {
        $order = Order::factory()->firstPublish()->create();
        $logger = Log::spy();

        $this->notifyPaid($order);

        $this->assertSame(OrderStatus::Paid, $order->refresh()->status);
        $logger->shouldNotHaveReceived('critical');
    }

    /** @return array<string, array{string}> */
    public static function harmlessNeighbours(): array
    {
        return [
            'a list price order is being paid' => ['list-price-paid-now'],
            'the other paid order was at list price' => ['other-at-list-price'],
            'the other first publish order is still pending' => ['other-pending'],
            'the other first publish order belongs to someone else' => ['other-user'],
        ];
    }

    #[Test]
    #[DataProvider('harmlessNeighbours')]
    public function one_discount_per_user_is_not_an_incident(string $case): void
    {
        $user = User::factory()->create();
        $invitation = Invitation::factory()->for($user)->create();

        $paying = match ($case) {
            'list-price-paid-now' => $this->with(
                Order::factory()->for($user)->firstPublish()->paid()->create(),
                Order::factory()->forInvitation($invitation)->create(),
            ),
            'other-at-list-price' => $this->with(
                Order::factory()->for($user)->paid()->create(),
                Order::factory()->forInvitation($invitation)->firstPublish()->create(),
            ),
            'other-pending' => $this->with(
                Order::factory()->for($user)->create(),
                Order::factory()->forInvitation($invitation)->firstPublish()->create(),
            ),
            'other-user' => $this->with(
                Order::factory()->firstPublish()->paid()->create(),
                Order::factory()->forInvitation($invitation)->firstPublish()->create(),
            ),
            default => $this->fail("Bilinmeyen durum: {$case}"),
        };
        $logger = Log::spy();

        $this->notifyPaid($paying);

        $this->assertSame(OrderStatus::Paid, $paying->refresh()->status);
        $logger->shouldNotHaveReceived('critical');
    }

    /** Sağlayıcı aynı bildirimi tekrarlar; olay bir kez yazılır. İade de yeni bir olay değildir. */
    #[Test]
    public function a_repeated_notification_and_a_refund_report_nothing_new(): void
    {
        $user = User::factory()->create();
        Order::factory()->for($user)->firstPublish()->paid()->create();
        $second = Order::factory()->forInvitation(Invitation::factory()->for($user)->create())->firstPublish()->create();
        $this->notifyPaid($second);
        $logger = Log::spy();

        $this->notifyPaid($second);
        $this->notify($second, OrderStatus::Refunded);

        $this->assertSame(OrderStatus::Refunded, $second->refresh()->status);
        $logger->shouldNotHaveReceived('critical');
    }

    /** Hazırlık satırlarını okutmak için: ilki komşu sipariş, dönen değer ödenecek olan. */
    private function with(Order $neighbour, Order $paying): Order
    {
        return $paying;
    }

    private function notifyPaid(Order $order): void
    {
        $this->notify($order, OrderStatus::Paid);
    }

    private function notify(Order $order, OrderStatus $status): void
    {
        $this->assertNotNull($order->provider_ref);

        app(HandlePaymentCallbackAction::class)->handle(new PaymentNotification($order->provider_ref, $status));
    }
}
