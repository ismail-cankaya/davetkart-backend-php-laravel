<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Order;
use App\Models\PriceCampaign;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Faz 13 (13.6): GET /api/public/plans ve GET /api/pricing (K128, K134, K135).
 * Ayrıntılı açıklama: docs/rehber/tests/Feature/PricingTest.md
 */
final class PricingTest extends TestCase
{
    use RefreshDatabase;

    private const VALID_IBAN = 'TR330006100519786457841326';

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('davetkart.tiers.standart.price', 249);
        Config::set('davetkart.tiers.gold.price', 399);
        Config::set('davetkart.tiers.elit.price', 549);
        Config::set('davetkart.pricing.first_publish_percent', 60);
        Config::set('payment.methods', 'gift_code');
    }

    #[Test]
    public function the_public_catalog_lists_the_three_plans_at_list_price(): void
    {
        $this->getJson(route('public.plans.index'))
            ->assertOk()
            ->assertExactJson(['data' => [
                'currency' => 'TRY',
                'personalized' => false,
                'firstPublishPercent' => 60,
                'plans' => [
                    ['tier' => 'standart', 'priceMinor' => 24900, 'promotion' => null, 'discountMinor' => 0, 'totalMinor' => 24900],
                    ['tier' => 'gold', 'priceMinor' => 39900, 'promotion' => null, 'discountMinor' => 0, 'totalMinor' => 39900],
                    ['tier' => 'elit', 'priceMinor' => 54900, 'promotion' => null, 'discountMinor' => 0, 'totalMinor' => 54900],
                ],
                'paymentMethods' => ['gift_code'],
                'bankTransfer' => null,
            ]]);
    }

    #[Test]
    public function the_public_catalog_shows_a_running_campaign_with_its_end(): void
    {
        $campaign = PriceCampaign::factory()->percent(20)->create(['ends_at' => now()->addDays(10), 'label' => 'GIZLI NOT']);

        $response = $this->getJson(route('public.plans.index'))
            ->assertOk()
            ->assertJsonPath('data.plans.1.promotion.kind', 'campaign')
            ->assertJsonPath('data.plans.1.promotion.percent', 20)
            ->assertJsonPath('data.plans.1.promotion.discountMinor', 7980)
            ->assertJsonPath('data.plans.1.promotion.endsAt', $campaign->ends_at?->toIso8601String())
            ->assertJsonPath('data.plans.1.totalMinor', 31920);

        // C1: kampanyanın kimliği ve notu dışarı çıkmaz.
        $this->assertStringNotContainsString($campaign->id, (string) $response->getContent());
        $this->assertStringNotContainsString('GIZLI NOT', (string) $response->getContent());
    }

    #[Test]
    public function the_public_catalog_never_applies_the_first_publish_discount_even_with_a_token(): void
    {
        $token = User::factory()->create()->createToken('t')->plainTextToken;

        $this->withToken($token)->getJson(route('public.plans.index'))
            ->assertOk()
            ->assertJsonPath('data.personalized', false)
            ->assertJsonPath('data.plans.1.promotion', null);
    }

    #[Test]
    public function the_personal_catalog_gives_a_new_user_the_first_publish_price(): void
    {
        $this->withToken(User::factory()->create()->createToken('t')->plainTextToken)
            ->getJson(route('pricing.show'))
            ->assertOk()
            ->assertJsonPath('data.personalized', true)
            ->assertJsonPath('data.plans.1.promotion.kind', 'first_publish')
            ->assertJsonPath('data.plans.1.promotion.endsAt', null)
            ->assertJsonPath('data.plans.1.totalMinor', 15960)
            ->assertJsonPath('data.plans.0.totalMinor', 9960)
            ->assertJsonPath('data.plans.2.totalMinor', 21960);
    }

    #[Test]
    public function the_personal_catalog_is_at_list_price_after_a_paid_order(): void
    {
        $user = User::factory()->create();
        Order::factory()->for($user)->paid()->create();

        $this->withToken($user->createToken('t')->plainTextToken)
            ->getJson(route('pricing.show'))
            ->assertOk()
            ->assertJsonPath('data.plans.1.promotion', null)
            ->assertJsonPath('data.plans.1.totalMinor', 39900);
    }

    #[Test]
    public function the_personal_catalog_needs_a_token(): void
    {
        $this->getJson(route('pricing.show'))->assertUnauthorized();
    }

    #[Test]
    public function the_bank_account_is_shown_only_when_bank_transfer_is_on(): void
    {
        Config::set('payment.bank_transfer.bank_name', 'Ziraat');
        Config::set('payment.bank_transfer.account_holder', 'DavetKart');
        Config::set('payment.bank_transfer.iban', self::VALID_IBAN);

        $this->getJson(route('public.plans.index'))->assertJsonPath('data.bankTransfer', null);

        Config::set('payment.methods', 'card,bank_transfer,gift_code');

        $this->getJson(route('public.plans.index'))
            ->assertJsonPath('data.paymentMethods', ['card', 'bank_transfer', 'gift_code'])
            ->assertJsonPath('data.bankTransfer', [
                'bankName' => 'Ziraat',
                'accountHolder' => 'DavetKart',
                'iban' => self::VALID_IBAN,
            ]);
    }

    #[Test]
    public function the_catalog_answers_304_when_nothing_changed(): void
    {
        $etag = $this->getJson(route('public.plans.index'))->assertOk()->headers->get('ETag');
        $this->assertNotNull($etag);

        $this->getJson(route('public.plans.index'), ['If-None-Match' => $etag])->assertStatus(304);

        PriceCampaign::factory()->create();

        $this->getJson(route('public.plans.index'), ['If-None-Match' => $etag])->assertOk();
    }

    #[Test]
    public function a_misspelled_setting_breaks_only_the_catalog_with_a_503(): void
    {
        Config::set('payment.methods', 'kart');

        $this->getJson(route('public.plans.index'))->assertStatus(503);
        $this->getJson('/api/ping')->assertOk();
    }
}
