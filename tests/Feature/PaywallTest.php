<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\PublishEntitlementResolver;
use App\Contracts\RsvpQuotaResolver;
use App\Enums\ErrorCode;
use App\Enums\InvitationStatus;
use App\Enums\OrderStatus;
use App\Enums\RsvpStatus;
use App\Enums\SubscriptionTier;
use App\Models\Invitation;
use App\Models\Order;
use App\Models\User;
use App\Services\Payment\CheckoutSession;
use App\Services\Payment\PaymentGateway;
use App\Services\Payment\PaymentNotification;
use App\Services\Pricing\TierResolver;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Config;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Faz 7'nin kaniti: projenin TICARI CEKIRDEGI.
 *
 * 🔴 Bu dosyanin en onemli testleri YANITA DEGIL ETKIYE bakar (T14):
 *   - Ayni webhook iki kez -> kaniti `paid_at` damgasinin DEGISMEMESI
 *   - Gecersiz imza        -> kaniti siparisin HALA 'pending' olmasi
 *   - Sunucu fiyati        -> kaniti `amount_minor` KOLONU
 *   - Bilinmeyen referans  -> kaniti hicbir satirin degismemesi
 *
 * Ayrintili aciklama: docs/rehber/tests/Feature/PaywallTest.md
 */
final class PaywallTest extends TestCase
{
    use RefreshDatabase;

    private const YOK_OLAN_ULID = '01arz3ndektsv4rrffq69g5fav';

    // ------------------------------------------------- TierResolver (7.8)

    #[Test]
    public function a_plain_invitation_requires_the_standart_tier(): void
    {
        $invitation = Invitation::factory()->create();

        $this->assertSame(
            SubscriptionTier::Standart,
            app(TierResolver::class)->requiredFor($invitation),
        );
    }

    #[Test]
    public function a_timeline_invitation_requires_the_gold_tier(): void
    {
        $invitation = Invitation::factory()->create(['show_timeline' => true]);

        $this->assertSame(
            SubscriptionTier::Gold,
            app(TierResolver::class)->requiredFor($invitation),
        );
    }

    #[Test]
    public function a_gallery_invitation_requires_the_elit_tier(): void
    {
        $invitation = Invitation::factory()->create(['show_gallery' => true]);

        $this->assertSame(
            SubscriptionTier::Elit,
            app(TierResolver::class)->requiredFor($invitation),
        );
    }

    /** Birden cok modul acikken EN YUKSEK gereksinim kazanir. */
    #[Test]
    public function the_highest_required_module_wins(): void
    {
        $invitation = Invitation::factory()->create([
            'show_timer' => true,      // standart
            'show_timeline' => true,   // gold
            'show_gift' => true,       // elit
        ]);

        $this->assertSame(
            SubscriptionTier::Elit,
            app(TierResolver::class)->requiredFor($invitation),
        );
    }

    // --------------------------------------- PublishEntitlementResolver (7.9)

    #[Test]
    public function an_invitation_without_orders_has_no_entitlement(): void
    {
        $this->assertNull(
            $this->entitlements()->highestTierFor(Invitation::factory()->create()),
        );
    }

    #[Test]
    public function a_paid_order_for_the_invitation_grants_its_tier(): void
    {
        $invitation = Invitation::factory()->create();

        Order::factory()->paid()->tier(SubscriptionTier::Gold)
            ->forInvitation($invitation)->create();

        $this->assertSame(
            SubscriptionTier::Gold,
            $this->entitlements()->highestTierFor($invitation),
        );
    }

    /** 🔴 K42'nin ikinci kolu: paket alim hesabin TUM davetiyelerini acar. */
    #[Test]
    public function a_package_order_grants_the_tier_account_wide(): void
    {
        $user = User::factory()->create();
        $invitation = Invitation::factory()->create(['user_id' => $user->id]);

        Order::factory()->paid()->tier(SubscriptionTier::Elit)->package()
            ->create(['user_id' => $user->id]);

        $this->assertSame(
            SubscriptionTier::Elit,
            $this->entitlements()->highestTierFor($invitation),
        );
    }

    /** 🔴 IDOR'un odeme katmanindaki hali: baskasinin paketi bu davetiyeyi acmaz. */
    #[Test]
    public function another_users_package_does_not_grant_publish_rights(): void
    {
        $invitation = Invitation::factory()->create();

        Order::factory()->paid()->tier(SubscriptionTier::Elit)->package()->create();

        $this->assertNull($this->entitlements()->highestTierFor($invitation));
    }

