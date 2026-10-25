<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ErrorCode;
use App\Enums\SubscriptionTier;
use App\Models\Invitation;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * GET /api/account/entitlements (Faz 14, 14.4 · K140).
 * Ayrıntılı açıklama: docs/rehber/tests/Feature/AccountEntitlementsTest.md
 */
final class AccountEntitlementsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_guest_gets_401(): void
    {
        $this->getJson(route('account.entitlements'))
            ->assertUnauthorized()
            ->assertJsonPath('error.code', ErrorCode::Unauthenticated->value);
    }

    #[Test]
    public function the_counts_come_per_tier_in_an_envelope(): void
    {
        $user = User::factory()->create();
        Order::factory()->count(2)->paid()->tier(SubscriptionTier::Gold)->package()->create(['user_id' => $user->id]);
        Order::factory()->refunded()->tier(SubscriptionTier::Gold)->package()->create(['user_id' => $user->id]);

        $this->fetch($user)
            ->assertOk()
            ->assertJsonPath('data.totalAvailable', 2)
            ->assertJsonPath('data.tiers.0.tier', 'standart')
            ->assertJsonPath('data.tiers.0.available', 0)
            ->assertJsonPath('data.tiers.1.tier', 'gold')
            ->assertJsonPath('data.tiers.1.available', 2)
            ->assertJsonPath('data.tiers.1.refunded', 1)
            ->assertJsonPath('data.tiers.2.tier', 'elit')
            ->assertJsonCount(3, 'data.rights');
    }

    #[Test]
    public function a_right_carries_its_invitation_and_refund_status(): void
    {
        $user = User::factory()->create();
        $invitation = Invitation::factory()->published()->create(['user_id' => $user->id, 'title' => 'Ayşe & Mehmet']);
        $order = Order::factory()->paid()->tier(SubscriptionTier::Elit)->forInvitation($invitation)->create();

        $this->fetch($user)
            ->assertOk()
            ->assertExactJson(['data' => [
                'tiers' => [
                    $this->emptyTier('standart'),
                    $this->emptyTier('gold'),
                    ['tier' => 'elit', 'available' => 0, 'inUse' => 1, 'refundRequested' => 0, 'pending' => 0, 'consumed' => 0, 'refunded' => 0],
                ],
                'totalAvailable' => 0,
                'rights' => [[
                    'orderId' => $order->id,
                    'tier' => 'elit',
                    'state' => 'in_use',
                    'createdAt' => $order->created_at?->toIso8601String(),
                    'paidAt' => $order->paid_at?->toIso8601String(),
                    'totalMinor' => $order->amount_minor,
                    'currency' => 'TRY',
                    'invitation' => [
                        'id' => $invitation->id,
                        'title' => 'Ayşe & Mehmet',
                        'publishedAt' => $invitation->published_at?->toIso8601String(),
                        'releasableUntil' => $invitation->releaseWindowEndsAt()?->toIso8601String(),
                        'deleted' => false,
                    ],
                    'refund' => [
                        'eligible' => false,
                        'blocker' => 'in_use',
                        'windowEndsAt' => $order->paid_at?->addDays(14)->toIso8601String(),
                        'request' => null,
                    ],
                ]],
            ]]);
    }

    /** 🔴 Sağlayıcı kimlikleri ve sahiplik alanları dışarı çıkmaz (C1). */
    #[Test]
    public function provider_identifiers_never_leave_the_server(): void
    {
        $user = User::factory()->create();
        Order::factory()->paid()->package()->create(['user_id' => $user->id, 'provider_ref' => 'gizli-ref-123']);

        $body = $this->fetch($user)->assertOk()->getContent();

        $this->assertIsString($body);
        $this->assertStringNotContainsString('gizli-ref-123', $body);
        $this->assertStringNotContainsString('provider', $body);
        $this->assertStringNotContainsString('userId', $body);
    }

    #[Test]
    public function another_users_orders_never_appear(): void
    {
        Order::factory()->paid()->package()->create();

        $this->fetch(User::factory()->create())
            ->assertOk()
            ->assertJsonPath('data.totalAvailable', 0)
            ->assertJsonCount(0, 'data.rights');
    }

    #[Test]
    public function an_unchanged_summary_answers_304(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $etag = $this->withToken($token)->getJson(route('account.entitlements'))->assertOk()->headers->get('ETag');
        $this->assertNotNull($etag);

        $this->withToken($token)
            ->withHeader('If-None-Match', $etag)
            ->getJson(route('account.entitlements'))
            ->assertStatus(304);
    }

    /**
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function fetch(User $user): TestResponse
    {
        return $this->withToken($user->createToken('test')->plainTextToken)
            ->getJson(route('account.entitlements'));
    }

    /**
     * @return array<string, int|string>
     */
    private function emptyTier(string $tier): array
    {
        return ['tier' => $tier, 'available' => 0, 'inUse' => 0, 'refundRequested' => 0, 'pending' => 0, 'consumed' => 0, 'refunded' => 0];
    }
}
