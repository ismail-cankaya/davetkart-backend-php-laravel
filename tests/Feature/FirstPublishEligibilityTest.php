<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Invitation;
use App\Models\Order;
use App\Models\User;
use App\Services\Pricing\FirstPublishEligibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Faz 18.3 (SEC-01, K159): "bu kullanıcı şu an ilk yayın indirimine uygun mu?"
 *
 * Kural tek yerde; fiyat önizlemesi de checkout da bu sınıfa sorar.
 * Ayrıntılı açıklama: docs/rehber/app/Services/Pricing/FirstPublishEligibility.md
 */
final class FirstPublishEligibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('davetkart.pricing.first_publish_percent', 60);
    }

    #[Test]
    public function a_user_without_orders_is_eligible_for_any_target(): void
    {
        $invitation = Invitation::factory()->create();

        $this->assertTrue($this->eligible($this->ownerOf($invitation), $invitation->id));
        $this->assertTrue($this->eligible($this->ownerOf($invitation), null));
    }

    /** @return array<string, array{string}> */
    public static function paidStates(): array
    {
        return ['paid' => ['paid'], 'refunded' => ['refunded']];
    }

    /** K131 yerinde: ilk yayın bir kez yaşanır, hedef ne olursa olsun. */
    #[Test]
    #[DataProvider('paidStates')]
    public function an_order_that_was_ever_paid_ends_it_for_every_target(string $state): void
    {
        $invitation = Invitation::factory()->create();
        $factory = Order::factory()->forInvitation($invitation);

        $state === 'paid' ? $factory->paid()->create() : $factory->refunded()->create();

        $this->assertFalse($this->eligible($this->ownerOf($invitation), $invitation->id));
        $this->assertFalse($this->eligible($this->ownerOf($invitation), null));
    }

    #[Test]
    public function an_open_discounted_order_keeps_the_discount_for_its_own_invitation_only(): void
    {
        $invitation = Invitation::factory()->create();
        $user = $this->ownerOf($invitation);
        $other = Invitation::factory()->for($user)->create();
        Order::factory()->forInvitation($invitation)->firstPublish()->create();

        $this->assertTrue($this->eligible($user, $invitation->id));
        $this->assertFalse($this->eligible($user, $other->id));
        $this->assertFalse($this->eligible($user, null));
    }

    #[Test]
    public function an_open_discounted_package_order_keeps_it_for_the_package_only(): void
    {
        $invitation = Invitation::factory()->create();
        $user = $this->ownerOf($invitation);
        Order::factory()->for($user)->package()->firstPublish()->create();

        $this->assertTrue($this->eligible($user, null));
        $this->assertFalse($this->eligible($user, $invitation->id));
    }

    #[Test]
    public function an_open_discounted_order_without_a_window_still_holds_it(): void
    {
        $invitation = Invitation::factory()->create();
        Order::factory()->forInvitation($invitation)->firstPublish()->create(['expires_at' => null]);

        $this->assertFalse($this->eligible($this->ownerOf($invitation), null));
    }

    /** @return array<string, array{string}> */
    public static function ordersThatHoldNothing(): array
    {
        return [
            'pending at list price' => ['list'],
            'expired' => ['expired'],
            'failed' => ['failed'],
            'pending but past its window' => ['stale'],
            'someone else\'s order' => ['foreign'],
        ];
    }

    #[Test]
    #[DataProvider('ordersThatHoldNothing')]
    public function only_an_open_first_publish_order_of_the_same_user_holds_the_discount(string $case): void
    {
        $invitation = Invitation::factory()->create();
        $mine = Order::factory()->forInvitation($invitation);

        match ($case) {
            'list' => $mine->create(),
            'expired' => $mine->firstPublish()->create(['status' => OrderStatus::Expired]),
            'failed' => $mine->firstPublish()->failed()->create(),
            'stale' => $mine->firstPublish()->create(['expires_at' => now()->subMinute()]),
            'foreign' => Order::factory()->firstPublish()->create(),
            default => $this->fail("Bilinmeyen durum: {$case}"),
        };

        // Başka bir hedef (paket) için soruluyor: yukarıdakilerin hiçbiri hakkı tutmuyor.
        $this->assertTrue($this->eligible($this->ownerOf($invitation), null));
    }

    #[Test]
    public function the_answer_costs_one_query(): void
    {
        $invitation = Invitation::factory()->create();
        $user = $this->ownerOf($invitation);

        DB::enableQueryLog();
        $this->eligible($user, $invitation->id);

        $this->assertCount(1, DB::getQueryLog());
    }

    private function eligible(User $user, ?string $invitationId): bool
    {
        return app(FirstPublishEligibility::class)->isEligible($user, $invitationId);
    }

    private function ownerOf(Invitation $invitation): User
    {
        return User::query()->findOrFail($invitation->user_id);
    }
}