    #[Test]
    public function a_pending_order_grants_nothing(): void
    {
        $invitation = Invitation::factory()->create();

        Order::factory()->tier(SubscriptionTier::Elit)->forInvitation($invitation)->create();

        $this->assertNull($this->entitlements()->highestTierFor($invitation));
    }

    #[Test]
    public function a_refunded_order_grants_nothing(): void
    {
        $invitation = Invitation::factory()->create();

        Order::factory()->refunded()->tier(SubscriptionTier::Elit)
            ->forInvitation($invitation)->create();

        $this->assertNull($this->entitlements()->highestTierFor($invitation));
    }

    #[Test]
    public function the_highest_paid_tier_wins(): void
    {
        $user = User::factory()->create();
        $invitation = Invitation::factory()->create(['user_id' => $user->id]);

        Order::factory()->paid()->tier(SubscriptionTier::Standart)
            ->forInvitation($invitation)->create();
        Order::factory()->paid()->tier(SubscriptionTier::Elit)->package()
            ->create(['user_id' => $user->id]);

        $this->assertSame(
            SubscriptionTier::Elit,
            $this->entitlements()->highestTierFor($invitation),
        );
    }

    // ------------------------------------------------- YAYIN UCU (7.12)

    #[Test]
    public function publishing_requires_authentication(): void
    {
        $invitation = Invitation::factory()->create();

        $this->postJson(route('invitations.publish', $invitation))
            ->assertUnauthorized();

        $this->assertDatabaseHas('invitations', [
            'id' => $invitation->id,
            'status' => InvitationStatus::Saved->value,
        ]);
    }

    /** 🔴 IDOR: baskasinin davetiyesi 404 — 403 DEGIL (H7). */
    #[Test]
    public function owner_cannot_publish_someone_elses_invitation(): void
    {
        $invitation = Invitation::factory()->create();
        $intruder = User::factory()->create();

        $this->withToken($this->tokenFor($intruder))
            ->postJson(route('invitations.publish', $invitation))
            ->assertNotFound()
            ->assertJsonPath('error.code', ErrorCode::ResourceNotFound->value);

        $this->assertDatabaseHas('invitations', [
            'id' => $invitation->id,
            'status' => InvitationStatus::Saved->value,
        ]);
    }

    /** 🔴 Hic odeme yok -> PAYMENT_REQUIRED, gereken plan bildirilir. */
    #[Test]
    public function publishing_without_any_order_returns_payment_required(): void
    {
        [$user, $invitation] = $this->ownedInvitation(['show_gallery' => true]);

        $this->withToken($this->tokenFor($user))
            ->postJson(route('invitations.publish', $invitation))
            ->assertStatus(402)
            ->assertJsonPath('error.code', ErrorCode::PaymentRequired->value)
            ->assertJsonPath('error.params.requiredTier', SubscriptionTier::Elit->value);

        $this->assertDatabaseHas('invitations', [
            'id' => $invitation->id,
            'status' => InvitationStatus::Saved->value,
            'published_at' => null,
        ]);
    }

    /** 🔴 Fazin bitti olcutu: Standart/Gold plan galerili davetiyeyi acamaz. */
    #[Test]
    public function a_gold_order_cannot_publish_a_gallery_invitation(): void
    {
        [$user, $invitation] = $this->ownedInvitation(['show_gallery' => true]);

        Order::factory()->paid()->tier(SubscriptionTier::Gold)
            ->forInvitation($invitation)->create();

        $this->withToken($this->tokenFor($user))
            ->postJson(route('invitations.publish', $invitation))
            ->assertStatus(402)
            ->assertJsonPath('error.code', ErrorCode::PaywallTierInsufficient->value)
            ->assertJsonPath('error.params.requiredTier', SubscriptionTier::Elit->value);

        $this->assertDatabaseHas('invitations', [
            'id' => $invitation->id,
            'status' => InvitationStatus::Saved->value,
        ]);
    }

    #[Test]
    public function a_paid_order_publishes_the_invitation(): void
    {
        [$user, $invitation] = $this->ownedInvitation(['show_gallery' => true]);

        Order::factory()->paid()->tier(SubscriptionTier::Elit)
            ->forInvitation($invitation)->create();

        $this->withToken($this->tokenFor($user))
            ->postJson(route('invitations.publish', $invitation))
            ->assertOk()
            ->assertJsonPath('data.id', $invitation->id)
            ->assertJsonPath('data.status', InvitationStatus::Published->value);

        $this->assertDatabaseHas('invitations', [
            'id' => $invitation->id,
            'status' => InvitationStatus::Published->value,
        ]);

        $this->assertNotNull($invitation->refresh()->published_at);
    }

