<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ErrorCode;
use App\Enums\SubscriptionTier;
use App\Models\GiftCode;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** K262 (A-E5, SEC-10 D2): Shopier'e alıcının gerçek telefonu gider; yalnızca siparişte, yalnızca gerektiğinde. */
final class CheckoutBuyerPhoneTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('payment.default', 'shopier');
        Config::set('payment.providers.shopier.api_key', 'test-key');
        Config::set('payment.providers.shopier.api_secret', 'test-secret');
        Config::set('payment.providers.shopier.confirmation', 'osb');
        Config::set('payment.providers.shopier.osb.username', 'osb-user');
        Config::set('payment.providers.shopier.osb.key', 'osb-key');
    }

    #[Test]
    public function a_card_checkout_without_a_phone_is_refused_before_any_order(): void
    {
        $this->checkout(User::factory()->create(), ['tier' => 'gold'])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', ErrorCode::ValidationFailed->value)
            ->assertJsonPath('error.fields.phone.0.rule', 'required');

        $this->assertDatabaseCount('orders', 0);
    }

    #[Test]
    public function the_phone_is_normalised_stored_on_the_order_and_sent_to_shopier(): void
    {
        $fields = $this->checkout(User::factory()->create(), ['tier' => 'gold', 'phone' => '+90 (532) 123-45 67'])
            ->assertCreated()
            ->json('data.checkout.fields');

        $this->assertIsArray($fields);
        $this->assertSame('05321234567', $fields['buyer_phone'] ?? null);
        $this->assertSame('05321234567', Order::query()->sole()->buyer_phone);
        $this->assertNotContains('05000000000', $fields);
    }

    #[Test]
    public function a_landline_or_garbage_is_not_a_mobile_phone(): void
    {
        foreach (['0212 123 45 67', '5321234567', 'telefon'] as $phone) {
            $this->checkout(User::factory()->create(), ['tier' => 'gold', 'phone' => $phone])
                ->assertUnprocessable()
                ->assertJsonPath('error.fields.phone.0.rule', 'regex');
        }

        $this->assertDatabaseCount('orders', 0);
    }

    /** K160 ile yeniden açılan aynı niyetli sipariş düzeltilmiş telefonu taşır. */
    #[Test]
    public function a_reopened_order_takes_the_corrected_phone(): void
    {
        $user = User::factory()->create();

        $this->checkout($user, ['tier' => 'gold', 'phone' => '05321234567'])->assertCreated();
        $this->checkout($user, ['tier' => 'gold', 'phone' => '05439876543'])->assertCreated();

        $this->assertSame('05439876543', Order::query()->sole()->buyer_phone);
    }

    /** En az veri: hediye kodunun tamamen karşıladığı ve telefon istemeyen sürücüdeki sipariş telefon tutmaz. */
    #[Test]
    public function no_phone_is_asked_or_kept_when_nothing_is_paid_or_the_driver_does_not_need_it(): void
    {
        GiftCode::factory()->withCode('HEPSI-BEDAVA')->free(SubscriptionTier::Gold)->create();

        $this->checkout(User::factory()->create(), ['tier' => 'gold', 'giftCode' => 'HEPSI-BEDAVA'])->assertCreated();

        Config::set('payment.default', 'fake');
        $this->checkout(User::factory()->create(), ['tier' => 'gold', 'phone' => '05321234567'])->assertCreated();

        $this->assertSame([null, null], Order::query()->orderBy('created_at')->pluck('buyer_phone')->all());
    }

    /**
     * @param  array<string, string>  $body
     *
     * @return TestResponse<\Illuminate\Http\JsonResponse>
     */
    private function checkout(User $user, array $body): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($user->createToken('test')->plainTextToken)->postJson(route('payments.checkout'), $body);
    }
}
