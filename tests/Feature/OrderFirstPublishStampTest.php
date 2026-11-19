<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\SubscriptionTier;
use App\Models\Invitation;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Faz 18.4 (SEC-02, K164 · K165): yayın damgası ne zaman basılır, ne zaman
 * DEĞİŞMEZ.
 *
 * T14: yanıta değil ETKİYE bakılır — yayın ve silme uçlarından sonra siparişin
 * `first_published_at` kolonu.
 * Ayrıntılı açıklama: docs/rehber/app/Actions/Payment/StampFirstPublishAction.md
 */
final class OrderFirstPublishStampTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_first_publish_stamps_the_order(): void
    {
        $user = User::factory()->create();
        $invitation = Invitation::factory()->create(['user_id' => $user->id]);
        $order = Order::factory()->paid()->forInvitation($invitation)->create();

        $this->publishAs($user, $invitation)->assertOk();

        $stamp = $order->refresh()->first_published_at;
        $publishedAt = $invitation->refresh()->published_at;
        $this->assertNotNull($stamp);
        $this->assertNotNull($publishedAt);
        $this->assertTrue($stamp->equalTo($publishedAt));
    }

    /** Bağsız paket yayın anında bağlanır (K99); damga aynı transaction'da basılır. */
    #[Test]
    public function a_package_claimed_by_the_publish_is_stamped(): void
    {
        $user = User::factory()->create();
        $invitation = Invitation::factory()->create(['user_id' => $user->id]);
        $package = Order::factory()->paid()->package()->create(['user_id' => $user->id]);

        $this->publishAs($user, $invitation)->assertOk();

        $package->refresh();
        $this->assertSame($invitation->id, $package->invitation_id);
        $this->assertNotNull($package->first_published_at);
    }

    /**
     * 🔴 BU ADIMIN EN ÖNEMLİ TESTİ (K82 + K164).
     *
     * Pencere açıkken silme bağı koparır; damga kalır. Damga da silinseydi
     * sipariş "hiç kullanılmamış" hakla aynı görünür ve iade edilirdi.
     */
    #[Test]
    public function releasing_a_right_keeps_its_first_published_at(): void
    {
        $user = User::factory()->create();
        $invitation = Invitation::factory()->create(['user_id' => $user->id]);
        $order = Order::factory()->paid()->forInvitation($invitation)->create();

        $this->publishAs($user, $invitation)->assertOk();
        $stamp = $order->refresh()->first_published_at;
        $this->assertNotNull($stamp);

        $this->travel(1)->days();
        $this->deleteAs($user, $invitation)->assertNoContent();

        $order->refresh();
        $this->assertNull($order->invitation_id);
        $this->assertNull($order->consumed_at);
        $this->assertNotNull($order->first_published_at);
        $this->assertTrue($stamp->equalTo($order->first_published_at));
    }

    /** "İLK yayın": serbest kalan hak yeni bir davetiyeyi yayınlayınca damga OYNAMAZ. */
    #[Test]
    public function a_second_publish_does_not_move_the_stamp(): void
    {
        $user = User::factory()->create();
        $first = Invitation::factory()->create(['user_id' => $user->id]);
        $order = Order::factory()->paid()->forInvitation($first)->create();

        $this->publishAs($user, $first)->assertOk();
        $stamp = $order->refresh()->first_published_at;
        $this->assertNotNull($stamp);

        $this->travel(1)->days();
        $this->deleteAs($user, $first)->assertNoContent();

        $this->travel(1)->days();
        $second = Invitation::factory()->create(['user_id' => $user->id]);
        $this->publishAs($user, $second)->assertOk();

        $order->refresh();
        $this->assertSame($second->id, $order->invitation_id);
        $this->assertNotNull($order->first_published_at);
        $this->assertTrue($stamp->equalTo($order->first_published_at));
    }

    /** Davetiyeye bağlı ödenmiş HER sipariş yayına hizmet eder; ödenmemiş olan etmez. */
    #[Test]
    public function every_paid_order_of_the_invitation_is_stamped_and_unpaid_ones_are_not(): void
    {
        $user = User::factory()->create();
        $invitation = Invitation::factory()->create(['user_id' => $user->id]);
        $standart = Order::factory()->paid()->forInvitation($invitation)->create();
        $gold = Order::factory()->paid()->tier(SubscriptionTier::Gold)->forInvitation($invitation)->create();
        $pending = Order::factory()->forInvitation($invitation)->create();

        $this->publishAs($user, $invitation)->assertOk();

        $this->assertNotNull($standart->refresh()->first_published_at);
        $this->assertNotNull($gold->refresh()->first_published_at);
        $this->assertNull($pending->refresh()->first_published_at);
    }

    /** Reddedilen yayın (402) hiçbir hakkı kullanmış saymaz. */
    #[Test]
    public function a_refused_publish_stamps_nothing(): void
    {
        $user = User::factory()->create();
        $invitation = Invitation::factory()->create(['user_id' => $user->id, 'show_gallery' => true]);
        $order = Order::factory()->paid()->forInvitation($invitation)->create();

        $this->publishAs($user, $invitation)->assertStatus(402);

        $this->assertNull($order->refresh()->first_published_at);
    }

    /**
     * 🔴 YAN KAPI (K165): yükseltme siparişi YAYINDAKİ davetiyeye ödenir; yayın
     * Action'ı hiç çalışmaz. Davetiye silinirken damgasız ayrılsaydı iade edilirdi.
     */
    #[Test]
    public function an_upgrade_paid_after_the_publish_is_stamped_when_the_right_is_released(): void
    {
        $user = User::factory()->create();
        $invitation = Invitation::factory()->create(['user_id' => $user->id]);
        Order::factory()->paid()->forInvitation($invitation)->create();
        $this->publishAs($user, $invitation)->assertOk();

        $this->travel(1)->days();
        $upgrade = Order::factory()->paid()->tier(SubscriptionTier::Gold)->forInvitation($invitation)->create();
        $paidAt = $upgrade->refresh()->paid_at;
        $this->assertNotNull($paidAt);

        $this->travel(1)->days();
        $this->deleteAs($user, $invitation)->assertNoContent();

        $upgrade->refresh();
        $this->assertNull($upgrade->invitation_id);
        $this->assertNotNull($upgrade->first_published_at);
        // Hizmet ödemeyle başladı: damga yayın anı değil ödeme anı.
        $this->assertTrue($paidAt->equalTo($upgrade->first_published_at));
    }

    /** Pencere kapalıyken silinen davetiyenin yükseltme siparişi de damgasız yanmaz. */
    #[Test]
    public function an_upgrade_is_stamped_when_the_right_burns(): void
    {
        $user = User::factory()->create();
        $invitation = Invitation::factory()->create(['user_id' => $user->id]);
        Order::factory()->paid()->forInvitation($invitation)->create();
        $this->publishAs($user, $invitation)->assertOk();

        $this->travel(5)->days();
        $upgrade = Order::factory()->paid()->tier(SubscriptionTier::Gold)->forInvitation($invitation)->create();
        $this->deleteAs($user, $invitation)->assertNoContent();

        $upgrade->refresh();
        $this->assertNotNull($upgrade->consumed_at);
        $this->assertNotNull($upgrade->first_published_at);
    }

    /** K139 bozulmadı: hiç yayınlanmamış davetiyenin hakkı damgasız serbest kalır. */
    #[Test]
    public function deleting_a_draft_stamps_nothing(): void
    {
        $user = User::factory()->create();
        $draft = Invitation::factory()->create(['user_id' => $user->id]);
        $order = Order::factory()->paid()->forInvitation($draft)->create();

        $this->deleteAs($user, $draft)->assertNoContent();

        $order->refresh();
        $this->assertNull($order->invitation_id);
        $this->assertNull($order->first_published_at);
    }

    /** Silme anında ödenmemiş sipariş damgalanmaz (CHECK kısıtı da reddederdi). */
    #[Test]
    public function a_pending_order_is_released_without_a_stamp(): void
    {
        $user = User::factory()->create();
        $invitation = Invitation::factory()->create(['user_id' => $user->id]);
        Order::factory()->paid()->forInvitation($invitation)->create();
        $this->publishAs($user, $invitation)->assertOk();
        $pending = Order::factory()->forInvitation($invitation)->create();

        $this->deleteAs($user, $invitation)->assertNoContent();

        $pending->refresh();
        $this->assertNull($pending->invitation_id);
        $this->assertNull($pending->first_published_at);
        $this->assertSame(1, DB::table('orders')->whereNotNull('first_published_at')->count());
    }

    /** @return TestResponse<Response> */
    private function publishAs(User $user, Invitation $invitation): TestResponse
    {
        $this->forgetAuthState();

        return $this->withToken($user->createToken('t')->plainTextToken)
            ->postJson(route('invitations.publish', $invitation));
    }

    /** @return TestResponse<Response> */
    private function deleteAs(User $user, Invitation $invitation): TestResponse
    {
        $this->forgetAuthState();

        return $this->withToken($user->createToken('t')->plainTextToken)
            ->deleteJson(route('invitations.destroy', $invitation));
    }
}
