<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ErrorCode;
use App\Enums\RefundRequestStatus;
use App\Enums\SubscriptionTier;
use App\Models\Invitation;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Hakkı iptal et / vazgeç (Faz 14, 14.5 · K138, K139).
 * Ayrıntılı açıklama: docs/rehber/tests/Feature/RefundRequestTest.md
 */
final class RefundRequestTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_guest_cannot_request_a_refund(): void
    {
        $order = Order::factory()->paid()->package()->create();

        $this->postJson(route('orders.refund-request.store', $order))->assertUnauthorized();
    }

    #[Test]
    public function the_owner_cancels_an_unused_right(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->paid()->tier(SubscriptionTier::Gold)->package()->create(['user_id' => $user->id]);

        $this->requestRefund($user, $order, ['reason' => '  Düğün ertelendi  '])
            ->assertCreated()
            ->assertJsonPath('data.orderId', $order->id)
            ->assertJsonPath('data.status', 'requested')
            ->assertJsonMissingPath('data.adminNote');

        $request = RefundRequest::query()->sole();
        $this->assertSame('Düğün ertelendi', $request->reason);
        $this->assertSame($user->id, $request->user_id);
        $this->assertSame(RefundRequestStatus::Requested, $request->status);

        $this->forgetAuthState();
        $this->withToken($user->createToken('t')->plainTextToken)
            ->getJson(route('account.entitlements'))
            ->assertJsonPath('data.tiers.1.available', 0)
            ->assertJsonPath('data.tiers.1.refundRequested', 1)
            ->assertJsonPath('data.rights.0.refund.request.status', 'requested');
    }

    #[Test]
    public function an_empty_reason_is_stored_as_null(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->paid()->package()->create(['user_id' => $user->id]);

        $this->requestRefund($user, $order, ['reason' => '   '])->assertCreated();

        $this->assertNull(RefundRequest::query()->sole()->reason);
    }

    #[Test]
    public function a_too_long_reason_is_refused(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->paid()->package()->create(['user_id' => $user->id]);

        $this->requestRefund($user, $order, ['reason' => str_repeat('a', 501)])
            ->assertUnprocessable()
            ->assertJsonPath('error.fields.reason.0.rule', 'max');
    }

    /** H7: başkasının siparişi 404 — 403 değil, varlığı sızmaz. */
    #[Test]
    public function another_users_order_is_not_found(): void
    {
        $order = Order::factory()->paid()->package()->create();

        $this->requestRefund(User::factory()->create(), $order)
            ->assertNotFound()
            ->assertJsonPath('error.code', ErrorCode::ResourceNotFound->value);

        $this->assertSame(0, RefundRequest::query()->count());
    }

    #[Test]
    public function a_right_in_use_cannot_be_cancelled(): void
    {
        $user = User::factory()->create();
        $invitation = Invitation::factory()->published()->create(['user_id' => $user->id]);
        $order = Order::factory()->paid()->forInvitation($invitation)->create();

        $this->assertRefused($user, $order, 'in_use');
    }

    #[Test]
    public function a_gift_right_cannot_be_cancelled(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->paid()->package()->create([
            'user_id' => $user->id,
            'provider' => Order::GIFT_PROVIDER,
            'amount_minor' => 0,
            'discount_minor' => 24900,
        ]);

        $this->assertRefused($user, $order, 'free');
    }

    #[Test]
    public function a_right_past_the_window_cannot_be_cancelled(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->paid()->package()->create(['user_id' => $user->id, 'paid_at' => now()->subDays(15)]);

        $this->assertRefused($user, $order, 'window_closed');
    }

    #[Test]
    public function an_unpaid_order_cannot_be_cancelled(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->create(['user_id' => $user->id]);

        $this->assertRefused($user, $order, 'not_paid');
    }

    #[Test]
    public function a_second_request_is_refused(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->paid()->package()->create(['user_id' => $user->id]);

        $this->requestRefund($user, $order)->assertCreated();
        $this->forgetAuthState();

        $this->assertRefused($user, $order, 'already_requested');
        $this->assertSame(1, RefundRequest::query()->count());
    }

    /** 🔴 Donmuş hak yayında kullanılamaz: iptal + yayın = bedava yayın olurdu. */
    #[Test]
    public function a_frozen_right_is_not_claimed_by_a_publish(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->paid()->package()->create(['user_id' => $user->id]);
        RefundRequest::factory()->forOrder($order)->create();
        $invitation = Invitation::factory()->create(['user_id' => $user->id]);

        $this->withToken($user->createToken('t')->plainTextToken)
            ->postJson(route('invitations.publish', $invitation))
            ->assertStatus(402);

        $this->assertNull($order->refresh()->invitation_id);
    }

    #[Test]
    public function withdrawing_unfreezes_the_right(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->paid()->package()->create(['user_id' => $user->id]);
        $request = RefundRequest::factory()->forOrder($order)->create();
        $token = $user->createToken('t')->plainTextToken;

        $this->withToken($token)
            ->deleteJson(route('orders.refund-request.destroy', $order))
            ->assertNoContent();

        $request->refresh();
        $this->assertSame(RefundRequestStatus::Withdrawn, $request->status);
        $this->assertNotNull($request->resolved_at);

        $invitation = Invitation::factory()->create(['user_id' => $user->id]);
        $this->forgetAuthState();
        $this->withToken($token)->postJson(route('invitations.publish', $invitation))->assertOk();
        $this->assertSame($invitation->id, $order->refresh()->invitation_id);
    }

    #[Test]
    public function withdrawing_without_an_open_request_is_404(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->paid()->package()->create(['user_id' => $user->id]);
        RefundRequest::factory()->forOrder($order)->rejected()->create();

        $this->withToken($user->createToken('t')->plainTextToken)
            ->deleteJson(route('orders.refund-request.destroy', $order))
            ->assertNotFound();
    }

    #[Test]
    public function another_user_cannot_withdraw(): void
    {
        $order = Order::factory()->paid()->package()->create();
        $request = RefundRequest::factory()->forOrder($order)->create();

        $this->withToken(User::factory()->create()->createToken('t')->plainTextToken)
            ->deleteJson(route('orders.refund-request.destroy', $order))
            ->assertNotFound();

        $this->assertSame(RefundRequestStatus::Requested, $request->refresh()->status);
    }

    private function assertRefused(User $user, Order $order, string $reason): void
    {
        $this->requestRefund($user, $order)
            ->assertConflict()
            ->assertJsonPath('error.code', ErrorCode::RefundNotAllowed->value)
            ->assertJsonPath('error.params.reason', $reason);
    }

    /**
     * @param  array<string, mixed>  $body
     *
     * @return TestResponse<Response>
     */
    private function requestRefund(User $user, Order $order, array $body = []): TestResponse
    {
        return $this->withToken($user->createToken('t')->plainTextToken)
            ->postJson(route('orders.refund-request.store', $order), $body);
    }
}
