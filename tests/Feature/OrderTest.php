<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ErrorCode;
use App\Enums\OrderStatus;
use App\Enums\SubscriptionTier;
use App\Models\Invitation;
use App\Models\Order;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Siparis okuma uclari (Faz 10, 10.22–10.25): GET /orders ve /orders/{order}.
 *
 * T13: ayni metotta iki kimlikli istek arasinda forgetAuthState().
 * T11: baskasinin siparisi ile var olmayan siparis HAM GOVDEDE ayirt edilemez.
 * Ayrintili aciklama: docs/rehber/tests/Feature/OrderTest.md
 */
final class OrderTest extends TestCase
{
    use RefreshDatabase;

    private const YOK_OLAN_ULID = '01arz3ndektsv4rrffq69g5fav';

    // ------------------------------------------------------------ KIMLIK

    #[Test]
    public function guest_cannot_read_orders(): void
    {
        $this->getJson(route('orders.index'))
            ->assertUnauthorized()
            ->assertJsonPath('error.code', ErrorCode::Unauthenticated->value);
    }

    // ---------------------------------------------------------- TEK KAYIT

    /**
     * Donus sayfasinin sorusu: "odendi mi, ne zaman, hangi davetiye icin?"
     * Tarihler sabit: donmus zaman, ISO 8601, UTC.
     */
    #[Test]
    public function the_owner_reads_a_paid_order(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-30 14:02:11', 'UTC'));

        $ayse = User::factory()->create();
        $inv = Invitation::factory()->for($ayse)->create();
        $order = Order::factory()->forInvitation($inv)->tier(SubscriptionTier::Gold)->paid()->create();

        $this->withToken($this->tokenFor($ayse))
            ->getJson(route('orders.show', $order))
            ->assertOk()
            ->assertExactJson(['data' => [
                'orderId' => $order->id,
                'tier' => 'gold',
                'status' => 'paid',
                'invitationId' => $inv->id,
                'createdAt' => '2026-09-30T14:02:11+00:00',
                'paidAt' => '2026-09-30T14:02:11+00:00',
            ]]);
    }

    /** @return iterable<string, array{OrderStatus}> */
    public static function unpaidStatuses(): iterable
    {
        yield 'bekliyor' => [OrderStatus::Pending];
        yield 'suresi doldu (K89)' => [OrderStatus::Expired];
        yield 'saglayici reddetti' => [OrderStatus::Failed];
    }

    /**
     * Donus sayfasi uc durumu ayri anlatir: bekliyor / tekrar dene. Her biri
     * `paidAt: null` ile gelir — anahtar VAR, degeri yok (N4).
     */
    #[Test]
    #[DataProvider('unpaidStatuses')]
    public function an_unpaid_order_reports_its_status_without_a_payment_time(OrderStatus $status): void
    {
        $ayse = User::factory()->create();
        $order = Order::factory()->for($ayse)->create(['status' => $status]);

        $data = $this->withToken($this->tokenFor($ayse))
            ->getJson(route('orders.show', $order))
            ->assertOk()
            ->json('data');

        $this->assertSame($status->value, $data['status']);
        $this->assertArrayHasKey('paidAt', $data);
        $this->assertNull($data['paidAt']);
    }

    /** Paket siparisi bir davetiyeye bagli degil: invitationId null, anahtar var. */
    #[Test]
    public function a_package_order_has_no_invitation(): void
    {
        $ayse = User::factory()->create();
        $order = Order::factory()->for($ayse)->package()->paid()->create();

        $this->withToken($this->tokenFor($ayse))
            ->getJson(route('orders.show', $order))
            ->assertOk()
            ->assertJsonPath('data.invitationId', null);
    }

    // ------------------------------------------------------- SAHIPLIK 🔴

    /**
     * 🔴 IDOR + T11: Mehmet, Ayse'nin siparis kimligini bilse bile hicbir sey
     * ogrenemez — yanit, hic var olmayan bir kimlikle BIREBIR ayni.
     */
    #[Test]
    public function another_users_order_is_indistinguishable_from_a_missing_one(): void
    {
        $ayse = User::factory()->create();
        $order = Order::factory()->for($ayse)->paid()->create();
        $mehmetToken = $this->tokenFor(User::factory()->create());

        $foreign = $this->withToken($mehmetToken)
            ->getJson(route('orders.show', $order))
            ->assertNotFound()
            ->assertJsonPath('error.code', ErrorCode::ResourceNotFound->value);

        $this->forgetAuthState();

        $missing = $this->withToken($mehmetToken)
            ->getJson(route('orders.show', self::YOK_OLAN_ULID))
            ->assertNotFound();

        $this->assertSame($missing->getContent(), $foreign->getContent());
    }

