<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ErrorCode;
use App\Enums\OrderStatus;
use App\Enums\PromotionKind;
use App\Enums\SubscriptionTier;
use App\Models\GiftCode;
use App\Models\GiftCodeRedemption;
use App\Models\Invitation;
use App\Models\Order;
use App\Models\PriceCampaign;
use App\Models\User;
use App\Services\Payment\CheckoutSession;
use App\Services\Payment\PaymentGateway;
use App\Services\Payment\PaymentNotification;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
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

    /**
     * Terk edilen ödeme indirimi yakmaz (K131). Faz 18.3: penceresi dolan sipariş
     * `orders:expire` beklenmeden kapanır, yoksa index yeni siparişi bloklardı (K161).
     */
    #[Test]
    public function an_abandoned_checkout_keeps_the_discount_for_the_next_try(): void
    {
        $user = User::factory()->create();

        $this->checkout($user, ['tier' => 'gold'])->assertCreated();
        $abandoned = Order::query()->sole();

        $this->travel(Config::integer('payment.order_expires_after_minutes') + 1)->minutes();
        $this->forgetAuthState();
        $this->checkout($user, ['tier' => 'gold'])->assertCreated();

        $this->assertSame(OrderStatus::Expired, $abandoned->refresh()->status);

        $fresh = Order::query()->where('status', OrderStatus::Pending->value)->sole();
        $this->assertNotSame($abandoned->id, $fresh->id);
        $this->assertSame(15960, $fresh->amount_minor);
        $this->assertSame(PromotionKind::FirstPublish, $fresh->promotion);
    }

    // ------------------------------------------------- Faz 18.3 · SEC-01 (K158–K161)

    /** SEC-01'in kendisi: iki hedef, iki açık sipariş, TEK indirim. */
    #[Test]
    public function the_first_publish_discount_is_granted_to_one_order_only(): void
    {
        $invitation = Invitation::factory()->create();
        $user = $this->ownerOf($invitation);

        $this->checkoutFor($invitation, ['tier' => 'gold'])->assertCreated();
        $this->forgetAuthState();
        $this->checkout($user, ['tier' => 'gold'])->assertCreated();

        $first = Order::query()->where('invitation_id', $invitation->id)->sole();
        $this->assertSame(PromotionKind::FirstPublish, $first->promotion);
        $this->assertSame(15960, $first->amount_minor);

        $second = Order::query()->whereNull('invitation_id')->sole();
        $this->assertNull($second->promotion);
        $this->assertSame(0, $second->promotion_discount_minor);
        $this->assertSame(39900, $second->amount_minor);
    }

    /** K160: aynı niyet iki kez gelirse ikinci istek birincinin siparişini döndürür. */
    #[Test]
    public function a_second_checkout_for_the_same_target_reuses_the_pending_order(): void
    {
        $invitation = Invitation::factory()->create();

        $first = $this->checkoutFor($invitation, ['tier' => 'gold'])->assertCreated();
        $before = Order::query()->sole();
        $this->forgetAuthState();
        $second = $this->checkoutFor($invitation, ['tier' => 'gold'])
            ->assertCreated()
            ->assertJsonPath('data.status', OrderStatus::Pending->value);

        $order = Order::query()->sole();
        $this->assertSame($first->json('data.orderId'), $second->json('data.orderId'));
        $this->assertSame($first->json('data.checkout'), $second->json('data.checkout'));
        $this->assertSame(15960, $order->amount_minor);

        // Sipariş olduğu gibi döner: kimliği de penceresi de aynı.
        $this->assertSame($before->provider_ref, $order->provider_ref);
        $this->assertNotNull($before->expires_at);
        $this->assertSame($before->expires_at->toIso8601String(), $order->expires_at?->toIso8601String());
    }

    #[Test]
    public function a_second_checkout_for_the_same_package_reuses_the_pending_order(): void
    {
        $user = User::factory()->create();

        $this->checkout($user, ['tier' => 'gold'])->assertCreated();
        $this->forgetAuthState();
        $this->checkout($user, ['tier' => 'gold'])->assertCreated();

        $this->assertSame(15960, Order::query()->sole()->amount_minor);
    }

    /** K159: açık indirimli sipariş varken her hedefin önizlemesi, o hedefin checkout'uyla aynı tutarı söyler. */
    #[Test]
    public function the_quote_and_the_checkout_agree_on_the_first_publish_discount(): void
    {
        $holder = Invitation::factory()->create();
        $user = $this->ownerOf($holder);
        $other = Invitation::factory()->for($user)->create();

        $this->checkoutFor($holder, ['tier' => 'gold'])->assertCreated();

        foreach ([[$holder, 15960], [$other, 39900]] as [$target, $expected]) {
            $this->forgetAuthState();
            $quoted = $this->withToken($user->createToken('test')->plainTextToken)
                ->getJson(route('pricing.show', ['invitation' => $target->id]))
                ->assertOk()
                ->json('data.plans.1.totalMinor');

            $this->forgetAuthState();
            $this->checkoutFor($target, ['tier' => 'gold'])->assertCreated();

            $charged = Order::query()
                ->where('invitation_id', $target->id)
                ->where('status', OrderStatus::Pending->value)
                ->sole()
                ->amount_minor;

            $this->assertSame($expected, $quoted);
            $this->assertSame($quoted, $charged);
        }
    }

    /** K161: aynı hedefte farklı plan eskisini kapatır; indirim yeni siparişe geçer. */
    #[Test]
    public function a_different_plan_for_the_same_target_closes_the_old_order_and_takes_the_discount(): void
    {
        $invitation = Invitation::factory()->create();

        $this->checkoutFor($invitation, ['tier' => 'gold'])->assertCreated();
        $old = Order::query()->sole();
        $this->forgetAuthState();
        $this->checkoutFor($invitation, ['tier' => 'elit'])->assertCreated();

        // `failed` DEĞİL `expired`: geç gelen ödeme yine kabul edilebilmeli (K89).
        $this->assertSame(OrderStatus::Expired, $old->refresh()->status);

        $new = Order::query()->where('status', OrderStatus::Pending->value)->sole();
        $this->assertSame(SubscriptionTier::Elit, $new->tier);
        $this->assertSame(PromotionKind::FirstPublish, $new->promotion);
        $this->assertSame(21960, $new->amount_minor);
    }

    /** Fiyat arada değiştiyse eski sipariş geri verilmez: gösterilen = tahsil edilen. */
    #[Test]
    public function a_price_that_changed_between_two_tries_opens_a_new_order(): void
    {
        $invitation = Invitation::factory()->create();

        $this->checkoutFor($invitation, ['tier' => 'gold'])->assertCreated();
        $old = Order::query()->sole();

        PriceCampaign::factory()->percent(70)->create();
        $this->forgetAuthState();
        $this->checkoutFor($invitation, ['tier' => 'gold'])->assertCreated();

        $this->assertSame(OrderStatus::Expired, $old->refresh()->status);

        $new = Order::query()->where('status', OrderStatus::Pending->value)->sole();
        $this->assertSame(PromotionKind::Campaign, $new->promotion);
        $this->assertSame(39900 - 27930, $new->amount_minor);
    }

    /** Tutar aynı olsa da plan niyetin parçasıdır: Gold siparişi Elit isteğine geri verilmez. */
    #[Test]
    public function another_plan_at_the_same_price_is_not_the_same_intent(): void
    {
        Config::set('davetkart.tiers.elit.price', 399);
        $invitation = Invitation::factory()->create();

        $this->checkoutFor($invitation, ['tier' => 'gold'])->assertCreated();
        $this->forgetAuthState();
        $this->checkoutFor($invitation, ['tier' => 'elit'])->assertCreated();

        $pending = Order::query()->where('status', OrderStatus::Pending->value)->sole();
        $this->assertSame(SubscriptionTier::Elit, $pending->tier);
        $this->assertSame(2, Order::query()->count());
    }

    /** İndirimsiz siparişte de: liste fiyatı arada değiştiyse eski tutar geri verilmez. */
    #[Test]
    public function a_list_price_that_changed_between_two_tries_opens_a_new_order(): void
    {
        $invitation = Invitation::factory()->create();
        Order::factory()->for($this->ownerOf($invitation))->paid()->create();

        $this->checkoutFor($invitation, ['tier' => 'gold'])->assertCreated();
        $old = Order::query()->where('status', OrderStatus::Pending->value)->sole();
        $this->assertSame(39900, $old->amount_minor);

        Config::set('davetkart.tiers.gold.price', 449);
        $this->forgetAuthState();
        $this->checkoutFor($invitation, ['tier' => 'gold'])->assertCreated();

        $this->assertSame(OrderStatus::Expired, $old->refresh()->status);
        $this->assertSame(44900, Order::query()->where('status', OrderStatus::Pending->value)->sole()->amount_minor);
    }

    /** Aynı kod + aynı hedef + aynı plan: tek sipariş, tek kullanım (eskiden GIFT_CODE_ALREADY_USED). */
    #[Test]
    public function the_same_gift_code_for_the_same_target_reuses_the_order(): void
    {
        GiftCode::factory()->withCode('YARIYARIYA')->percent(50)->create();
        $invitation = Invitation::factory()->create();

        $this->checkoutFor($invitation, ['tier' => 'gold', 'giftCode' => 'YARIYARIYA'])->assertCreated();
        $this->forgetAuthState();
        $this->checkoutFor($invitation, ['tier' => 'gold', 'giftCode' => 'yari-yariya'])->assertCreated();

        $this->assertSame(7980, Order::query()->sole()->amount_minor);
        $this->assertSame(1, GiftCodeRedemption::query()->count());
    }

    /** Kodu bırakmak ya da değiştirmek farklı bir niyettir: eski sipariş kapanır, kodu serbest kalır. */
    #[Test]
    public function dropping_the_gift_code_closes_the_old_order_and_frees_the_code(): void
    {
        GiftCode::factory()->withCode('YARIYARIYA')->percent(50)->create();
        $invitation = Invitation::factory()->create();

        $this->checkoutFor($invitation, ['tier' => 'gold', 'giftCode' => 'YARIYARIYA'])->assertCreated();
        $withCode = Order::query()->sole();
        $this->forgetAuthState();
        $this->checkoutFor($invitation, ['tier' => 'gold'])->assertCreated();

        $this->assertSame(OrderStatus::Expired, $withCode->refresh()->status);
        $this->assertSame(15960, Order::query()->where('status', OrderStatus::Pending->value)->sole()->amount_minor);
        $this->assertSame(0, GiftCodeRedemption::query()->active()->count());

        // Serbest kalan kod aynı hedefte yeniden kullanılabilir.
        $this->forgetAuthState();
        $this->checkoutFor($invitation, ['tier' => 'gold', 'giftCode' => 'YARIYARIYA'])->assertCreated();

        $this->assertSame(7980, Order::query()->where('status', OrderStatus::Pending->value)->sole()->amount_minor);
        $this->assertSame(1, GiftCodeRedemption::query()->active()->count());
    }

    /** Geçersiz kod eski siparişi KAPATMAZ: hata transaction'ı geri alır. */
    #[Test]
    public function an_invalid_gift_code_leaves_the_open_order_untouched(): void
    {
        $invitation = Invitation::factory()->create();

        $this->checkoutFor($invitation, ['tier' => 'gold'])->assertCreated();
        $this->forgetAuthState();
        $this->checkoutFor($invitation, ['tier' => 'gold', 'giftCode' => 'YOKBOYLEKOD'])->assertStatus(422);

        $order = Order::query()->sole();
        $this->assertSame(OrderStatus::Pending, $order->status);
        $this->assertSame(PromotionKind::FirstPublish, $order->promotion);
    }

    /** Değişmezin 1. katmanı (TST-02 deseni): kilit, siparişlere bakılmadan ÖNCE alınır. */
    #[Test]
    public function the_user_row_is_locked_before_the_checkout_looks_at_orders(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        /** @var list<string> $statements */
        $statements = [];
        DB::listen(static function (QueryExecuted $query) use (&$statements): void {
            $statements[] = strtolower($query->sql);
        });

        $this->withToken($token)->postJson(route('payments.checkout'), ['tier' => 'gold'])->assertCreated();

        $lock = $this->firstIndexMatching($statements, '/from "users".*for update/');
        $orders = $this->firstIndexMatching($statements, '/"orders"/');

        $this->assertNotNull($lock, 'Checkout kullanıcı satırını kilitlemedi (lockForUpdate).');
        $this->assertNotNull($orders);
        $this->assertLessThan($orders, $lock, 'Kilit, sipariş sorgularından SONRA alındı.');
    }

    /** Sağlayıcı ikinci denemede patlarsa ilk oturumu hâlâ ödenebilir sipariş `failed` yapılmaz. */
    #[Test]
    public function a_provider_failure_on_a_reused_order_leaves_it_pending(): void
    {
        $invitation = Invitation::factory()->create();
        $order = Order::factory()->forInvitation($invitation)->firstPublish()->create();
        $this->bindExplodingGateway($order->provider);

        $this->checkoutFor($invitation, ['tier' => 'standart'])->assertStatus(502);

        $this->assertSame(OrderStatus::Pending, Order::query()->sole()->status);
    }

    /** K134 yeniden kullanmada da geçerli: kart kapalıysa bekleyen sipariş geri verilmez. */
    #[Test]
    public function a_closed_card_method_refuses_the_reuse_too(): void
    {
        $invitation = Invitation::factory()->create();

        $this->checkoutFor($invitation, ['tier' => 'gold'])->assertCreated();
        Config::set('payment.methods', 'gift_code');
        $this->forgetAuthState();

        $this->checkoutFor($invitation, ['tier' => 'gold'])
            ->assertForbidden()
            ->assertJsonPath('error.code', ErrorCode::PaymentMethodDisabled->value);

        $this->assertSame(OrderStatus::Pending, Order::query()->sole()->status);
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
     * Davetiyesiz (paket) checkout.
     *
     * @param  array<string, mixed>  $body
     *
     * @return TestResponse<JsonResponse>
     */
    private function checkout(User $user, array $body): TestResponse
    {
        return $this->withToken($user->createToken('test')->plainTextToken)
            ->postJson(route('payments.checkout'), $body);
    }

    /**
     * Bir davetiye için checkout, sahibinin adına.
     *
     * @param  array<string, mixed>  $body
     *
     * @return TestResponse<JsonResponse>
     */
    private function checkoutFor(Invitation $invitation, array $body): TestResponse
    {
        return $this->withToken($this->ownerOf($invitation)->createToken('test')->plainTextToken)
            ->postJson(route('invitations.checkout', $invitation), $body);
    }

    private function ownerOf(Invitation $invitation): User
    {
        return User::query()->findOrFail($invitation->user_id);
    }

    /** @param  list<string>  $statements */
    private function firstIndexMatching(array $statements, string $pattern): ?int
    {
        foreach ($statements as $index => $sql) {
            if (preg_match($pattern, $sql) === 1) {
                return $index;
            }
        }

        return null;
    }

    /** `$name` adıyla kurulan ama oturum açamayan sağlayıcı (PaywallTest'teki ile aynı fikir). */
    private function bindExplodingGateway(string $name): void
    {
        $this->app->bind(PaymentGateway::class, fn (): PaymentGateway => new class($name) implements PaymentGateway
        {
            public function __construct(private readonly string $name) {}

            public function name(): string
            {
                return $this->name;
            }

            public function startCheckout(Order $order): CheckoutSession
            {
                throw new RuntimeException('provider is down');
            }

            public function parseNotification(Request $request): PaymentNotification
            {
                throw new RuntimeException('not used');
            }
        });
    }
}
