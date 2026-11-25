<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\GiftCode\ReserveGiftCodeAction;
use App\Actions\Payment\ClaimReleasedOrderAction;
use App\Actions\Payment\HandlePaymentCallbackAction;
use App\Actions\Rsvp\EnsureRsvpQuotaAction;
use App\Enums\OrderStatus;
use App\Enums\RefundRequestStatus;
use App\Enums\SubscriptionTier;
use App\Models\GiftCode;
use App\Models\Invitation;
use App\Models\Order;
use App\Models\User;
use App\Services\Payment\PaymentNotification;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\LockAssertions;
use Tests\TestCase;

/**
 * TST-02 · K187: iki bağlantılı GERÇEK kilit testleri (denetim mutantları M14, M47, M48, M49)
 * ve Faz 18.4'ün "donmuş hak eşzamanlı yayında bağlanabiliyor" yarışı.
 *
 * 🔴 RefreshDatabase DEĞİL: onun transaction'ı içindeki satırları ikinci bağlantı göremez.
 * DatabaseTruncation + test sonunda yeniden boşaltma (sonraki testlere veri sızmaz).
 * Ayrintili aciklama: docs/rehber/tests/Support/LockAssertions.md
 */
#[Group('concurrency')]
final class LockRaceTest extends TestCase
{
    use DatabaseTruncation;
    use LockAssertions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->beforeApplicationDestroyed(function (): void {
            DB::disconnect('pgsql_race');
            $this->truncateTablesForAllConnections();
        });
    }

    /** M14: tek kullanımlık kod iki siparişe yazılmasın. */
    #[Test]
    public function reserving_a_gift_code_waits_for_the_code_row_lock(): void
    {
        $user = User::factory()->create();
        $code = GiftCode::factory()->withCode('LOCKTEST1')->create();

        $this->assertWaitsForRowLock('gift_codes', $code->id, fn () => app(ReserveGiftCodeAction::class)
            ->handle($user, 'LOCKTEST1', SubscriptionTier::Standart));
    }

    /** M47: aynı ödeme bildirimi iki kez işlenmesin. */
    #[Test]
    public function a_payment_notification_waits_for_the_order_row_lock(): void
    {
        $order = Order::factory()->create(['provider_ref' => 'race-ref']);

        $this->assertWaitsForRowLock('orders', $order->id, fn () => app(HandlePaymentCallbackAction::class)
            ->handle(new PaymentNotification('race-ref', OrderStatus::Paid)));
    }

    /** M48: LCV kotası son iki yanıtla aşılmasın. */
    #[Test]
    public function the_rsvp_quota_check_waits_for_the_invitation_row_lock(): void
    {
        $invitation = Invitation::factory()->published()->create();
        Order::factory()->tier(SubscriptionTier::Standart)->forInvitation($invitation)->paid()->create();

        $this->assertWaitsForRowLock('invitations', $invitation->id, fn () => app(EnsureRsvpQuotaAction::class)
            ->handle($invitation, 1));
    }

    /** M49: serbest hak iki davetiyeye birden bağlanmasın. */
    #[Test]
    public function claiming_a_released_order_waits_for_the_order_row_lock(): void
    {
        $invitation = Invitation::factory()->create();
        $order = Order::factory()->paid()->released()->create(['user_id' => $invitation->user_id]);

        $this->assertWaitsForRowLock('orders', $order->id, fn () => app(ClaimReleasedOrderAction::class)
            ->handle($invitation, SubscriptionTier::Standart));
    }

    /**
     * A-E8 (M47): ilk bildirim siparişi ödenmiş yapıp commit ederken ikincisi gelir. Kilitli okuma
     * bekler ve `paid → paid`'e takılır; kilitsiz okuma bayat `pending` görüp `paid_at`'i ezerdi.
     */
    #[Test]
    public function a_repeated_payment_notification_does_not_rewrite_a_concurrently_paid_order(): void
    {
        $order = Order::factory()->create(['provider_ref' => 'race-ref-2']);
        $firstPaidAt = '2026-01-02 03:04:05';

        $process = $this->holdRowLockInAnotherProcess([
            "select id from orders where id = '{$order->id}' for update",
            sprintf(
                "update orders set status = '%s', paid_at = '%s' where id = '%s'",
                OrderStatus::Paid->value,
                $firstPaidAt,
                $order->id,
            ),
        ], holdMs: 800);

        $started = microtime(true);
        app(HandlePaymentCallbackAction::class)->handle(new PaymentNotification('race-ref-2', OrderStatus::Paid));
        $waited = microtime(true) - $started;

        $result = $process->wait();

        $this->assertTrue($result->successful(), $result->errorOutput());
        $this->assertGreaterThan(0.3, $waited, 'Bildirim kilidi beklemedi: araya girme üretilemedi.');
        $this->assertSame($firstPaidAt, $order->fresh()?->paid_at?->format('Y-m-d H:i:s'), 'İkinci bildirim ödenmiş siparişi yeniden işledi.');
    }

    /**
     * A-E8 (M49): başka bir yayın serbest hakkı bağlayıp commit ederken ikinci sahiplenme gelir.
     * Kilitli okuma bekler ve hakkı artık serbest görmez; kilitsiz okuma ilk bağlamanın üzerine yazardı.
     */
    #[Test]
    public function a_released_order_claimed_concurrently_is_not_claimed_twice(): void
    {
        $invitation = Invitation::factory()->create();
        $first = Invitation::factory()->create(['user_id' => $invitation->user_id]);
        $order = Order::factory()->paid()->released()->create(['user_id' => $invitation->user_id]);

        $process = $this->holdRowLockInAnotherProcess([
            "select id from orders where id = '{$order->id}' for update",
            "update orders set invitation_id = '{$first->id}' where id = '{$order->id}'",
        ], holdMs: 800);

        $started = microtime(true);
        $claimed = DB::transaction(fn (): bool => app(ClaimReleasedOrderAction::class)
            ->handle($invitation, SubscriptionTier::Standart));
        $waited = microtime(true) - $started;

        $result = $process->wait();

        $this->assertTrue($result->successful(), $result->errorOutput());
        $this->assertGreaterThan(0.3, $waited, 'Sahiplenme kilidi beklemedi: araya girme üretilemedi.');
        $this->assertFalse($claimed, 'Aynı serbest hak ikinci davetiyeye de bağlandı.');
        $this->assertSame($first->id, $order->fresh()?->invitation_id);
    }

    /**
     * Faz 18.4 yarışı: iade talebi siparişi kilitler ama GÜNCELLEMEZ; bekleyen sahiplenme
     * sorgusu kilit kalkınca eski anlık görüntüyle "açık iade yok" der ve donmuş hakkı bağlardı.
     */
    #[Test]
    public function a_refund_requested_during_a_claim_keeps_the_right_frozen(): void
    {
        $invitation = Invitation::factory()->create();
        $order = Order::factory()->paid()->released()->create(['user_id' => $invitation->user_id]);

        $process = $this->holdRowLockInAnotherProcess([
            "select id from orders where id = '{$order->id}' for update",
            sprintf(
                "insert into refund_requests (id, order_id, user_id, status, created_at, updated_at) values ('%s', '%s', %d, '%s', now(), now())",
                strtolower((string) Str::ulid()),
                $order->id,
                $invitation->user_id,
                RefundRequestStatus::Requested->value,
            ),
        ], holdMs: 800);

        $started = microtime(true);
        $claimed = DB::transaction(fn (): bool => app(ClaimReleasedOrderAction::class)
            ->handle($invitation, SubscriptionTier::Standart));
        $waited = microtime(true) - $started;

        $result = $process->wait();

        $this->assertTrue($result->successful(), $result->errorOutput());
        $this->assertGreaterThan(0.3, $waited, 'Sahiplenme kilidi beklemedi: araya girme üretilemedi.');
        $this->assertFalse($claimed, 'İptal talebi olan (donmuş) hak yeni davetiyeye bağlandı.');
        $this->assertNull($order->fresh()?->invitation_id);
    }
}
