<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\InvitationStatus;
use App\Models\Invitation;
use App\Models\Order;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Faz 18.4 (SEC-02, K164 · K169): `orders.first_published_at` — kolon, kısıt,
 * model ve göçün geriye dönük doldurması.
 *
 * Doldurma testleri göçü geri alır, eski biçimde satır yazar ve yeniden koşar:
 * kuralı test değil göç uygulamalı.
 * Ayrıntılı açıklama: docs/rehber/database/migrations/2026_11_17_100000_add_first_published_at_to_orders_table.md
 */
final class OrderFirstPublishedAtTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'migrations/2026_11_17_100000_add_first_published_at_to_orders_table.php';

    #[Test]
    public function a_new_order_has_never_published(): void
    {
        $order = Order::factory()->paid()->create()->refresh();

        $this->assertNull($order->first_published_at);
        $this->assertFalse($order->hasPublished());
    }

    #[Test]
    public function the_stamp_is_read_as_an_immutable_date(): void
    {
        $order = Order::factory()->paid()->publishedOnce()->create()->refresh();

        $this->assertInstanceOf(CarbonImmutable::class, $order->first_published_at);
        $this->assertTrue($order->hasPublished());
    }

    /** 🔴 Damgayı istek gövdesi yazamaz: yalnızca StampFirstPublishAction basar. */
    #[Test]
    public function the_stamp_cannot_be_mass_assigned(): void
    {
        $order = Order::factory()->paid()->create();

        $this->expectException(MassAssignmentException::class);

        $order->fill(['first_published_at' => now()]);
    }

    /** Parası alınmamış hak yayın yapmış olamaz (orders_first_published_at_check). */
    #[Test]
    public function the_database_refuses_a_stamp_without_payment(): void
    {
        $order = Order::factory()->create();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('orders_first_published_at_check');

        Order::query()->whereKey($order->id)->update(['first_published_at' => now()]);
    }

    #[Test]
    public function the_backfill_stamps_a_paid_order_attached_to_a_published_invitation(): void
    {
        $this->rollBack();

        $publishedAt = now()->subDays(2)->startOfSecond();
        $invitation = $this->invitationPublishedAt($publishedAt);
        $order = $this->paidOrder($invitation->user_id, $invitation->id, paidAt: $publishedAt->subHour());
        $unpaid = Order::factory()->forInvitation($invitation)->create();

        $this->migrateAgain();

        $this->assertStampEquals($publishedAt, $order);
        $this->assertStampIsNull($unpaid);
    }

    /** Yükseltme siparişi yayından SONRA ödenmiş olabilir: hizmet ödemeyle başladı. */
    #[Test]
    public function the_backfill_uses_the_payment_time_for_an_order_paid_after_the_publish(): void
    {
        $this->rollBack();

        $invitation = $this->invitationPublishedAt(now()->subDays(2));
        $paidAt = now()->subDay()->startOfSecond();
        $upgrade = $this->paidOrder($invitation->user_id, $invitation->id, $paidAt);

        $this->migrateAgain();

        $this->assertStampEquals($paidAt, $upgrade);
    }

    #[Test]
    public function the_backfill_skips_an_order_attached_to_a_draft(): void
    {
        $this->rollBack();

        $draft = Invitation::factory()->create();
        $order = $this->paidOrder($draft->user_id, $draft->id, now()->subDay());

        $this->migrateAgain();

        $this->assertStampIsNull($order);
    }

    /** Yanmış hakkın davetiyesi kalıcı silinmiş olabilir: yayın anı yok, yanma anı var. */
    #[Test]
    public function the_backfill_stamps_a_consumed_order_with_its_consumption_time(): void
    {
        $this->rollBack();

        $consumedAt = now()->subDays(40)->startOfSecond();
        $order = $this->paidOrder(User::factory()->create()->id, null, now()->subDays(50));
        DB::table('orders')->where('id', $order->id)->update(['consumed_at' => $consumedAt]);

        $this->migrateAgain();

        $this->assertStampEquals($consumedAt, $order);
    }

    /**
     * 🔴 SEC-02'nin bıraktığı iz: pencere açıkken silinen davetiye çöpte durur ve
     * serbest kalan siparişin son güncellenmesi o silmeyle AYNI ANDIR.
     */
    #[Test]
    public function the_backfill_stamps_a_released_order_traced_to_a_trashed_published_invitation(): void
    {
        $this->rollBack();

        $publishedAt = now()->subDays(2)->startOfSecond();
        $deletedAt = now()->subDay()->startOfSecond();
        $invitation = $this->invitationPublishedAt($publishedAt);
        DB::table('invitations')->where('id', $invitation->id)->update(['deleted_at' => $deletedAt]);

        $released = $this->paidOrder($invitation->user_id, null, $publishedAt->subHour(), updatedAt: $deletedAt);

        $this->migrateAgain();

        $this->assertStampEquals($publishedAt, $released);
    }

    /** İz yoksa damga da yok: hiç kullanılmamış hak iade edilebilir kalmalı (K139). */
    #[Test]
    public function the_backfill_leaves_an_unused_order_of_the_same_user_alone(): void
    {
        $this->rollBack();

        $deletedAt = now()->subDay()->startOfSecond();
        $invitation = $this->invitationPublishedAt(now()->subDays(2));
        DB::table('invitations')->where('id', $invitation->id)->update(['deleted_at' => $deletedAt]);

        $idle = $this->paidOrder($invitation->user_id, null, now()->subDays(3), updatedAt: $deletedAt->subHours(5));
        $paidLater = $this->paidOrder($invitation->user_id, null, $deletedAt->addSecond(), updatedAt: $deletedAt->addSecond());
        $neverPublished = Invitation::factory()->create(['user_id' => $invitation->user_id]);
        DB::table('invitations')->where('id', $neverPublished->id)->update(['deleted_at' => $deletedAt->subHours(5)]);

        $this->migrateAgain();

        $this->assertStampIsNull($idle);
        $this->assertStampIsNull($paidLater);
    }

    #[Test]
    public function the_migration_can_be_rolled_back_and_run_again(): void
    {
        $this->rollBack();
        $this->assertFalse(Schema::hasColumn('orders', 'first_published_at'));

        $this->migrateAgain();
        $this->assertTrue(Schema::hasColumn('orders', 'first_published_at'));
    }

    /** Göçü geri alır: kolon yokken eski biçimde satır yazabilmek için. */
    private function rollBack(): void
    {
        $migration = require database_path(self::MIGRATION);
        $migration->down();
    }

    /** Göçü yeniden koşar: kolon + kısıt + geriye dönük doldurma. */
    private function migrateAgain(): void
    {
        $migration = require database_path(self::MIGRATION);
        $migration->up();
    }

    private function invitationPublishedAt(DateTimeInterface $publishedAt): Invitation
    {
        return Invitation::factory()->create([
            'status' => InvitationStatus::Published,
            'published_at' => $publishedAt,
        ]);
    }

    /**
     * Kolon yokken çalışır: satır fabrikayla açılır, zamanlar doğrudan yazılır
     * (Eloquent `updated_at`'i kendisi basardı).
     */
    private function paidOrder(
        int $userId,
        ?string $invitationId,
        DateTimeInterface $paidAt,
        ?DateTimeInterface $updatedAt = null,
    ): Order {
        $order = Order::factory()->paid()->create(['user_id' => $userId]);

        DB::table('orders')->where('id', $order->id)->update([
            'invitation_id' => $invitationId,
            'paid_at' => $paidAt,
            'updated_at' => $updatedAt ?? $paidAt,
        ]);

        return $order;
    }

    private function assertStampEquals(DateTimeInterface $expected, Order $order): void
    {
        $stamp = $order->refresh()->first_published_at;

        $this->assertNotNull($stamp);
        $this->assertSame($expected->format('Y-m-d H:i:s'), $stamp->format('Y-m-d H:i:s'));
    }

    private function assertStampIsNull(Order $order): void
    {
        $this->assertNull($order->refresh()->first_published_at);
    }
}