    /** 🔴 Ikinci yayin istegi 409 — sessizce basarili DEGIL (7.12 §4). */
    #[Test]
    public function publishing_twice_returns_conflict(): void
    {
        [$user, $invitation] = $this->ownedInvitation();

        Order::factory()->paid()->forInvitation($invitation)->create();

        $token = $this->tokenFor($user);

        $this->withToken($token)->postJson(route('invitations.publish', $invitation))->assertOk();

        // T13: ikinci kimlikli istekten once guard sifirlanir.
        $this->forgetAuthState();

        $this->withToken($token)
            ->postJson(route('invitations.publish', $invitation))
            ->assertStatus(409)
            ->assertJsonPath('error.code', ErrorCode::InvitationAlreadyPublished->value);
    }

    /** Uctan uca: yayin, misafir ucunu gercekten aciyor mu? */
    #[Test]
    public function publishing_makes_the_invitation_publicly_visible(): void
    {
        [$user, $invitation] = $this->ownedInvitation();

        Order::factory()->paid()->forInvitation($invitation)->create();

        $this->getJson(route('public.invitations.show', $invitation))->assertNotFound();

        $this->withToken($this->tokenFor($user))
            ->postJson(route('invitations.publish', $invitation))
            ->assertOk();

        $this->forgetAuthState();

        $this->getJson(route('public.invitations.show', $invitation))
            ->assertOk()
            ->assertJsonPath('data.id', $invitation->id);
    }

    // ------------------------------------------------- CHECKOUT (7.10 / 7.14)

    #[Test]
    public function checkout_requires_authentication(): void
    {
        $this->postJson(route('payments.checkout'), ['tier' => 'gold'])
            ->assertUnauthorized();

        $this->assertDatabaseCount('orders', 0);
    }