    /**
     * P3: liste YALNIZCA kendi siparisleri — Gate degil, sorgu kapsami.
     *
     * 🔴 Uc siparis KARISIK sirayla ekleniyor (orta, eski, yeni). Iki kayitla
     * beklenen sira ya ekleme sirasina ya tersine denk gelir ve siralamasiz
     * bir sorgu (PostgreSQL sira garanti etmez) testi SANSLA gecebilirdi —
     * 10.25'te oldu: ORDER BY silinince iki kayitli test yesil kaldi.
     */
    #[Test]
    public function the_list_contains_only_the_owners_orders_newest_first(): void
    {
        $ayse = User::factory()->create();

        $middle = $this->orderAt($ayse, '2026-09-02 10:00:00');
        $oldest = $this->orderAt($ayse, '2026-09-01 10:00:00');
        $newest = $this->orderAt($ayse, '2026-09-03 10:00:00');

        Order::factory()->count(2)->create(); // baska kullanicilarin

        $ids = $this->withToken($this->tokenFor($ayse))
            ->getJson(route('orders.index'))
            ->assertOk()
            ->json('data.*.orderId');

        $this->assertSame([$newest->id, $middle->id, $oldest->id], $ids);
    }

    /**
     * Ayni saniyede acilan siparisler: `created_at` esit, sirayi `id` (ULID)
     * belirler. Kimlikler ELLE veriliyor ve yine karisik sirayla ekleniyor.
     */
    #[Test]
    public function orders_opened_in_the_same_second_keep_a_stable_order(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-05 12:00:00', 'UTC'));
        $ayse = User::factory()->create();

        foreach (['01k6m0000000000000000000b1', '01k6m0000000000000000000a1', '01k6m0000000000000000000c1'] as $id) {
            Order::factory()->for($ayse)->create(['id' => $id]);
        }

        $ids = $this->withToken($this->tokenFor($ayse))
            ->getJson(route('orders.index'))
            ->assertOk()
            ->json('data.*.orderId');

        $this->assertSame(
            ['01k6m0000000000000000000c1', '01k6m0000000000000000000b1', '01k6m0000000000000000000a1'],
            $ids,
        );
    }

    /** T6'nin yokluk yarisi: hic siparisi olmayan kullanici bos liste alir, hata degil. */
    #[Test]
    public function a_user_without_orders_gets_an_empty_list(): void
    {
        Order::factory()->create(); // baskasinin

        $this->withToken($this->tokenFor(User::factory()->create()))
            ->getJson(route('orders.index'))
            ->assertOk()
            ->assertExactJson(['data' => []]);
    }

    // --------------------------------------------------------- SOZLESME

    /**
     * 🔴 C1: saglayici ic kimligi ve fiyat HICBIR YERDE yok. Ham govdede
     * aranir; alan adi degisse bile DEGERI yakalanir.
     */
    #[Test]
    public function provider_internals_and_amounts_never_leak(): void
    {
        $ayse = User::factory()->create();
        $order = Order::factory()->for($ayse)->paid()->create(['provider_ref' => 'sizmamali_ref_123']);

        foreach ([route('orders.show', $order), route('orders.index')] as $url) {
            $body = (string) $this->withToken($this->tokenFor($ayse))->getJson($url)->assertOk()->getContent();
            $this->forgetAuthState();

            $this->assertStringNotContainsString('sizmamali_ref_123', $body);
            $this->assertStringNotContainsString('provider', $body);
            $this->assertStringNotContainsString('amount', $body);
            $this->assertStringNotContainsString('userId', $body);
            $this->assertStringNotContainsString('expiresAt', $body);
        }
    }

    /** whereUlid: bicimsiz kimlik rotaya hic eslesmez, sorgu acilmaz (O6). */
    #[Test]
    public function a_malformed_order_id_is_a_plain_404(): void
    {
        $this->withToken($this->tokenFor(User::factory()->create()))
            ->getJson('/api/orders/1')
            ->assertNotFound()
            ->assertJsonPath('error.code', ErrorCode::ResourceNotFound->value);
    }

    /** Yazma ucu YOK: siparisi checkout acar, webhook degistirir. */
    #[Test]
    public function orders_cannot_be_written_through_the_api(): void
    {
        $ayse = User::factory()->create();
        $order = Order::factory()->for($ayse)->create();
        $token = $this->tokenFor($ayse);

        $this->withToken($token)->postJson('/api/orders', [])->assertStatus(404);
        $this->forgetAuthState();
        $this->withToken($token)->putJson(route('orders.show', $order), ['status' => 'paid'])->assertStatus(404);
        $this->forgetAuthState();
        $this->withToken($token)->deleteJson(route('orders.show', $order))->assertStatus(404);

        $this->assertSame(OrderStatus::Pending, $order->refresh()->status);
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken('api')->plainTextToken;
    }

    private function orderAt(User $user, string $utc): Order
    {
        $this->travelTo(CarbonImmutable::parse($utc, 'UTC'));

        return Order::factory()->for($user)->create();
    }
}
