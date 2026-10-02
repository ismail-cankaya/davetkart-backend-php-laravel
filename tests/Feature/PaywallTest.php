<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Invitation\DeleteInvitationAction;
use App\Contracts\PublishEntitlementResolver;
use App\Contracts\RsvpQuotaResolver;
use App\Enums\ErrorCode;
use App\Enums\InvitationStatus;
use App\Enums\OrderScope;
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
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /**
     * Faz 10 (10.49): her modulun plani, ALTI birden ve sabit.
     *
     * Ustteki testler yalnizca program (gold) ve galeriyi (elit) sinyordu;
     * `show_envelope` gold'dan standart'a indirilse dosya yesil kaliyordu
     * (TEST-DENETIMI §2). Zarf animasyonu Standart'a bedava acilmis olurdu.
     *
     * @param  'show_gallery'|'show_gift'|'show_envelope'|'show_timeline'|'show_timer'|'show_rsvp'  $column
     */
    #[Test]
    #[DataProvider('moduleTiers')]
    public function each_module_requires_its_published_tier(string $column, SubscriptionTier $tier): void
    {
        $invitation = Invitation::factory()->create([$column => true]);

        $this->assertSame($tier, app(TierResolver::class)->requiredFor($invitation));
    }

    /** @return array<string, array{'show_gallery'|'show_gift'|'show_envelope'|'show_timeline'|'show_timer'|'show_rsvp', SubscriptionTier}> */
    public static function moduleTiers(): array
    {
        return [
            'galeri elit' => ['show_gallery', SubscriptionTier::Elit],
            'hediye elit' => ['show_gift', SubscriptionTier::Elit],
            'zarf gold' => ['show_envelope', SubscriptionTier::Gold],
            'program gold' => ['show_timeline', SubscriptionTier::Gold],
            'geri sayim standart' => ['show_timer', SubscriptionTier::Standart],
            'lcv standart' => ['show_rsvp', SubscriptionTier::Standart],
        ];
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

    /**
     * Faz 10 (10.58 · K99): bagsiz paket kendi basina hicbir davetiyeye hak
     * vermez. Faz 9'a kadar hesabin TUM davetiyelerini aciyordu.
     */
    #[Test]
    public function an_unclaimed_package_grants_nothing_on_its_own(): void
    {
        $user = User::factory()->create();
        $invitation = Invitation::factory()->create(['user_id' => $user->id]);

        Order::factory()->paid()->tier(SubscriptionTier::Elit)->package()
            ->create(['user_id' => $user->id]);

        $this->assertNull($this->entitlements()->highestTierFor($invitation));
    }

    /**
     * 🔴 Faz 10 (10.58 · K99): paket TEK davetiye yayinlar.
     *
     * Ilk yayin paketi o davetiyeye baglar; ikinci davetiye icin hak kalmaz.
     * Fiyat sayfasindaki fiyatlar davetiye basina.
     */
    #[Test]
    public function a_package_publishes_exactly_one_invitation(): void
    {
        [$user, $first] = $this->ownedInvitation();
        $second = Invitation::factory()->create(['user_id' => $user->id]);

        $package = Order::factory()->paid()->tier(SubscriptionTier::Elit)->package()
            ->create(['user_id' => $user->id]);

        $token = $this->tokenFor($user);

        $this->withToken($token)
            ->postJson(route('invitations.publish', $first))
            ->assertOk();

        $this->assertSame($first->id, $package->refresh()->invitation_id);

        $this->withToken($token)
            ->postJson(route('invitations.publish', $second))
            ->assertStatus(402)
            ->assertJsonPath('error.code', ErrorCode::PaymentRequired->value);
    }

    /** Faz 10 (10.58): eski 'account' satırları bağsız tekil siparişe çevrilir. */
    #[Test]
    public function the_migration_turns_old_packages_into_unclaimed_orders(): void
    {
        $legacy = Order::factory()->paid()->tier(SubscriptionTier::Elit)
            ->create(['scope' => OrderScope::Account]);

        $migration = require database_path('migrations/2026_10_02_100000_convert_package_orders_to_unattached.php');
        if (! is_object($migration) || ! method_exists($migration, 'up')) {
            $this->fail('Migration dosyası bir migration nesnesi döndürmedi.');
        }
        $migration->up();

        $legacy->refresh();
        $this->assertSame(OrderScope::Invitation, $legacy->scope);
        $this->assertNull($legacy->invitation_id);
    }

    /**
     * 🔴 IDOR'un odeme katmanindaki hali: baskasinin siparisi bu davetiyeyi acmaz.
     *
     * Faz 10 (K99): hak artik bagli siparisten geliyor. Baskasinin siparisi
     * normal bir akisla bu davetiyeye baglanamaz; test bozuk veriyi elle
     * kuruyor ve resolver'daki `user_id` kosulunun (savunma katmani) onu yine
     * de reddettigini gosteriyor.
     */
    #[Test]
    public function another_users_order_grants_nothing_even_if_bound_here(): void
    {
        $invitation = Invitation::factory()->create();

        Order::factory()->paid()->tier(SubscriptionTier::Elit)
            ->create(['invitation_id' => $invitation->id]);

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

    /** Faz 10 (K89): suresi dolmus siparis ne hak verir ne de odenmis sayilir. */
    #[Test]
    public function an_expired_order_grants_nothing(): void
    {
        $invitation = Invitation::factory()->create();

        Order::factory()->tier(SubscriptionTier::Elit)->forInvitation($invitation)
            ->create(['status' => OrderStatus::Expired]);

        $this->assertNull($this->entitlements()->highestTierFor($invitation));
    }

    #[Test]
    public function the_highest_paid_tier_wins(): void
    {
        $user = User::factory()->create();
        $invitation = Invitation::factory()->create(['user_id' => $user->id]);

        Order::factory()->paid()->tier(SubscriptionTier::Standart)
            ->forInvitation($invitation)->create();
        Order::factory()->paid()->tier(SubscriptionTier::Elit)
            ->forInvitation($invitation)->create();

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

    // --------------------------------- YAYINDAKI DAVETIYEDE MODUL (10.1 · K88)

    /**
     * 🔴 Rapor §1.1 · denetim K-1: Standart ile yayinlanan davetiyede galeri
     * sonradan ACILAMAZ.
     *
     * Kanit yanit degil ETKI (T14): bayrak hala `false` ve AYNI istekteki
     * baslik da yazilmadi. Kayit parca parca degil, TEK PARCA reddedildi.
     */
    #[Test]
    public function a_published_invitation_cannot_enable_a_module_above_its_tier(): void
    {
        [$user, $invitation] = $this->publishedInvitation(SubscriptionTier::Standart, [
            'title' => 'Düğünümüze Davetlisiniz',
        ]);

        $this->withToken($this->tokenFor($user))
            ->putJson(route('invitations.update', $invitation), ['invitation' => [
                'title' => 'Nikâhımıza Davetlisiniz',
                'showGallery' => true,
            ]])
            ->assertStatus(402)
            ->assertJsonPath('error.code', ErrorCode::PaywallTierInsufficient->value)
            ->assertJsonPath('error.params.requiredTier', SubscriptionTier::Elit->value);

        $this->assertDatabaseHas('invitations', [
            'id' => $invitation->id,
            'show_gallery' => false,
            'title' => 'Düğünümüze Davetlisiniz',
        ]);
    }

    /** Plan ICINDEKI modul serbest: Gold, zaman cizelgesini acabilir. */
    #[Test]
    public function a_published_invitation_can_enable_a_module_within_its_tier(): void
    {
        [$user, $invitation] = $this->publishedInvitation(SubscriptionTier::Gold);

        $this->withToken($this->tokenFor($user))
            ->putJson(route('invitations.update', $invitation), ['invitation' => ['showTimeline' => true]])
            ->assertOk()
            ->assertJsonPath('data.invitation.showTimeline', true);

        $this->assertDatabaseHas('invitations', ['id' => $invitation->id, 'show_timeline' => true]);
    }

    /**
     * 🔴 Modul KAPATMAK her zaman serbest — elde hicbir hak kalmamis olsa bile.
     *
     * Siparis iade edildi (hak yok), davetiye yayinda kaldi (acik karar 10.61).
     * Kural "sahip olunan plan son hali kapsiyor mu" olsaydi bu istek 402
     * alirdi ve kullanici iade edilmis galeriyi KAPATAMAZDI bile.
     */
    #[Test]
    public function disabling_a_module_needs_no_covering_order(): void
    {
        $user = User::factory()->create();
        $invitation = Invitation::factory()->published()->create([
            'user_id' => $user->id,
            'show_gallery' => true,
        ]);
        Order::factory()->refunded()->tier(SubscriptionTier::Elit)->forInvitation($invitation)->create();

        $this->withToken($this->tokenFor($user))
            ->putJson(route('invitations.update', $invitation), ['invitation' => ['showGallery' => false]])
            ->assertOk();

        $this->assertDatabaseHas('invitations', ['id' => $invitation->id, 'show_gallery' => false]);
    }

    /** Ayni istek, plan yukseltilince gecer: red kalici degil, hakka bagli. */
    #[Test]
    public function upgrading_the_order_lets_the_owner_enable_the_module(): void
    {
        [$user, $invitation] = $this->publishedInvitation(SubscriptionTier::Standart);
        $token = $this->tokenFor($user);
        $payload = ['invitation' => ['showGift' => true, 'bankName' => 'Ziraat Bankası']];

        $this->withToken($token)
            ->putJson(route('invitations.update', $invitation), $payload)
            ->assertStatus(402);

        Order::factory()->paid()->tier(SubscriptionTier::Elit)->forInvitation($invitation)->create();

        // T13: ikinci kimlikli istekten once guard sifirlanir.
        $this->forgetAuthState();

        $this->withToken($token)
            ->putJson(route('invitations.update', $invitation), $payload)
            ->assertOk();

        $this->assertDatabaseHas('invitations', [
            'id' => $invitation->id,
            'show_gift' => true,
            'bank_name' => 'Ziraat Bankası',
        ]);
    }

    /** Taslakta her sey serbest: K43'un ruhu, denemenin bedeli olmaz. */
    #[Test]
    public function a_draft_invitation_can_enable_any_module_without_an_order(): void
    {
        [$user, $invitation] = $this->ownedInvitation();

        $this->withToken($this->tokenFor($user))
            ->putJson(route('invitations.update', $invitation), ['invitation' => [
                'showGallery' => true,
                'showGift' => true,
            ]])
            ->assertOk();

        $this->assertDatabaseHas('invitations', [
            'id' => $invitation->id,
            'status' => InvitationStatus::Saved->value,
            'show_gallery' => true,
            'show_gift' => true,
        ]);
    }

    /**
     * 🔴 Kural bir FARKA bakar, son hale degil.
     *
     * Fiyat haritasi degisti: zaman cizelgesi artik Elit'e ait. Gold ile
     * yayinlanmis davetiye artik "plan disi" gorunur — ama sahibi yine de
     * metnini duzeltebilmeli. Bu istek gereksinimi YUKSELTMIYOR.
     */
    #[Test]
    public function a_published_invitation_stays_editable_when_the_price_map_changes(): void
    {
        [$user, $invitation] = $this->publishedInvitation(SubscriptionTier::Gold, ['show_timeline' => true]);

        Config::set('davetkart.module_tiers.show_timeline', SubscriptionTier::Elit->value);

        $this->withToken($this->tokenFor($user))
            ->putJson(route('invitations.update', $invitation), ['invitation' => ['venue' => 'Çırağan Sarayı, İstanbul']])
            ->assertOk();

        $this->assertDatabaseHas('invitations', [
            'id' => $invitation->id,
            'venue' => 'Çırağan Sarayı, İstanbul',
        ]);
    }

    /** Hic hak kalmamissa (iade) modul acmak "once bir plan al" der — publish ile ayni iki kod. */
    #[Test]
    public function enabling_a_module_after_a_refund_asks_for_a_purchase(): void
    {
        $user = User::factory()->create();
        $invitation = Invitation::factory()->published()->create(['user_id' => $user->id]);
        Order::factory()->refunded()->forInvitation($invitation)->create();

        $this->withToken($this->tokenFor($user))
            ->putJson(route('invitations.update', $invitation), ['invitation' => ['showTimeline' => true]])
            ->assertStatus(402)
            ->assertJsonPath('error.code', ErrorCode::PaymentRequired->value)
            ->assertJsonPath('error.params.requiredTier', SubscriptionTier::Gold->value);

        $this->assertDatabaseHas('invitations', ['id' => $invitation->id, 'show_timeline' => false]);
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

        // Faz 10 (10.49): beklenen tutar SABIT. Once `Elit->price() * 100`
        // yaziliyordu; `price()` 1 dondurse beklenen de 100 olur ve test
        // kendisiyle karsilastirilmis olurdu (TEST-DENETIMI §2).
        $this->assertDatabaseHas('orders', [
            'user_id' => $user->id,
            'tier' => SubscriptionTier::Elit->value,
            'status' => OrderStatus::Pending->value,
            'amount_minor' => 54900,
            'currency' => 'TRY',
        ]);
    }

    /**
     * Faz 10 (10.49): her planin fiyati, fiyat sayfasinda soylenen sayi.
     *
     * Kurus cinsinden, SABIT. Fiyat bir is karari (config); degistiginde bu
     * test BILEREK kirilir ve degisikligin bilincli oldugunu soyletir.
     */
    #[Test]
    #[DataProvider('publishedPrices')]
    public function each_tier_is_charged_its_published_price(string $tier, int $amountMinor): void
    {
        $user = User::factory()->create();

        $this->withToken($this->tokenFor($user))
            ->postJson(route('payments.checkout'), ['tier' => $tier])
            ->assertCreated();

        $order = Order::query()->where('user_id', $user->id)->sole();

        $this->assertSame($amountMinor, $order->amount_minor);
        $this->assertSame('TRY', $order->currency);
    }

    /** @return array<string, array{string, int}> */
    public static function publishedPrices(): array
    {
        return [
            'standart 249 TL' => ['standart', 24900],
            'gold 399 TL' => ['gold', 39900],
            'elit 549 TL' => ['elit', 54900],
        ];
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

        // Faz 10 (K99): 'account' degil, bagsiz tekil siparis.
        $this->assertDatabaseHas('orders', [
            'user_id' => $user->id,
            'invitation_id' => null,
            'scope' => OrderScope::Invitation->value,
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
        $logger = Log::spy();

        $this->signedWebhook(['providerRef' => 'ref-1', 'status' => 'paid'])
            ->assertNoContent();

        $order->refresh();

        $this->assertSame(OrderStatus::Paid, $order->status);
        $this->assertNotNull($order->paid_at);

        // Faz 10 (T6): zamaninda gelen odeme uyari URETMEZ. Uyari yalnizca
        // `expired -> paid` icin; her odemede yazilsaydi anlamini yitirirdi.
        $logger->shouldNotHaveReceived('warning');
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

    // ------------------------------------------ GEC GELEN ODEME (10.7 · K89)

    /**
     * 🔴 Rapor §1.2 · denetim K-4, UCTAN UCA.
     *
     * Odeme penceresi kapandi, zamanlayici siparisi `expired` yapti, SONRA
     * saglayicinin imzali `paid` bildirimi geldi. Faz 9'da bu bildirim 204
     * ile sessizce yutuluyordu. Kanit yanit degil, zincirin sonu (T14):
     * siparis odendi VE davetiye gercekten yayinlanabiliyor.
     */
    #[Test]
    public function a_paid_webhook_after_expiry_still_grants_the_order(): void
    {
        [$user, $invitation] = $this->ownedInvitation();

        $order = Order::factory()->forInvitation($invitation)->create([
            'provider_ref' => 'ref-gec',
            'expires_at' => now()->subMinute(),
        ]);

        // Zamanlayicinin saatlik kosusu: pencere kapandi.
        $this->assertSame(Command::SUCCESS, Artisan::call('orders:expire'));
        $this->assertSame(OrderStatus::Expired, $order->refresh()->status);

        $logger = Log::spy();

        $this->signedWebhook(['providerRef' => 'ref-gec', 'status' => 'paid'])->assertNoContent();

        $order->refresh();
        $this->assertSame(OrderStatus::Paid, $order->status);
        $this->assertNotNull($order->paid_at);

        // Kabul edildi ama iz birakti: cifte tahsilat olabilir (10.6).
        $logger->shouldHaveReceived('warning', [
            'Late payment accepted for an expired order',
            Mockery::on(fn (array $context): bool => ($context['order_id'] ?? null) === $order->id),
        ]);
        $logger->shouldNotHaveReceived('critical');

        $this->withToken($this->tokenFor($user))
            ->postJson(route('invitations.publish', $invitation))
            ->assertOk();

        $this->assertSame(InvitationStatus::Published, $invitation->refresh()->status);
    }

    /**
     * 🔴 `failed` final kalir (K89) — ama red artik SESSIZ DEGIL.
     *
     * Saglayici once "reddedildi", sonra "odendi" diyor. Celiski otomatik
     * cozulmez (siparis acilmaz), bir insani cagirir: Log::critical.
     * Baglam TAM olarak dogrulaniyor: log'a kisisel veri sizmasin (K14).
     */
    #[Test]
    public function a_paid_webhook_after_a_provider_failure_is_rejected_loudly(): void
    {
        $order = Order::factory()->failed()->create(['provider_ref' => 'ref-red']);

        $logger = Log::spy();

        $this->signedWebhook(['providerRef' => 'ref-red', 'status' => 'paid'])->assertNoContent();

        $order->refresh();
        $this->assertSame(OrderStatus::Failed, $order->status);
        $this->assertNull($order->paid_at);

        $logger->shouldHaveReceived('critical', [
            'Paid notification rejected: the order cannot become paid',
            Mockery::on(fn (array $context): bool => $context === [
                'order_id' => $order->id,
                'provider_ref' => 'ref-red',
                'status' => OrderStatus::Failed->value,
            ]),
        ]);
    }

    /** Webhook TEKRARI normal isleyistir: zaten odenmis siparise ikinci `paid` alarm uretmez. */
    #[Test]
    public function a_repeated_paid_webhook_is_not_reported_as_critical(): void
    {
        Order::factory()->paid()->create(['provider_ref' => 'ref-1']);

        $logger = Log::spy();

        $this->signedWebhook(['providerRef' => 'ref-1', 'status' => 'paid'])->assertNoContent();

        $logger->shouldNotHaveReceived('critical');
        $logger->shouldNotHaveReceived('warning');
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

    // -------------------------- SERBEST BIRAKMA VE YENIDEN BAGLAMA (9.7 / 9.8)

    /**
     * 🔴 Faz 9'un kapattigi delik, okuma tarafindan gorunusu.
     *
     * Serbest birakilmis siparis (scope='invitation' + invitation_id=NULL)
     * eskiden `whereNull('invitation_id')` koluna duser ve PAKET sanilirdi.
     * Artik hicbir davetiyeyi acmaz — once baglanmasi gerekir.
     */
    #[Test]
    public function a_released_order_grants_nothing_until_it_is_claimed(): void
    {
        $user = User::factory()->create();
        $invitation = Invitation::factory()->create(['user_id' => $user->id]);

        Order::factory()->paid()->tier(SubscriptionTier::Elit)->released()
            ->create(['user_id' => $user->id]);

        $this->assertNull($this->entitlements()->highestTierFor($invitation));
    }

    /** Pencere ACIK: yayindan 1 gun sonra silinirse hak geri alinir. */
    #[Test]
    public function deleting_within_the_window_releases_the_paid_order(): void
    {
        $invitation = Invitation::factory()->create([
            'status' => InvitationStatus::Published,
            'published_at' => now()->subDay(),
        ]);

        $order = Order::factory()->paid()->forInvitation($invitation)->create();

        app(DeleteInvitationAction::class)->handle($invitation);

        $order->refresh();

        $this->assertNull($order->invitation_id);

        // 🔴 Kapsam DEGISMEDI. Degisseydi siparis pakete donusur ve hesabin
        // tum davetiyelerini acardi — kapatilan delik geri gelirdi.
        $this->assertSame(OrderScope::Invitation, $order->scope);
    }

    /** Pencere KAPALI: yayindan 5 gun sonra silinirse hak yanar. */
    #[Test]
    public function deleting_after_the_window_burns_the_paid_order(): void
    {
        $invitation = Invitation::factory()->create([
            'status' => InvitationStatus::Published,
            'published_at' => now()->subDays(5),
        ]);

        $order = Order::factory()->paid()->forInvitation($invitation)->create();

        app(DeleteInvitationAction::class)->handle($invitation);

        $this->assertSame($invitation->id, $order->refresh()->invitation_id);
    }

    /** Hic yayinlanmamis davetiyede hak zaten harcanmamisti. */
    #[Test]
    public function deleting_an_unpublished_invitation_releases_the_paid_order(): void
    {
        $invitation = Invitation::factory()->create(['published_at' => null]);

        $order = Order::factory()->paid()->forInvitation($invitation)->create();

        app(DeleteInvitationAction::class)->handle($invitation);

        $this->assertNull($order->refresh()->invitation_id);
    }

    /**
     * Faz 10 (10.58 · K99): bir davetiyeye baglanmis paket, tekil siparis
     * gibi serbest kalir ve sonraki davetiyeyi yayinlar.
     */
    #[Test]
    public function a_claimed_package_is_released_like_any_single_order(): void
    {
        [$user, $other] = $this->ownedInvitation();
        $invitation = Invitation::factory()->published()->create(['user_id' => $user->id]);

        $package = Order::factory()->paid()->tier(SubscriptionTier::Elit)->package()
            ->create(['user_id' => $user->id, 'invitation_id' => $invitation->id]);

        app(DeleteInvitationAction::class)->handle($invitation);

        $this->assertNull($package->refresh()->invitation_id);

        $this->withToken($this->tokenFor($user))
            ->postJson(route('invitations.publish', $other))
            ->assertOk();

        $this->assertSame($other->id, $package->refresh()->invitation_id);
    }

    /** Uctan uca: serbest hak yeni bir davetiyede kullanilabiliyor mu? */
    #[Test]
    public function publishing_claims_a_released_order(): void
    {
        [$user, $invitation] = $this->ownedInvitation(['show_gallery' => true]);

        $order = Order::factory()->paid()->tier(SubscriptionTier::Elit)->released()
            ->create(['user_id' => $user->id]);

        $this->withToken($this->tokenFor($user))
            ->postJson(route('invitations.publish', $invitation))
            ->assertOk();

        $this->assertSame($invitation->id, $order->refresh()->invitation_id);
    }

    /**
     * 🔴 BU FAZIN EN ONEMLI TESTI.
     *
     * Serbest birakilan hak TEK BIR davetiye acar. Delik tam olarak buydu:
     * eski sorguda ayni siparis paket sayilir ve hesabin butun davetiyelerini
     * SINIRSIZ acardi.
     */
    #[Test]
    public function a_released_order_can_only_publish_one_invitation(): void
    {
        $user = User::factory()->create();
        $first = Invitation::factory()->create(['user_id' => $user->id]);
        $second = Invitation::factory()->create(['user_id' => $user->id]);

        Order::factory()->paid()->tier(SubscriptionTier::Standart)->released()
            ->create(['user_id' => $user->id]);

        $token = $this->tokenFor($user);

        $this->withToken($token)
            ->postJson(route('invitations.publish', $first))
            ->assertOk();

        // T13: ikinci kimlikli istekten once guard sifirlanir.
        $this->forgetAuthState();

        $this->withToken($token)
            ->postJson(route('invitations.publish', $second))
            ->assertStatus(402)
            ->assertJsonPath('error.code', ErrorCode::PaymentRequired->value);

        $this->assertDatabaseHas('invitations', [
            'id' => $second->id,
            'status' => InvitationStatus::Saved->value,
        ]);
    }

    /**
     * Eldeki hak yetiyorsa serbest siparis HARCANMAZ.
     *
     * Ters sirada yazilsaydi (once bagla, sonra sor) davetiyesi icin zaten
     * odemis kullanici her yayinda bagsiz bir siparisini (paketini) de
     * yakardi. Faz 10 (K99): eldeki hak artik yalnizca davetiyeye bagli
     * siparisten gelebilir.
     */
    #[Test]
    public function publishing_does_not_claim_when_a_bound_order_already_covers_it(): void
    {
        [$user, $invitation] = $this->ownedInvitation();

        Order::factory()->paid()->tier(SubscriptionTier::Elit)
            ->forInvitation($invitation)->create();

        $released = Order::factory()->paid()->tier(SubscriptionTier::Standart)->package()
            ->create(['user_id' => $user->id]);

        $this->withToken($this->tokenFor($user))
            ->postJson(route('invitations.publish', $invitation))
            ->assertOk();

        $this->assertNull($released->refresh()->invitation_id);
    }

    /** Yetmeyen bir serbest siparis baglanmaz — ve harcanmis da olmaz. */
    #[Test]
    public function a_released_order_that_is_too_cheap_is_not_claimed(): void
    {
        [$user, $invitation] = $this->ownedInvitation(['show_gallery' => true]);

        $released = Order::factory()->paid()->tier(SubscriptionTier::Standart)->released()
            ->create(['user_id' => $user->id]);

        $this->withToken($this->tokenFor($user))
            ->postJson(route('invitations.publish', $invitation))
            ->assertStatus(402)
            ->assertJsonPath('error.code', ErrorCode::PaymentRequired->value)
            ->assertJsonPath('error.params.requiredTier', SubscriptionTier::Elit->value);

        $this->assertNull($released->refresh()->invitation_id);
    }

    /** 🔴 Tuketimde dogru refleks TERSTIR: en yuksek degil, YETEN EN DUSUK. */
    #[Test]
    public function the_cheapest_covering_released_order_is_claimed(): void
    {
        [$user, $invitation] = $this->ownedInvitation();

        $standart = Order::factory()->paid()->tier(SubscriptionTier::Standart)->released()
            ->create(['user_id' => $user->id]);
        $elit = Order::factory()->paid()->tier(SubscriptionTier::Elit)->released()
            ->create(['user_id' => $user->id]);

        $this->withToken($this->tokenFor($user))
            ->postJson(route('invitations.publish', $invitation))
            ->assertOk();

        $this->assertSame($invitation->id, $standart->refresh()->invitation_id);
        $this->assertNull($elit->refresh()->invitation_id);
    }

    /** IDOR'un serbest siparisteki hali: baskasinin hakki baglanmaz. */
    #[Test]
    public function another_users_released_order_is_never_claimed(): void
    {
        [$user, $invitation] = $this->ownedInvitation();

        $foreign = Order::factory()->paid()->tier(SubscriptionTier::Elit)->released()->create();

        $this->withToken($this->tokenFor($user))
            ->postJson(route('invitations.publish', $invitation))
            ->assertStatus(402);

        $this->assertNull($foreign->refresh()->invitation_id);
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
     * Yayinda bir davetiye ve onu yayinlatan odenmis TEKIL siparis.
     *
     * @param  array<string, mixed>  $overrides
     *
     * @return array{0: User, 1: Invitation}
     */
    private function publishedInvitation(SubscriptionTier $paidTier, array $overrides = []): array
    {
        $user = User::factory()->create();

        /** @var array<string, mixed> $attributes */
        $attributes = array_merge(['user_id' => $user->id], $overrides);

        $invitation = Invitation::factory()->published()->create($attributes);

        Order::factory()->paid()->tier($paidTier)->forInvitation($invitation)->create();

        return [$user, $invitation];
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