    /** 🔴 D6: hata zarfindaki kural ADI 'in' — sinif adi SIZMAZ. */
    #[Test]
    public function checkout_rejects_an_unknown_tier(): void
    {
        $user = User::factory()->create();

        $this->withToken($this->tokenFor($user))
            ->postJson(route('payments.checkout'), ['tier' => 'platinum'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', ErrorCode::ValidationFailed->value)
            ->assertJsonPath('error.fields.tier.0.rule', 'in');

        $this->assertDatabaseCount('orders', 0);
    }

    #[Test]
    public function checkout_for_someone_elses_invitation_returns_not_found(): void
    {
        $invitation = Invitation::factory()->create();
        $intruder = User::factory()->create();

        $this->withToken($this->tokenFor($intruder))
            ->postJson(route('invitations.checkout', $invitation), ['tier' => 'elit'])
            ->assertNotFound()
            ->assertJsonPath('error.code', ErrorCode::ResourceNotFound->value);

        $this->assertDatabaseCount('orders', 0);
    }

    /** Var olmayan davetiye ile baskasininki AYNI yaniti verir. */
    #[Test]
    public function checkout_for_a_missing_invitation_returns_not_found(): void
    {
        $user = User::factory()->create();

        $this->withToken($this->tokenFor($user))
            ->postJson('/api/invitations/'.self::YOK_OLAN_ULID.'/checkout', ['tier' => 'elit'])
            ->assertNotFound();

        $this->assertDatabaseCount('orders', 0);
    }

    /** 🔴 Fiyat SUNUCUDAN gelir — govdedeki deger yok sayilir. */
    #[Test]
    public function the_order_amount_comes_from_the_server_side_price(): void
    {
        $user = User::factory()->create();

        $this->withToken($this->tokenFor($user))
            ->postJson(route('payments.checkout'), [
                'tier' => 'elit',
                'price' => 1,
                'amountMinor' => 1,
            ])
            ->assertCreated();

        $this->assertDatabaseHas('orders', [
            'user_id' => $user->id,
            'tier' => SubscriptionTier::Elit->value,
            'status' => OrderStatus::Pending->value,
            'amount_minor' => SubscriptionTier::Elit->price() * 100,
        ]);
    }

    #[Test]
    public function a_tier_that_does_not_cover_the_invitation_is_rejected(): void
    {
        [$user, $invitation] = $this->ownedInvitation(['show_gallery' => true]);

        $this->withToken($this->tokenFor($user))
            ->postJson(route('invitations.checkout', $invitation), ['tier' => 'standart'])
            ->assertStatus(402)
            ->assertJsonPath('error.code', ErrorCode::PaywallTierInsufficient->value);

        $this->assertDatabaseCount('orders', 0);
    }

    #[Test]
    public function the_package_checkout_creates_an_order_without_an_invitation(): void
    {
        $user = User::factory()->create();

        $this->withToken($this->tokenFor($user))
            ->postJson(route('payments.checkout'), ['tier' => 'gold'])
            ->assertCreated()
            ->assertJsonStructure(['data' => ['orderId', 'tier', 'status', 'redirectUrl']])
            ->assertJsonPath('data.status', OrderStatus::Pending->value);

        $this->assertDatabaseHas('orders', [
            'user_id' => $user->id,
            'invitation_id' => null,
            'tier' => SubscriptionTier::Gold->value,
        ]);
    }

    /** 🔴 C1: idempotans anahtari istemciye OGRETILMEZ. */
    #[Test]
    public function the_checkout_response_never_exposes_the_provider_ref(): void
    {
        $user = User::factory()->create();

        $this->withToken($this->tokenFor($user))
            ->postJson(route('payments.checkout'), ['tier' => 'gold'])
            ->assertCreated()
            ->assertJsonMissingPath('data.providerRef')
            ->assertJsonMissingPath('data.provider')
            ->assertJsonMissingPath('data.amountMinor');
    }

    /** 🔴 F3'un dis servis hali: saglayici patlarsa siparis 'failed' kalir. */
    #[Test]
    public function a_failing_gateway_marks_the_order_failed_and_returns_502(): void
    {
        $this->bindExplodingGateway();

        $user = User::factory()->create();

        $this->withToken($this->tokenFor($user))
            ->postJson(route('payments.checkout'), ['tier' => 'gold'])
            ->assertStatus(502)
            ->assertJsonPath('error.code', ErrorCode::PaymentProviderError->value);

        // T14: yanit degil ETKI. Siparis silinmedi, 'failed' isaretlendi.
        $this->assertDatabaseHas('orders', [
            'user_id' => $user->id,
            'status' => OrderStatus::Failed->value,
            'provider_ref' => null,
        ]);
    }

    // ------------------------------------------------- WEBHOOK (7.11 / 7.14)

    /** 🔴 Imza tek savunma: gecersizse 404 (401/403 DEGIL) ve satir DEGISMEZ. */
    #[Test]
    public function the_webhook_rejects_an_invalid_signature(): void
    {
        $order = Order::factory()->create(['provider_ref' => 'ref-1']);

        $this->withHeader($this->signatureHeader(), 'kesinlikle-yanlis')
            ->postJson(route('public.payments.webhook'), [
                'providerRef' => 'ref-1',
                'status' => 'paid',
            ])
            ->assertNotFound()
            ->assertJsonPath('error.code', ErrorCode::ResourceNotFound->value);

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => OrderStatus::Pending->value,
            'paid_at' => null,
        ]);
    }

    #[Test]
    public function a_signed_webhook_marks_the_order_paid(): void
    {
        $order = Order::factory()->create(['provider_ref' => 'ref-1']);

        $this->signedWebhook(['providerRef' => 'ref-1', 'status' => 'paid'])
            ->assertNoContent();

        $order->refresh();

        $this->assertSame(OrderStatus::Paid, $order->status);
        $this->assertNotNull($order->paid_at);
    }

    /** 🔴 IDEMPOTANS. Kanit yanit degil, DAMGANIN DEGISMEMESI (T14). */
    #[Test]
    public function the_same_webhook_twice_does_not_move_paid_at(): void
    {
        $order = Order::factory()->create(['provider_ref' => 'ref-1']);

        $this->signedWebhook(['providerRef' => 'ref-1', 'status' => 'paid'])->assertNoContent();

        $firstStamp = $order->refresh()->paid_at;
        $this->assertNotNull($firstStamp);

        // Zaman ILERLETILIYOR: damga yeniden yazilsaydi FARKLI olurdu.
        // (Faz 6, ders 49: zaman ortuk bir girdidir; testte acikca kontrol edilir.)
        $this->travel(5)->minutes();

        $this->signedWebhook(['providerRef' => 'ref-1', 'status' => 'paid'])->assertNoContent();

        $this->assertSame(
            $firstStamp->getTimestamp(),
            $order->refresh()->paid_at?->getTimestamp(),
        );
        $this->assertDatabaseCount('orders', 1);
    }

