<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PromotionKind;
use App\Enums\SubscriptionTier;
use App\Models\GiftCode;
use App\Models\GiftCodeRedemption;
use App\Models\Order;
use App\Models\PriceCampaign;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Config;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Faz 13 (13.5): otomatik indirimin siparişe yazılması (K129–K133).
 *
 * T14: yanıta değil ETKİYE bakılır — sipariş satırının kolonları.
 * Ayrıntılı açıklama: docs/rehber/tests/Feature/FirstPublishCheckoutTest.md
 */
final class FirstPublishCheckoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('davetkart.tiers.standart.price', 249);
        Config::set('davetkart.tiers.gold.price', 399);
        Config::set('davetkart.tiers.elit.price', 549);
        Config::set('davetkart.pricing.first_publish_percent', 60);
    }

    #[Test]
    public function a_new_users_first_order_is_opened_at_sixty_percent_off(): void
    {
        $this->checkout(User::factory()->create(), ['tier' => 'gold'])
            ->assertCreated()
            ->assertJsonPath('data.status', OrderStatus::Pending->value);

        $order = Order::query()->sole();
        $this->assertSame(15960, $order->amount_minor);
        $this->assertSame(23940, $order->discount_minor);
        $this->assertSame(PromotionKind::FirstPublish, $order->promotion);
        $this->assertSame(23940, $order->promotion_discount_minor);
        $this->assertNull($order->getAttribute('price_campaign_id'));
        $this->assertSame(39900, $order->listPriceMinor());
    }

    #[Test]
    public function once_an_order_is_paid_the_next_one_is_at_list_price(): void
    {
        $user = User::factory()->create();
        Order::factory()->for($user)->paid()->create();

        $this->checkout($user, ['tier' => 'gold'])->assertCreated();

        $order = Order::query()->latest('created_at')->where('status', OrderStatus::Pending->value)->sole();
        $this->assertSame(39900, $order->amount_minor);
        $this->assertNull($order->promotion);
        $this->assertSame(0, $order->promotion_discount_minor);
    }

    #[Test]
    public function an_abandoned_checkout_keeps_the_discount_for_the_next_try(): void
    {
        $user = User::factory()->create();

        $this->checkout($user, ['tier' => 'gold'])->assertCreated();
        $this->forgetAuthState();
        $this->checkout($user, ['tier' => 'gold'])->assertCreated();

        $this->assertSame([15960, 15960], Order::query()->pluck('amount_minor')->all());
    }

    #[Test]
    public function a_campaign_order_points_at_its_campaign(): void
    {
        $campaign = PriceCampaign::factory()->percent(25)->create();
        $returning = User::factory()->create();
        Order::factory()->for($returning)->paid()->create();

        $this->checkout($returning, ['tier' => 'elit'])->assertCreated();

        $order = Order::query()->where('status', OrderStatus::Pending->value)->sole();
        $this->assertSame(PromotionKind::Campaign, $order->promotion);
        $this->assertSame($campaign->id, $order->priceCampaign?->id);
        $this->assertSame(13725, $order->promotion_discount_minor);
        $this->assertSame(54900 - 13725, $order->amount_minor);
    }

    #[Test]
    public function a_percent_code_is_applied_to_what_is_left(): void
    {
        GiftCode::factory()->withCode('YARIYARIYA')->percent(50)->create();

        $this->checkout(User::factory()->create(), ['tier' => 'gold', 'giftCode' => 'YARIYARIYA'])
            ->assertCreated()
            ->assertJsonPath('data.discountMinor', 23940 + 7980);

        $order = Order::query()->sole();
        $this->assertSame(7980, $order->amount_minor);
        $this->assertSame(31920, $order->discount_minor);
        $this->assertSame(23940, $order->promotion_discount_minor);
        // Kullanım satırı yalnızca KODUN payını tutar.
        $this->assertSame(7980, GiftCodeRedemption::query()->sole()->discount_minor);
    }

    #[Test]
    public function a_free_code_on_a_first_order_is_a_paid_gift_order_that_keeps_the_breakdown(): void
    {
        GiftCode::factory()->withCode('BEDAVAGOLD')->free(SubscriptionTier::Gold)->create();

        $this->checkout(User::factory()->create(), ['tier' => 'gold', 'giftCode' => 'BEDAVAGOLD'])
            ->assertCreated()
            ->assertJsonPath('data.status', OrderStatus::Paid->value);

        $order = Order::query()->sole();
        $this->assertTrue($order->isGift());
        $this->assertSame(0, $order->amount_minor);
        $this->assertSame(39900, $order->discount_minor);
        $this->assertSame(PromotionKind::FirstPublish, $order->promotion);
        $this->assertSame(23940, $order->promotion_discount_minor);
        $this->assertSame(15960, GiftCodeRedemption::query()->sole()->discount_minor);
    }

    #[Test]
    public function the_body_cannot_lower_the_price(): void
    {
        $this->checkout(User::factory()->create(), [
            'tier' => 'gold',
            'priceMinor' => 1,
            'promotion' => ['percent' => 90],
            'discountMinor' => 39899,
        ])->assertCreated();

        $this->assertSame(15960, Order::query()->sole()->amount_minor);
    }

    /**
     * @param  array<string, mixed>  $body
     *
     * @return TestResponse<JsonResponse>
     */
    private function checkout(User $user, array $body): TestResponse
    {
        return $this->withToken($user->createToken('test')->plainTextToken)
            ->postJson(route('payments.checkout'), $body);
    }
}
