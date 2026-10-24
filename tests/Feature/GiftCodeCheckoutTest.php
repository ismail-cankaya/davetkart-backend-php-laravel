<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ErrorCode;
use App\Enums\OrderStatus;
use App\Enums\SubscriptionTier;
use App\Events\InvitationChanged;
use App\Models\GiftCode;
use App\Models\GiftCodeRedemption;
use App\Models\Invitation;
use App\Models\Order;
use App\Models\User;
use App\Services\Payment\CheckoutSession;
use App\Services\Payment\FakeGateway;
use App\Services\Payment\PaymentGateway;
use App\Services\Payment\PaymentNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Faz 11: hediye koduyla ödeme başlatma (D1–D8).
 *
 * Testler yanıta değil ETKİYE bakar (T14): sipariş satırının tutarı, kullanım
 * satırının varlığı, sağlayıcının çağrılıp çağrılmadığı.
 * Ayrıntılı açıklama: docs/rehber/tests/Feature/GiftCodeCheckoutTest.md
 */
final class GiftCodeCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private const CODE = 'DUGUN2026';

    protected function setUp(): void
    {
        parent::setUp();

        // Hesap testten okunabilsin: 249 / 399 / 549 TL.
        Config::set('davetkart.tiers.standart.price', 249);
        Config::set('davetkart.tiers.gold.price', 399);
        Config::set('davetkart.tiers.elit.price', 549);
    }

    // ------------------------------------------------- kısmi indirim (D2)

    #[Test]
    public function a_percent_code_lowers_the_amount_and_still_goes_to_the_provider(): void
    {
        $code = GiftCode::factory()->withCode(self::CODE)->percent(50)->create();
        $user = User::factory()->create();

        $this->checkout($user, ['tier' => 'gold', 'giftCode' => self::CODE])
            ->assertCreated()
            ->assertJsonPath('data.status', OrderStatus::Pending->value)
            ->assertJsonPath('data.discountMinor', 19950)
            ->assertJsonStructure(['data' => ['redirectUrl']]);

        $order = Order::query()->sole();
        $this->assertSame(19950, $order->amount_minor);
        $this->assertSame(19950, $order->discount_minor);
        $this->assertSame('fake', $order->provider);
        $this->assertNotNull($order->expires_at);

        $redemption = GiftCodeRedemption::query()->sole();
        $this->assertSame($code->id, $redemption->gift_code_id);
        $this->assertSame($user->id, $redemption->user_id);
        $this->assertSame($order->id, $redemption->order_id);
        $this->assertSame(19950, $redemption->discount_minor);
    }

    #[Test]
    public function a_fixed_code_below_the_price_lowers_the_amount(): void
    {
        GiftCode::factory()->withCode(self::CODE)->fixed(5050)->create();

        $this->checkout(User::factory()->create(), ['tier' => 'standart', 'giftCode' => self::CODE])
            ->assertCreated()
            ->assertJsonPath('data.status', OrderStatus::Pending->value);

        $order = Order::query()->sole();
        $this->assertSame(24900 - 5050, $order->amount_minor);
        $this->assertSame(5050, $order->discount_minor);
    }

    #[Test]
    public function the_code_is_matched_regardless_of_case_spaces_and_dashes(): void
    {
        GiftCode::factory()->withCode('DK-7KQ2-M9XA-P4TR')->percent(10)->create();

        $this->checkout(User::factory()->create(), ['tier' => 'gold', 'giftCode' => ' dk7kq2 m9xa-p4tr '])
            ->assertCreated();

        $this->assertSame(1, GiftCodeRedemption::query()->count());
    }

    #[Test]
    public function without_a_code_nothing_changes(): void
    {
        $this->checkout(User::factory()->create(), ['tier' => 'gold', 'giftCode' => '   '])
            ->assertCreated()
            ->assertJsonMissingPath('data.discountMinor');

        $order = Order::query()->sole();
        $this->assertSame(39900, $order->amount_minor);
        $this->assertSame(0, $order->discount_minor);
        $this->assertSame(0, GiftCodeRedemption::query()->count());
    }

    // ------------------------------------------------- tamamen karşılanan (D1)

    /** @return array<string, array{string}> */
    public static function coveringCodes(): array
    {
        return ['free' => ['free'], '%100' => ['percent100'], 'fiyatı aşan sabit' => ['fixedOver']];
    }

    #[Test]
    #[DataProvider('coveringCodes')]
    public function a_covering_code_writes_a_paid_gift_order_without_the_provider(string $kind): void
    {
        $factory = GiftCode::factory()->withCode(self::CODE);
        match ($kind) {
            'free' => $factory->free(SubscriptionTier::Gold)->create(),
            'percent100' => $factory->percent(100)->create(),
            default => $factory->fixed(1_000_000)->create(),
        };

        // Sağlayıcı çağrılırsa patlar: hediye yolu ona hiç gitmemeli.
        $this->bindExplodingGateway();

        $response = $this->checkout(User::factory()->create(), ['tier' => 'gold', 'giftCode' => self::CODE])
            ->assertCreated()
            ->assertJsonPath('data.status', OrderStatus::Paid->value)
            ->assertJsonPath('data.discountMinor', 39900);

        $order = Order::query()->sole();
        $this->assertSame(OrderStatus::Paid, $order->status);
        $this->assertSame(Order::GIFT_PROVIDER, $order->provider);
        $this->assertSame(0, $order->amount_minor);
        $this->assertSame(39900, $order->discount_minor);
        $this->assertNotNull($order->paid_at);
        $this->assertNull($order->expires_at);
        $this->assertStringStartsWith('gift_', (string) $order->provider_ref);

        $response->assertJsonPath(
            'data.redirectUrl',
            Config::string('payment.return_urls.success').'?order='.$order->id,
        );
    }

    #[Test]
    public function a_gift_order_for_an_invitation_is_bound_to_it_and_clears_its_cache(): void
    {
        Event::fake([InvitationChanged::class]);
        GiftCode::factory()->withCode(self::CODE)->free(SubscriptionTier::Standart)->create();
        [$user, $invitation] = $this->ownedInvitation();

        $this->withToken($this->tokenFor($user))
            ->postJson(route('invitations.checkout', $invitation), ['tier' => 'standart', 'giftCode' => self::CODE])
            ->assertCreated()
            ->assertJsonPath('data.invitationId', $invitation->id);

        Event::assertDispatched(
            InvitationChanged::class,
            static fn (InvitationChanged $event): bool => $event->invitation->is($invitation),
        );
    }

    #[Test]
    public function a_gift_order_grants_the_publish_right(): void
    {
        GiftCode::factory()->withCode(self::CODE)->free(SubscriptionTier::Gold)->create();
        [$user, $invitation] = $this->ownedInvitation(['show_timeline' => true]); // gold gerekir

        $token = $this->tokenFor($user);

        $this->withToken($token)
            ->postJson(route('invitations.publish', $invitation))
            ->assertStatus(402);

        $this->withToken($token)
            ->postJson(route('invitations.checkout', $invitation), ['tier' => 'gold', 'giftCode' => self::CODE])
            ->assertCreated();

        $this->withToken($token)
            ->postJson(route('invitations.publish', $invitation))
            ->assertOk();
    }

    #[Test]
    public function an_unattached_gift_order_is_claimed_on_first_publish(): void
    {
        GiftCode::factory()->withCode(self::CODE)->free(SubscriptionTier::Elit)->create();
        [$user, $invitation] = $this->ownedInvitation(['show_gallery' => true]); // elit gerekir

        $token = $this->tokenFor($user);

        // Fiyat sayfasından: davetiyesiz.
        $this->withToken($token)
            ->postJson(route('payments.checkout'), ['tier' => 'elit', 'giftCode' => self::CODE])
            ->assertCreated()
            ->assertJsonPath('data.invitationId', null);

        $this->withToken($token)
            ->postJson(route('invitations.publish', $invitation))
            ->assertOk();

        $this->assertSame($invitation->id, Order::query()->sole()->invitation_id);
    }

    // ------------------------------------------------- hatalar (D7)

    #[Test]
    public function a_free_code_for_another_tier_is_not_applicable(): void
    {
        GiftCode::factory()->withCode(self::CODE)->free(SubscriptionTier::Standart)->create();

        $this->checkout(User::factory()->create(), ['tier' => 'elit', 'giftCode' => self::CODE])
            ->assertStatus(422)
            ->assertJsonPath('error.code', ErrorCode::GiftCodeNotApplicable->value)
            ->assertJsonPath('error.params.tier', SubscriptionTier::Standart->value);

        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, GiftCodeRedemption::query()->count());
    }

    /**
     * 🔴 Bilinmeyen, kapalı, süresi dolmuş, tükenmiş: dört yanıt BAYT BAYT aynı.
     * Ayrışsaydı kod deneyen biri hangi kodların var olduğunu öğrenirdi.
     */
    #[Test]
    public function every_invalid_reason_gets_the_same_bytes(): void
    {
        GiftCode::factory()->withCode('KAPALI01')->disabled()->create();
        GiftCode::factory()->withCode('SURESIDOLDU')->expired()->create();
        $exhausted = GiftCode::factory()->withCode('TUKENDI1')->maxUses(1)->create();
        GiftCodeRedemption::factory()->for($exhausted)->forOrder(Order::factory()->paid()->create())->create();

        $bodies = [];

        foreach (['YOKBOYLEKOD', 'KAPALI01', 'SURESIDOLDU', 'TUKENDI1'] as $code) {
            $response = $this->checkout(User::factory()->create(), ['tier' => 'gold', 'giftCode' => $code])
                ->assertStatus(422)
                ->assertJsonPath('error.code', ErrorCode::GiftCodeInvalid->value);

            $this->forgetAuthState();
            $bodies[$code] = (string) $response->getContent();
        }

        $this->assertCount(1, array_unique($bodies), 'Yanıtlar ayrışıyor: '.json_encode($bodies));
        $this->assertSame(0, Order::query()->where('amount_minor', '>', 0)->where('status', OrderStatus::Pending)->count());
    }

    #[Test]
    public function a_code_whose_expiry_is_still_ahead_works(): void
    {
        GiftCode::factory()->withCode(self::CODE)->create(['expires_at' => now()->addMinute()]);

        $this->checkout(User::factory()->create(), ['tier' => 'gold', 'giftCode' => self::CODE])
            ->assertCreated();
    }

    // ------------------------------------------------- kişi başı ve toplam (D4, D5, D8)

    #[Test]
    public function the_same_user_cannot_use_a_code_twice(): void
    {
        GiftCode::factory()->withCode(self::CODE)->percent(10)->maxUses(100)->create();
        $user = User::factory()->create();

        $this->checkout($user, ['tier' => 'gold', 'giftCode' => self::CODE])->assertCreated();
        $this->forgetAuthState();

        $this->checkout($user, ['tier' => 'standart', 'giftCode' => self::CODE])
            ->assertStatus(422)
            ->assertJsonPath('error.code', ErrorCode::GiftCodeAlreadyUsed->value);

        $this->assertSame(1, Order::query()->count());
    }

    #[Test]
    public function an_expired_order_gives_the_redemption_back(): void
    {
        GiftCode::factory()->withCode(self::CODE)->percent(10)->maxUses(1)->create();
        $user = User::factory()->create();

        $this->checkout($user, ['tier' => 'gold', 'giftCode' => self::CODE])->assertCreated();
        $this->forgetAuthState();

        // Ödeme penceresi geçer, orders:expire siparişi expired yapar.
        $this->travel(Config::integer('payment.order_expires_after_minutes') + 1)->minutes();
        Artisan::call('orders:expire');
        $this->assertSame(OrderStatus::Expired, Order::query()->sole()->status);

        $this->checkout($user, ['tier' => 'gold', 'giftCode' => self::CODE])->assertCreated();

        $this->assertSame(2, GiftCodeRedemption::query()->count());
        $this->assertSame(1, GiftCodeRedemption::query()->active()->count());
    }

    #[Test]
    public function a_failing_provider_marks_the_order_failed_and_frees_the_code(): void
    {
        GiftCode::factory()->withCode(self::CODE)->percent(10)->maxUses(1)->create();
        $user = User::factory()->create();

        $this->bindExplodingGateway();
        $this->checkout($user, ['tier' => 'gold', 'giftCode' => self::CODE])
            ->assertStatus(502);

        $this->assertSame(OrderStatus::Failed, Order::query()->sole()->status);
        $this->assertSame(0, GiftCodeRedemption::query()->active()->count());

        // Sağlayıcı düzelince aynı kişi aynı kodu yeniden dener.
        $this->app->bind(PaymentGateway::class, FakeGateway::class);
        $this->forgetAuthState();

        $this->checkout($user, ['tier' => 'gold', 'giftCode' => self::CODE])->assertCreated();
        $this->assertSame(1, GiftCodeRedemption::query()->active()->count());
    }

    #[Test]
    public function a_single_use_code_is_invalid_for_the_second_user(): void
    {
        GiftCode::factory()->withCode(self::CODE)->percent(10)->maxUses(1)->create();

        $this->checkout(User::factory()->create(), ['tier' => 'gold', 'giftCode' => self::CODE])->assertCreated();
        $this->forgetAuthState();

        $this->checkout(User::factory()->create(), ['tier' => 'gold', 'giftCode' => self::CODE])
            ->assertStatus(422)
            ->assertJsonPath('error.code', ErrorCode::GiftCodeInvalid->value);
    }

    /** D8: iade edilen sipariş kullanımı GERİ VERMEZ (iade et, tekrar kullan döngüsü yok). */
    #[Test]
    public function a_refund_does_not_give_the_redemption_back(): void
    {
        $code = GiftCode::factory()->withCode(self::CODE)->percent(10)->maxUses(1)->create();
        $user = User::factory()->create();
        GiftCodeRedemption::factory()->for($code)
            ->forOrder(Order::factory()->for($user)->refunded()->create())
            ->create();

        $this->checkout($user, ['tier' => 'gold', 'giftCode' => self::CODE])
            ->assertStatus(422)
            ->assertJsonPath('error.code', ErrorCode::GiftCodeAlreadyUsed->value);
        $this->forgetAuthState();

        $this->checkout(User::factory()->create(), ['tier' => 'gold', 'giftCode' => self::CODE])
            ->assertStatus(422)
            ->assertJsonPath('error.code', ErrorCode::GiftCodeInvalid->value);
    }

    /**
     * D10 (bilinen sınır): süresi dolmuş siparişe geç gelen ödeme kullanımı
     * yeniden aktifleştirir; kod max'ı 1 aşabilir. Kod yazılmadı, test belgeler.
     */
    #[Test]
    public function a_late_payment_reactivates_the_redemption_beyond_the_maximum(): void
    {
        $code = GiftCode::factory()->withCode(self::CODE)->percent(10)->maxUses(1)->create();
        $late = Order::factory()->create(['status' => OrderStatus::Expired]);
        GiftCodeRedemption::factory()->for($code)->forOrder($late)->create();

        // Kullanım iade oldu; başka biri kodu kullanıyor.
        $this->checkout(User::factory()->create(), ['tier' => 'gold', 'giftCode' => self::CODE])->assertCreated();

        // Sonra eski siparişin parası geliyor (K89: expired -> paid).
        $late->status = OrderStatus::Paid;
        $late->paid_at = now();
        $late->save();

        $this->assertSame(2, GiftCode::query()->withActiveRedemptionCount()->sole()->active_redemptions_count);
    }

    // ------------------------------------------------- güvenlik

    /** 🔴 Tutar ve indirim istekten OKUNMAZ. */
    #[Test]
    public function amount_and_discount_in_the_body_are_ignored(): void
    {
        GiftCode::factory()->withCode(self::CODE)->percent(10)->create();

        $this->checkout(User::factory()->create(), [
            'tier' => 'gold',
            'giftCode' => self::CODE,
            'amount' => 1,
            'amountMinor' => 1,
            'discount' => 39900,
            'discountMinor' => 39900,
            'price' => 1,
        ])->assertCreated()->assertJsonPath('data.status', OrderStatus::Pending->value);

        $order = Order::query()->sole();
        $this->assertSame(35910, $order->amount_minor);
        $this->assertSame(3990, $order->discount_minor);
    }

    /** Ödeme engeli (402) koddan ÖNCE: yetmeyen plana kod harcanmaz. */
    #[Test]
    public function an_insufficient_tier_is_rejected_before_the_code_is_spent(): void
    {
        GiftCode::factory()->withCode(self::CODE)->free(SubscriptionTier::Standart)->create();
        [$user, $invitation] = $this->ownedInvitation(['show_gallery' => true]);

        $this->withToken($this->tokenFor($user))
            ->postJson(route('invitations.checkout', $invitation), ['tier' => 'standart', 'giftCode' => self::CODE])
            ->assertStatus(402);

        $this->assertSame(0, GiftCodeRedemption::query()->count());
    }

    #[Test]
    public function a_too_long_code_is_a_validation_error(): void
    {
        $this->checkout(User::factory()->create(), ['tier' => 'gold', 'giftCode' => str_repeat('A', 65)])
            ->assertStatus(422)
            ->assertJsonPath('error.code', ErrorCode::ValidationFailed->value);
    }

    /** 🔴 D3: düz kod veritabanına ve log'a girmez. */
    #[Test]
    public function the_plain_code_is_stored_and_logged_nowhere(): void
    {
        $logger = Log::spy();

        GiftCode::factory()->withCode(self::CODE)->percent(10)->create();
        $this->checkout(User::factory()->create(), ['tier' => 'gold', 'giftCode' => self::CODE])->assertCreated();

        // Kullanım log'a düşüyor, ama yalnızca ipucuyla.
        $logger->shouldHaveReceived('info', [
            'Gift code redeemed',
            Mockery::on(static fn (array $context): bool => $context['code_hint'] === '2026'
                && ! str_contains((string) json_encode($context), self::CODE)),
        ]);

        foreach (['gift_codes', 'gift_code_redemptions', 'orders'] as $table) {
            $dump = json_encode(DB::table($table)->get());
            $this->assertStringNotContainsString(self::CODE, (string) $dump, $table);
        }
    }

    // ------------------------------------------------- yardımcılar

    /**
     * @param  array<string, mixed>  $body
     *
     * @return TestResponse<JsonResponse>
     */
    private function checkout(User $user, array $body): TestResponse
    {
        return $this->withToken($this->tokenFor($user))
            ->postJson(route('payments.checkout'), $body);
    }

    /**
     * @param  array<string, mixed>  $overrides
     *
     * @return array{User, Invitation}
     */
    private function ownedInvitation(array $overrides = []): array
    {
        $user = User::factory()->create();

        /** @var array<string, mixed> $attributes */
        $attributes = array_merge(['user_id' => $user->id], $overrides);

        return [$user, Invitation::factory()->create($attributes)];
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    /** PaywallTest'teki ile aynı: her çağrıda patlayan sağlayıcı. */
    private function bindExplodingGateway(): void
    {
        $this->app->bind(PaymentGateway::class, fn (): PaymentGateway => new class implements PaymentGateway
        {
            public function name(): string
            {
                return 'exploding';
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