    /** Odenmis bir siparis 'failed'e DUSMEZ — durum makinesi izin vermiyor. */
    #[Test]
    public function a_paid_order_cannot_be_moved_back_to_failed(): void
    {
        $order = Order::factory()->paid()->create(['provider_ref' => 'ref-1']);

        $this->signedWebhook(['providerRef' => 'ref-1', 'status' => 'failed'])
            ->assertNoContent();

        $this->assertSame(OrderStatus::Paid, $order->refresh()->status);
    }

    /** Iade: hak duser ama odeme ANI korunur. */
    #[Test]
    public function a_refund_keeps_the_paid_at_stamp(): void
    {
        $order = Order::factory()->paid()->create(['provider_ref' => 'ref-1']);
        $stamp = $order->paid_at;

        $this->signedWebhook(['providerRef' => 'ref-1', 'status' => 'refunded'])
            ->assertNoContent();

        $order->refresh();

        $this->assertSame(OrderStatus::Refunded, $order->status);
        $this->assertNotNull($stamp);
        $this->assertSame($stamp->getTimestamp(), $order->paid_at?->getTimestamp());
    }

    /** 🔴 Bilinmeyen referans 204 alir: 404 saglayiciyi sonsuza kadar retry ettirir. */
    #[Test]
    public function an_unknown_provider_ref_is_accepted_silently(): void
    {
        Order::factory()->create(['provider_ref' => 'ref-1']);

        $this->signedWebhook(['providerRef' => 'bilinmeyen', 'status' => 'paid'])
            ->assertNoContent();

        $this->assertDatabaseHas('orders', [
            'provider_ref' => 'ref-1',
            'status' => OrderStatus::Pending->value,
        ]);
    }

    /** Imza gecerli ama govde anlamsiz -> 400 (404 DEGIL: gonderen mesru). */
    #[Test]
    public function a_malformed_payload_is_rejected(): void
    {
        $this->signedWebhook(['foo' => 'bar'])
            ->assertStatus(400)
            ->assertJsonPath('error.code', ErrorCode::MalformedRequest->value);
    }

    #[Test]
    public function an_unknown_provider_status_is_rejected(): void
    {
        Order::factory()->create(['provider_ref' => 'ref-1']);

        $this->signedWebhook(['providerRef' => 'ref-1', 'status' => 'captured'])
            ->assertStatus(400);

        $this->assertDatabaseHas('orders', [
            'provider_ref' => 'ref-1',
            'status' => OrderStatus::Pending->value,
        ]);
    }

    /** 🔴 Odeme != yayin. Webhook davetiyeye DOKUNMAZ (7.11 §8). */
    #[Test]
    public function the_webhook_does_not_publish_the_invitation(): void
    {
        $invitation = Invitation::factory()->create();

        Order::factory()->forInvitation($invitation)->create(['provider_ref' => 'ref-1']);

        $this->signedWebhook(['providerRef' => 'ref-1', 'status' => 'paid'])->assertNoContent();

        $this->assertDatabaseHas('invitations', [
            'id' => $invitation->id,
            'status' => InvitationStatus::Saved->value,
            'published_at' => null,
        ]);
    }

    // ------------------------------------------------- LCV KOTASI (7.16)

    /** 🔴 Faz 5'in dikis yeri: odeme yoksa EN DAR kota. */
    #[Test]
    public function an_unpaid_invitation_falls_back_to_the_narrowest_quota(): void
    {
        $invitation = Invitation::factory()->create();

        $this->assertSame(
            SubscriptionTier::Standart->rsvpLimit(),
            app(RsvpQuotaResolver::class)->limitFor($invitation),
        );
    }

    /** Gold plan: kota SINIRSIZ -> `null` (ders 45). */
    #[Test]
    public function a_gold_order_makes_the_rsvp_quota_unlimited(): void
    {
        $invitation = Invitation::factory()->create();

        Order::factory()->paid()->tier(SubscriptionTier::Gold)
            ->forInvitation($invitation)->create();

        $this->assertNull(app(RsvpQuotaResolver::class)->limitFor($invitation));
    }

    // ------------------------------------------------- K63 SAAT DILIMI (7.17)

