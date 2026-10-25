<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Invitation\DeleteInvitationAction;
use App\Enums\InvitationStatus;
use App\Enums\SubscriptionTier;
use App\Models\Invitation;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Yanmış hak yanmış kalır (Faz 14, 14.1 · K137).
 *
 * T14: yanıta değil ETKİYE bakılır — siparişin damgası ve bir sonraki
 * yayının hangi siparişi bağladığı.
 * Ayrıntılı açıklama: docs/rehber/tests/Feature/OrderConsumptionTest.md
 */
final class OrderConsumptionTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function deleting_after_the_window_stamps_the_order_as_consumed(): void
    {
        $invitation = $this->publishedDaysAgo(5);
        $order = Order::factory()->paid()->forInvitation($invitation)->create();

        app(DeleteInvitationAction::class)->handle($invitation);

        $order->refresh();
        $this->assertNotNull($order->consumed_at);
        $this->assertTrue($order->isConsumed());
        // Bağ da duruyor: hangi davetiyede yandığı bilgisi kaybolmadı.
        $this->assertSame($invitation->id, $order->invitation_id);
    }

    #[Test]
    public function deleting_within_the_window_does_not_consume(): void
    {
        $invitation = $this->publishedDaysAgo(1);
        $order = Order::factory()->paid()->forInvitation($invitation)->create();

        app(DeleteInvitationAction::class)->handle($invitation);

        $order->refresh();
        $this->assertNull($order->consumed_at);
        $this->assertNull($order->invitation_id);
    }

    /** Ödenmemiş sipariş tüketilmez: parası alınmamış hak yanamaz (CHECK). */
    #[Test]
    public function a_pending_order_is_never_stamped(): void
    {
        $invitation = $this->publishedDaysAgo(5);
        $order = Order::factory()->forInvitation($invitation)->create();

        app(DeleteInvitationAction::class)->handle($invitation);

        $this->assertNull($order->refresh()->consumed_at);
    }

    /**
     * 🔴 BU ADIMIN EN ÖNEMLİ TESTİ.
     *
     * Çöp kutusu boşalınca FK bağı koparır. Damga olmasaydı sipariş serbest
     * bir hak gibi görünür ve bir sonraki yayın onu bağlardı.
     */
    #[Test]
    public function a_consumed_order_is_not_reborn_when_the_trash_is_purged(): void
    {
        $user = User::factory()->create();
        $burned = $this->publishedDaysAgo(5, $user);
        $order = Order::factory()->paid()->tier(SubscriptionTier::Elit)->forInvitation($burned)->create();

        app(DeleteInvitationAction::class)->handle($burned);

        // Çöp kutusu süresi doldu, data:purge davetiyeyi kalıcı sildi.
        Invitation::onlyTrashed()->whereKey($burned->id)->update(['deleted_at' => now()->subDays(60)]);
        Artisan::call('data:purge');

        $order->refresh();
        $this->assertNull($order->invitation_id);
        $this->assertNotNull($order->consumed_at);

        $next = Invitation::factory()->create(['user_id' => $user->id, 'show_gallery' => true]);

        $this->withToken($user->createToken('test')->plainTextToken)
            ->postJson(route('invitations.publish', $next))
            ->assertStatus(402);

        $this->assertNull($order->refresh()->invitation_id);
    }

    #[Test]
    public function the_database_refuses_a_consumed_order_without_payment(): void
    {
        $order = Order::factory()->create();

        $this->expectException(QueryException::class);

        Order::query()->whereKey($order->id)->update(['consumed_at' => now()]);
    }

    private function publishedDaysAgo(int $days, ?User $user = null): Invitation
    {
        return Invitation::factory()->create([
            'user_id' => ($user ?? User::factory()->create())->id,
            'status' => InvitationStatus::Published,
            'published_at' => now()->subDays($days),
        ]);
    }
}
