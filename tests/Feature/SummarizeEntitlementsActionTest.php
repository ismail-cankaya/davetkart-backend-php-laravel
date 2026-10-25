<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Account\EntitlementSummary;
use App\Actions\Account\SummarizeEntitlementsAction;
use App\Actions\Invitation\DeleteInvitationAction;
use App\Enums\EntitlementState;
use App\Enums\InvitationStatus;
use App\Enums\RefundBlocker;
use App\Enums\SubscriptionTier;
use App\Models\Invitation;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * "Planlarım" okuma modeli ve iade edilebilirlik kuralı (Faz 14, 14.3 · K139, K140).
 * Ayrıntılı açıklama: docs/rehber/tests/Feature/SummarizeEntitlementsActionTest.md
 */
final class SummarizeEntitlementsActionTest extends TestCase
{
    use RefreshDatabase;

    /** 🔴 İsmail'in örneği: 2 Gold ödendi, 1'i iptal edildi → 1 hak görünür. */
    #[Test]
    public function two_gold_paid_and_one_refunded_leaves_one_available(): void
    {
        $user = User::factory()->create();
        Order::factory()->paid()->tier(SubscriptionTier::Gold)->package()->create(['user_id' => $user->id]);
        Order::factory()->refunded()->tier(SubscriptionTier::Gold)->package()->create(['user_id' => $user->id]);

        $gold = $this->summarize($user)->forTier(SubscriptionTier::Gold);

        $this->assertSame(1, $gold->count(EntitlementState::Available));
        $this->assertSame(1, $gold->count(EntitlementState::Refunded));
    }

    #[Test]
    public function every_tier_is_present_in_rank_order_even_when_empty(): void
    {
        $summary = $this->summarize(User::factory()->create());

        $this->assertSame(
            [SubscriptionTier::Standart, SubscriptionTier::Gold, SubscriptionTier::Elit],
            array_map(static fn ($tier) => $tier->tier, $summary->tiers),
        );
        $this->assertSame([], $summary->rights);
        $this->assertSame(0, $summary->totalAvailable());
    }

    #[Test]
    public function an_order_on_a_live_invitation_is_in_use(): void
    {
        $user = User::factory()->create();
        $invitation = Invitation::factory()->published()->create(['user_id' => $user->id]);
        Order::factory()->paid()->tier(SubscriptionTier::Elit)->forInvitation($invitation)->create();

        $elit = $this->summarize($user)->forTier(SubscriptionTier::Elit);

        $this->assertSame(1, $elit->count(EntitlementState::InUse));
        $this->assertSame(0, $elit->count(EntitlementState::Available));
    }

    /** 🔴 Yanmış hak, davetiyesi kalıcı silinse de "kullanılabilir" görünmez (K137). */
    #[Test]
    public function a_burned_right_stays_consumed_before_and_after_the_purge(): void
    {
        $user = User::factory()->create();
        $invitation = Invitation::factory()->create([
            'user_id' => $user->id,
            'status' => InvitationStatus::Published,
            'published_at' => now()->subDays(10),
        ]);
        Order::factory()->paid()->forInvitation($invitation)->create();

        app(DeleteInvitationAction::class)->handle($invitation);
        $this->assertSame(1, $this->summarize($user)->forTier(SubscriptionTier::Standart)->count(EntitlementState::Consumed));

        $invitation->forceDelete();
        $standart = $this->summarize($user)->forTier(SubscriptionTier::Standart);

        $this->assertSame(1, $standart->count(EntitlementState::Consumed));
        $this->assertSame(0, $standart->count(EntitlementState::Available));
    }

    /** Damgasız eski satır da (çöpteki davetiyeye bağlı) yanmış sayılır. */
    #[Test]
    public function an_order_on_a_trashed_invitation_counts_as_consumed(): void
    {
        $user = User::factory()->create();
        $invitation = Invitation::factory()->published()->create(['user_id' => $user->id]);
        Order::factory()->paid()->forInvitation($invitation)->create();
        DB::table('invitations')->where('id', $invitation->id)->update(['deleted_at' => now()]);

        $this->assertSame(1, $this->summarize($user)->forTier(SubscriptionTier::Standart)->count(EntitlementState::Consumed));
    }