    /**
     * 🔴 Ayni an, ayni son tarih, IKI FARKLI saat dilimi -> iki farkli sonuc.
     *
     * Zaman donduruluyor (travelTo): aksi halde test kosma saatine gore
     * bazen yesil bazen kirmizi olurdu — Faz 6'nin 49. dersi.
     */
    #[Test]
    public function the_rsvp_deadline_is_evaluated_in_the_invitation_timezone(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-03 01:00:00', 'UTC'));

        // UTC+14: orada artik 3 Eylul 15:00 -> 2 Eylul GECMISTE kaldi.
        $ahead = $this->openInvitation([
            'timezone' => 'Pacific/Kiritimati',
            'rsvp_deadline' => '2026-09-02',
        ]);

        $this->postJson(route('public.invitations.rsvps.store', $ahead), $this->rsvpPayload())
            ->assertStatus(403)
            ->assertJsonPath('error.code', ErrorCode::RsvpDeadlinePassed->value);

        // UTC-11: orada hala 2 Eylul 14:00 -> son gun DAHIL.
        $behind = $this->openInvitation([
            'timezone' => 'Pacific/Niue',
            'rsvp_deadline' => '2026-09-02',
        ]);

        $this->postJson(route('public.invitations.rsvps.store', $behind), $this->rsvpPayload())
            ->assertCreated();
    }

    /** Misafir surumu saat dilimini HER ZAMAN tasir (C7). */
    #[Test]
    public function the_public_payload_always_carries_a_timezone(): void
    {
        $invitation = Invitation::factory()->published()->create(['timezone' => null]);

        $this->getJson(route('public.invitations.show', $invitation))
            ->assertOk()
            ->assertJsonPath(
                'data.invitation.timezone',
                Config::string('davetkart.default_timezone'),
            );
    }

    // -------------------------------------------------------- YARDIMCI

    private function entitlements(): PublishEntitlementResolver
    {
        return app(PublishEntitlementResolver::class);
    }

    /**
     * @param  array<string, mixed>  $overrides
     *
     * @return array{0: User, 1: Invitation}
     */
    private function ownedInvitation(array $overrides = []): array
    {
        $user = User::factory()->create();

        /** @var array<string, mixed> $attributes */
        $attributes = array_merge(['user_id' => $user->id], $overrides);

        return [$user, Invitation::factory()->create($attributes)];
    }

    /**
     * Yayinda, LCV acik davetiye.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function openInvitation(array $overrides = []): Invitation
    {
        /** @var array<string, mixed> $attributes */
        $attributes = array_merge(['show_rsvp' => true], $overrides);

        return Invitation::factory()->published()->create($attributes);
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    private function signatureHeader(): string
    {
        return Config::string('payment.webhook.signature_header');
    }

    /**
     * Imzasi DOGRU bir webhook gonderir.
     *
     * 🔴 Imza, Laravel'in govdeyi serilestirdigi bicimin uzerinden
     * hesaplaniyor. json_encode ile postJson ayni ciktiyi urettigi icin
     * imza tutuyor — gercek hayatta da kural aynidir: imza NEYIN uzerinden
     * hesaplandiysa dogrulama da onun uzerinden yapilir.
     *
     * @param  array<string, mixed>  $payload
     *
     * @return TestResponse<JsonResponse> postJson()'in dondugu tip; jenerik
     *                                    parametre PHPStan level 8'de zorunlu
     */
    private function signedWebhook(array $payload): TestResponse
    {
        $body = json_encode($payload);

        $signature = hash_hmac(
            'sha256',
            is_string($body) ? $body : '',
            Config::string('app.key'),
        );

        return $this->withHeader($this->signatureHeader(), $signature)
            ->postJson(route('public.payments.webhook'), $payload);
    }

    /**
     * @param  array<string, mixed>  $overrides
     *
     * @return array<string, mixed>
     */
    private function rsvpPayload(array $overrides = []): array
    {
        return array_merge([
            'guestName' => 'Melis Kaya',
            'guestCount' => 2,
            'status' => RsvpStatus::Attending->value,
        ], $overrides);
    }

    /**
     * Her cagrida patlayan bir saglayici baglar.
     *
     * 🔴 Arayuzun ikinci ve daha az konusulan kazanci: 502 yolu, GERCEK bir
     * saglayici olmadan test edilebiliyor. Somut sinifa bagimli olsaydik bu
     * testi yazmanin tek yolu agi kesmekti.
     */
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

            public function parseNotification(string $payload, string $signature): PaymentNotification
            {
                throw new RuntimeException('not used');
            }
        });
    }
}
