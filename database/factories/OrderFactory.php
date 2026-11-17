<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Actions\Pricing\Promotion;
use App\Actions\Pricing\QuotePriceAction;
use App\Enums\OrderScope;
use App\Enums\OrderStatus;
use App\Enums\PromotionKind;
use App\Enums\SubscriptionTier;
use App\Models\Invitation;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use LogicException;

/**
 * Test ve seeder icin sahte siparis uretir.
 *
 * 🔴 Rastgelelik yalnizca DAVRANISI ETKILEMEYEN alanda (provider_ref).
 * Plan, durum, tutar ve sahiplik sabittir — yoksa paywall testleri tesadufe
 * baglanirdi (InvitationFactory'nin ayni ilkesi).
 *
 * 🔴 Tutar HER ZAMAN plandan turetilir, elle yazilmaz. Fabrika ile uretim
 * kodu ayni kaynaga bakmazsa, testler gercekte olmayan bir fiyat uzerinden
 * yesil yanardi (B4'un fabrika hali).
 * Ayrintili aciklama: docs/rehber/database/factories/OrderFactory.md
 *
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    /**
     * Varsayilan: PAKET alimi (invitation_id = NULL), odenmemis.
     *
     * Neden odenmemis? Bir fabrikanin varsayilani, testin ACIKCA istemesi
     * gereken seyi bedava vermemelidir. `paid()` yazilmadan yayin hakki
     * dogsaydi, paywall testleri "sizinti" testine donerdi.
     *
     * Donus tipi BILEREK yazilmadi — ust siniftan devralinir (ders 19).
     */
    public function definition(): array
    {
        $tier = SubscriptionTier::Standart;

        return [
            'user_id' => User::factory(),
            'invitation_id' => null,

            // Varsayilan: bagsiz tekil siparis (fiyat sayfasindan alinan paket,
            // K99). Ilk yayinda bir davetiyeye baglanir.
            'scope' => OrderScope::Invitation,

            'tier' => $tier,
            'status' => OrderStatus::default(),

            'amount_minor' => self::minorFor($tier),
            'currency' => Config::string('davetkart.currency'),

            'provider' => Config::string('payment.default'),

            // Benzersiz olmak ZORUNDA: kolon UNIQUE. Str::ulid() carpismayi
            // pratikte imkansiz kilar ve fabrikanin dongu icinde cagrilmasini
            // guvenli hale getirir.
            'provider_ref' => 'test_'.Str::ulid()->toBase32(),

            'paid_at' => null,
            'expires_at' => now()->addMinutes(Config::integer('payment.order_expires_after_minutes')),
        ];
    }

    /**
     * Odenmis siparis.
     *
     * 🔴 `status` ve `paid_at` BIRLIKTE degisir. Ayri ayri verilebilseydi
     * orders_paid_at_check kisiti fabrikayi patlatirdi — kisit burada bir
     * engel degil, bir OGRETMEN: iki kolonun tek bir olguyu anlattigini
     * fabrika da bilmek zorunda (InvitationFactory::published() ile ayni
     * desen).
     */
    public function paid(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => OrderStatus::Paid,
            'paid_at' => now(),
        ]);
    }

    /**
     * Saglayici reddetti ya da odeme hic baslatilamadi.
     *
     * Suresi dolan siparis `failed` DEGIL `expired`'dir (Faz 10, K89) ve onun
     * durumu burada degil, orders:expire komutunun kendisiyle uretilir.
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => OrderStatus::Failed,
            'paid_at' => null,
        ]);
    }

    /** Iade edilmis siparis — parasi alinmisti, hakki geri alindi. */
    public function refunded(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => OrderStatus::Refunded,
            'paid_at' => now()->subDay(),
        ]);
    }

    /** Plan degisince TUTAR da degisir — ikisi tek kaynaktan gelir. */
    public function tier(SubscriptionTier $tier): static
    {
        return $this->state(fn (array $attributes): array => [
            'tier' => $tier,
            'amount_minor' => self::minorFor($tier),
        ]);
    }

    /**
     * İlk yayın indirimiyle açılmış sipariş (Faz 18.3, K158).
     *
     * 🔴 Dört kolon BİRLİKTE değişir (orders_promotion_* kısıtları): kaynak,
     * otomatik indirim, toplam indirim, ödenecek tutar. Oran üretim koduyla
     * aynı yerden okunur (B4). tier()'dan SONRA çağrılır.
     */
    public function firstPublish(): static
    {
        return $this->state(function (array $attributes): array {
            $list = $attributes['amount_minor'] ?? null;

            if (! is_int($list)) {
                throw new LogicException('firstPublish() needs the list price in amount_minor.');
            }

            $discount = Promotion::firstPublish(QuotePriceAction::firstPublishPercent())->discountFor($list);

            // Oran kapalıyken (0) satır orders_promotion_source_check'e çarpardı; neden burada söylenir.
            if ($discount <= 0) {
                throw new LogicException('firstPublish() needs davetkart.pricing.first_publish_percent above 0.');
            }

            return [
                'amount_minor' => $list - $discount,
                'discount_minor' => $discount,
                'promotion' => PromotionKind::FirstPublish,
                'promotion_discount_minor' => $discount,
            ];
        });
    }

    /**
     * TEKIL alim: yalnizca bu davetiyeyi acar (K42).
     *
     * Sahiplik de birlikte tasinir: davetiyenin sahibi olmayan bir kullaniciya
     * bagli siparis, uretim kodunda hicbir zaman olusmaz — fabrikanin bunu
     * uretebilmesi testleri gercekte olmayan bir duruma dayandirirdi.
     */
    public function forInvitation(Invitation $invitation): static
    {
        return $this->state(fn (array $attributes): array => [
            'invitation_id' => $invitation->id,
            'user_id' => $invitation->user_id,

            // Kapsam da tasinir: davetiyeye bagli bir siparis TEKIL alimdir.
            // Yazilmasaydi fabrika 'account' + dolu invitation_id uretir ve
            // CHECK kisiti her testi patlatirdi.
            'scope' => OrderScope::Invitation,
        ]);
    }

    /**
     * SERBEST BIRAKILMIS tekil siparis (Faz 9).
     *
     * 🔴 Kapsam 'invitation' KALIR, yalnizca bag kopar. Bu ucuncu kombinasyon
     * (scope='invitation' + invitation_id=NULL) Faz 9'un kapattigi deligin ta
     * kendisidir: eski sorgu onu PAKET sanip hesap geneline hak veriyordu.
     *
     * forInvitation()'dan SONRA cagrilmali degil — tek basina kullanilir:
     *   Order::factory()->paid()->released()->create(['user_id' => $u->id])
     */
    public function released(): static
    {
        return $this->state(fn (array $attributes): array => [
            'invitation_id' => null,
            'scope' => OrderScope::Invitation,
        ]);
    }

    /**
     * PAKET alim: davetiyesiz, fiyat sayfasindan (K99). Varsayilan zaten budur.
     *
     * Faz 10'dan once hesabin tamamini aciyordu; artik released() ile ayni
     * bicim: ilk yayinda bir davetiyeye baglanir.
     */
    public function package(): static
    {
        return $this->state(fn (array $attributes): array => [
            'invitation_id' => null,
            'scope' => OrderScope::Invitation,
        ]);
    }

    /** Plan fiyatini (TL) en kucuk birime (kurus) cevirir. */
    private static function minorFor(SubscriptionTier $tier): int
    {
        return $tier->price() * 100;
    }
}