    #[Test]
    public function a_pending_payment_is_shown_but_an_expired_one_is_not(): void
    {
        $user = User::factory()->create();
        Order::factory()->create(['user_id' => $user->id]);
        Order::factory()->create(['user_id' => $user->id, 'expires_at' => now()->subMinute()]);
        Order::factory()->failed()->create(['user_id' => $user->id]);

        $summary = $this->summarize($user);

        $this->assertSame(1, $summary->forTier(SubscriptionTier::Standart)->count(EntitlementState::Pending));
        $this->assertCount(1, $summary->rights);
        $this->assertSame(0, $summary->totalAvailable());
    }

    #[Test]
    public function an_open_refund_request_freezes_the_right(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->paid()->package()->create(['user_id' => $user->id]);
        RefundRequest::factory()->forOrder($order)->create();

        $standart = $this->summarize($user)->forTier(SubscriptionTier::Standart);

        $this->assertSame(1, $standart->count(EntitlementState::RefundRequested));
        $this->assertSame(0, $standart->count(EntitlementState::Available));
    }

    #[Test]
    public function another_users_orders_are_never_counted(): void
    {
        $user = User::factory()->create();
        Order::factory()->paid()->package()->create();

        $this->assertSame(0, $this->summarize($user)->totalAvailable());
    }

    #[Test]
    public function the_newest_order_comes_first(): void
    {
        $user = User::factory()->create();
        $old = Order::factory()->paid()->package()->create(['user_id' => $user->id, 'created_at' => now()->subDays(2)]);
        $new = Order::factory()->paid()->package()->create(['user_id' => $user->id]);

        $rights = $this->summarize($user)->rights;

        $this->assertSame([$new->id, $old->id], array_map(static fn ($right) => $right->order->id, $rights));
    }

    // ------------------------------------------------ İADE EDİLEBİLİRLİK (K139)

    #[Test]
    public function a_paid_unused_order_within_the_window_is_refundable(): void
    {
        $user = User::factory()->create();
        Order::factory()->paid()->package()->create(['user_id' => $user->id]);

        $right = $this->summarize($user)->rights[0];

        $this->assertTrue($right->isRefundable());
        $this->assertNotNull($right->refundWindowEndsAt);
        $this->assertTrue($right->refundWindowEndsAt->isFuture());
    }

    #[Test]
    public function the_refund_blockers_name_the_first_reason(): void
    {
        Config::set('davetkart.refunds.window_days', 14);
        $user = User::factory()->create();
        $invitation = Invitation::factory()->create(['user_id' => $user->id]);

        $inUse = Order::factory()->paid()->forInvitation($invitation)->create();
        $old = Order::factory()->paid()->package()->create(['user_id' => $user->id, 'paid_at' => now()->subDays(15)]);
        $free = Order::factory()->paid()->package()->create([
            'user_id' => $user->id,
            'provider' => Order::GIFT_PROVIDER,
            'amount_minor' => 0,
            'discount_minor' => 24900,
        ]);
        $refunded = Order::factory()->refunded()->package()->create(['user_id' => $user->id]);
        $requested = Order::factory()->paid()->package()->create(['user_id' => $user->id]);
        RefundRequest::factory()->forOrder($requested)->create();

        $blockers = [];
        foreach ($this->summarize($user)->rights as $right) {
            $blockers[$right->order->id] = $right->refundBlocker;
        }

        $this->assertSame(RefundBlocker::InUse, $blockers[$inUse->id]);
        $this->assertSame(RefundBlocker::WindowClosed, $blockers[$old->id]);
        $this->assertSame(RefundBlocker::Free, $blockers[$free->id]);
        $this->assertSame(RefundBlocker::NotPaid, $blockers[$refunded->id]);
        $this->assertSame(RefundBlocker::AlreadyRequested, $blockers[$requested->id]);
    }

    private function summarize(User $user): EntitlementSummary
    {
        return app(SummarizeEntitlementsAction::class)->handle($user);
    }
}
