<?php

declare(strict_types=1);

namespace App\Actions\Payment;

use App\Actions\GiftCode\GiftCodeQuote;
use App\Actions\GiftCode\ReserveGiftCodeAction;
use App\Actions\Pricing\PriceQuote;
use App\Actions\Pricing\QuotePriceAction;
use App\Enums\OrderScope;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\SubscriptionTier;
use App\Events\InvitationChanged;
use App\Exceptions\GiftCodeException;
use App\Exceptions\PaymentMethodException;
use App\Exceptions\PaymentProviderException;
use App\Exceptions\PaywallViolationException;
use App\Models\GiftCode;
use App\Models\GiftCodeRedemption;
use App\Models\Invitation;
use App\Models\Order;
use App\Models\User;
use App\Services\Payment\PaymentGateway;
use App\Services\Payment\PaymentGatewayResolver;
use App\Services\Payment\PaymentMethods;
use App\Services\Payment\RedirectAction;
use App\Services\Payment\RequiresBuyerPhone;
use App\Services\Pricing\TierResolver;
use App\Support\GiftCodes\GiftCodeFormat;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Throwable;

/**
 * Bir plan icin odeme baslatir: siparis satirini yazar, saglayicida oturum acar.
 * Ayrintili aciklama: docs/rehber/app/Actions/Payment/StartCheckoutAction.md §A-E5-42
 */
final class StartCheckoutAction
{
    public function __construct(
        private readonly PaymentGatewayResolver $gateways,
        private readonly TierResolver $tiers,
        private readonly ReserveGiftCodeAction $reserveGiftCode,
        private readonly QuotePriceAction $quotePrice,
        private readonly PaymentMethods $methods,
    ) {}

    /**
     * @param  User  $user  Odemeyi yapan — davetiyenin sahibi (Gate dogruladi)
     * @param  Invitation|null  $invitation  null = PAKET alimi (K42)
     * @param  SubscriptionTier  $tier  Istemcinin sectigi plan — DOGRULANIR
     * @param  string|null  $giftCode  Kullanicinin yazdigi hediye kodu (Faz 11)
     * @param  string|null  $phone  Normallestirilmis TR cep telefonu (K262); surucu isterse zorunlu
     *
     * @throws PaywallViolationException Secilen plan davetiyeyi kapsamiyor -> 402
     * @throws GiftCodeException Kod kullanilamiyor -> 422
     * @throws PaymentMethodException Yontem kapali -> 403 (Faz 13)
     * @throws PaymentProviderException Saglayici oturumu acamadi -> 502
     */
    public function handle(
        User $user,
        ?Invitation $invitation,
        SubscriptionTier $tier,
        ?string $giftCode = null,
        ?string $phone = null,
    ): CheckoutResult {
        // K276 (Faz 22.1): e-posta doğrulaması ödemeyi beklemez; yalnızca ilk yayın indirimi ister.

        // 3. KATMAN — yeterlilik: secilen plan davetiyenin modullerini kapsamali (paket aliminda yayin aninda sinanir).
        // Ayrintili aciklama: docs/rehber/app/Actions/Payment/StartCheckoutAction.md §A-E5-103
        if ($invitation !== null) {
            $required = $this->tiers->requiredFor($invitation);

            if (! $tier->covers($required)) {
                throw PaywallViolationException::insufficientTier($required, $tier);
            }
        }

        // Faz 13 (K134): kod kapaliysa kodu hic aramayiz — rezervasyon kilidi
        // bile alinmaz.
        if ($giftCode !== null) {
            $this->methods->ensureEnabled(PaymentMethod::GiftCode);
        }

        // Faz 11: rezervasyon + siparis + kullanim satiri TEK transaction.
        // Ayrintili aciklama: docs/rehber/app/Actions/Payment/StartCheckoutAction.md §A-E5-125
        [$order, $gateway, $reused] = DB::transaction(function () use ($user, $invitation, $tier, $giftCode, $phone): array {
            // 🔴 Faz 18.3 (K158, 1. katman): aynı kullanıcının checkout'ları SIRAYA
            // girer. Aşağıdaki her karar (uygunluk, yeniden kullanma) bu kilidin
            // altında verilir; kilitsiz iki istek aynı "indirim boşta" cevabını okurdu (E9).
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

            // K161: penceresi dolmuş satır orders:expire'ı beklemez; beklerse
            // kısmi UNIQUE index yeni indirimli siparişi bloklar.
            $this->expireStaleOrders($user);

            $open = $this->openOrdersFor($user, $invitation);

            // 4. KATMAN: fiyat SUNUCUDA, tek hesaptan (K129) ve BU hedef için (K159).
            $price = $this->quotePrice->handle($user, $tier, $invitation?->id);

            // K160: aynı niyet -> aynı sipariş. Yenilenen sayfa yeni satır açmaz.
            $existing = $this->sameIntent($open, $price, $giftCode);

            if ($existing !== null) {
                $this->methods->ensureEnabled(PaymentMethod::Card);
                $gateway = $this->gateways->resolve();

                // Sağlayıcı arada değiştiyse eski siparişin oturumu yeniden açılamaz.
                if ($existing->provider === $gateway->name()) {
                    // K262: telefon sonradan düzeltilmiş olabilir; yeniden açılan oturum yenisini taşır.
                    $existing->buyer_phone = $this->buyerPhoneFor($gateway, $phone);
                    $existing->save();

                    // Sürücü alıcıyı ilişkiden okur; elimizdeki kullanıcı o.
                    $existing->setRelation('user', $user);

                    return [$existing, $gateway, true];
                }
            }

            // K161: farklı niyet (plan, kod, fiyat) hedefin eski siparişini kapatır.
            // Kod rezervasyonundan ÖNCE: eski siparişin tuttuğu kod serbest kalsın.
            $this->supersede($open);

            $giftQuote = $giftCode === null
                ? null
                : $this->reserveGiftCode->handle($user, $giftCode, $tier, $price->afterPromotionMinor());

            if ($giftQuote !== null) {
                $price = $price->withGiftCode($giftQuote);
            }

            $gateway = null;

            if ($price->coversEverything()) {
                $order = $this->createGiftOrder($user, $invitation, $price);
            } else {
                // 🔴 K134: odenecek bir tutar var ve onu yalnizca kart odeyebilir.
                // Havale bu uctan gecmez (siparis acmaz). Exception transaction'i
                // geri alir: ayrilan kod serbest kalir.
                $this->methods->ensureEnabled(PaymentMethod::Card);

                $gateway = $this->gateways->resolve();
                $order = $this->createPendingOrder($user, $invitation, $price, $gateway, $this->buyerPhoneFor($gateway, $phone));
            }

            if ($giftQuote !== null) {
                $this->recordRedemption($order, $user, $giftQuote);
            }

            return [$order, $gateway, false];
        });

        // D1: hediye siparisi zaten odenmis; saglayici YOK (hic kurulmadi).
        if ($gateway === null) {
            return new CheckoutResult($order, new RedirectAction($this->successUrlFor($order)));
        }

        // 🔴 F3'un dis servis hali: SAGLAYICI DA TRANSACTION'A DAHIL DEGILDIR.
        // Ayrintili aciklama: docs/rehber/app/Actions/Payment/StartCheckoutAction.md §A-E5-202
        try {
            $session = $gateway->startCheckout($order);
        } catch (Throwable $e) {
            // TELAFI: siparis silinmez, 'failed' isaretlenir; yalnizca BU istegin actigi satir (K160).
            // Ayrintili aciklama: docs/rehber/app/Actions/Payment/StartCheckoutAction.md §A-E5-209
            if (! $reused) {
                $order->status = OrderStatus::Failed;
                $order->save();
            }

            // H8: saglayicinin HAM hatasi yanita GIRMEZ, log'a gider.
            // Hediye kodu kullanimi varsa 'failed' ile kendiliginden iade olur (D4).
            Log::error('Payment checkout failed', [
                'order_id' => $order->getKey(),
                'provider' => $gateway->name(),
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            throw PaymentProviderException::rejected($e);
        }

        // Saglayicinin verdigi kimlik ve pencere satira isleniyor.
        // Ayrintili aciklama: docs/rehber/app/Actions/Payment/StartCheckoutAction.md §A-E5-233
        if ($order->provider_ref === null) {
            $order->provider_ref = $session->providerRef;

            if ($session->expiresAt !== null) {
                $order->expires_at = $session->expiresAt;
            }

            $order->save();
        }

        // Faz 12 (K118): adres ya da imzali form; Action hangisi oldugunu bilmez.
        return new CheckoutResult($order, $session->action);
    }

    /**
     * Bu HEDEF için ödeme bekleyen siparişler; en yenisi başta (Faz 18.3).
     * Hedef: davetiye ya da davetiyesiz paket (`invitation_id IS NULL`).
     *
     * @return Collection<int, Order>
     */
    private function openOrdersFor(User $user, ?Invitation $invitation): Collection
    {
        $query = Order::query()
            ->where('user_id', $user->id)
            ->awaitingPayment()
            // Niyet karşılaştırması kodu da okur; lazy loading kapalı (N+1 kuralı).
            ->with('giftCodeRedemption');

        if ($invitation === null) {
            $query->whereNull('invitation_id');
        } else {
            $query->where('invitation_id', $invitation->id);
        }

        return $query->latest()->get();
    }

    /**
     * Aynı NİYETİ taşıyan açık sipariş (K160): aynı plan, aynı otomatik fiyat,
     * aynı hediye kodu. Fiyat da sınanır: arada kampanya açıldıysa eski tutarı
     * geri vermek, ekrandaki fiyattan farklı bir tutar tahsil etmek olurdu.
     *
     * @param  Collection<int, Order>  $open
     */
    private function sameIntent(Collection $open, PriceQuote $price, ?string $giftCode): ?Order
    {
        if ($open->isEmpty()) {
            return null;
        }

        // Yalnızca KİMLİK karşılaştırması: kilit yok, kural yok (onlar rezervasyonda).
        $giftCodeId = $giftCode === null
            ? null
            : GiftCode::query()->where('code_hash', GiftCodeFormat::hash($giftCode))->value('id');

        // Kod yazıldı ama öyle bir kod yok: eşleşecek sipariş de yok; rezervasyon 422 verir.
        if ($giftCode !== null && $giftCodeId === null) {
            return null;
        }

        // Fiyatta TUTAR karşılaştırılır (liste + otomatik indirim), indirimin adı değil:
        // tutar aynıysa ekrandaki fiyat ile tahsil edilen fiyat da aynıdır.
        return $open->first(
            static fn (Order $order): bool => $order->tier === $price->tier
                && $order->listPriceMinor() === $price->listMinor
                && $order->promotion_discount_minor === $price->promotionDiscountMinor()
                && $order->giftCodeRedemption?->gift_code_id === $giftCodeId,
        );
    }

    /**
     * Hedefin önceki açık siparişlerini kapatır (K161).
     * Ayrintili aciklama: docs/rehber/app/Actions/Payment/StartCheckoutAction.md §A-E5-310
     *
     * @param  Collection<int, Order>  $open
     */
    private function supersede(Collection $open): void
    {
        if ($open->isEmpty()) {
            return;
        }

        $this->markExpired(Order::query()->whereKey($open->modelKeys()));

        Log::info('Pending orders closed by a new checkout for the same target', [
            'order_ids' => $open->modelKeys(),
        ]);
    }

    /** Penceresi dolmuş ama hâlâ `pending` duran satırlar: orders:expire'ın işi, bu kullanıcı için öne çekilir. */
    private function expireStaleOrders(User $user): void
    {
        $this->markExpired(
            Order::query()
                ->where('user_id', $user->id)
                ->whereNotNull('expires_at')
                ->where('expires_at', '<', now()),
        );
    }

    /**
     * `pending` koşulu UPDATE'in KENDİ içinde (E2): bu arada ödenmiş bir satır
     * hiçbir şekilde `expired` olamaz (ExpireStaleOrders ile aynı desen).
     *
     * @param  Builder<Order>  $orders
     */
    private function markExpired(Builder $orders): void
    {
        $orders->where('status', OrderStatus::Pending->value)->update([
            'status' => OrderStatus::Expired->value,
            'updated_at' => now(),
        ]);
    }

    /**
     * Odenmemis siparis satirini yazar.
     *
     * 🔴 Her alan ACIKCA atanir — Order'in #[Fillable] listesi BOS (E7 ailesi).
     * Toplu atama acik olsaydi `{"status":"paid"}` govdesi odemeyi atlardi.
     */
    private function createPendingOrder(
        User $user,
        ?Invitation $invitation,
        PriceQuote $price,
        PaymentGateway $gateway,
        ?string $buyerPhone,
    ): Order {
        $order = new Order;

        $order->user_id = $user->id;
        $order->invitation_id = $invitation?->getKey();

        // 🔴 KAPSAM SATIN ALMA ANINDA YAZILIR, okuma aninda turetilmez.
        // Ayrintili aciklama: docs/rehber/app/Actions/Payment/StartCheckoutAction.md §A-E5-375
        $order->scope = OrderScope::Invitation;

        $order->tier = $price->tier;
        $order->status = OrderStatus::default();

        // 🔴 4. KATMAN: fiyat SUNUCUDAN okunur (QuotePriceAction, K129), istek govdesinden asla.
        // Ayrintili aciklama: docs/rehber/app/Actions/Payment/StartCheckoutAction.md §A-E5-393
        $this->applyPrice($order, $price);

        // F4: surucu KENDI adini soyler; config ile kolon asla ayrisamaz.
        $order->provider = $gateway->name();
        $order->buyer_phone = $buyerPhone;

        $order->expires_at = now()->addMinutes(
            Config::integer('payment.order_expires_after_minutes'),
        );

        $order->save();

        return $order;
    }

    /**
     * Hediye kodunun tamamen karsiladigi siparis: dogrudan ODENMIS yazilir (D1).
     *
     * Yayin hakki, bagsiz siparisin baglanmasi ve iade akisi bu satiri normal
     * bir odenmis siparis olarak tanir; hicbir paywall noktasi degismez.
     */
    private function createGiftOrder(
        User $user,
        ?Invitation $invitation,
        PriceQuote $price,
    ): Order {
        $order = new Order;

        $order->user_id = $user->id;
        $order->invitation_id = $invitation?->getKey();
        $order->scope = OrderScope::Invitation;
        $order->tier = $price->tier;

        // orders_paid_at_check: odenmis siparis damga TASIR.
        $order->status = OrderStatus::Paid;
        $order->paid_at = now();

        // orders_gift_amount_check: provider='gift' <=> amount_minor=0.
        $this->applyPrice($order, $price);
        $order->provider = Order::GIFT_PROVIDER;

        // provider_ref UNIQUE; saglayici olmadigi icin kendimiz uretiriz.
        $order->provider_ref = 'gift_'.Str::ulid()->toBase32();

        // Odeme penceresi yok: orders:expire bu satira hic bakmaz.
        $order->expires_at = null;

        $order->save();

        // K102 kalibi: hak degisti, misafir sayfasinin onbellegi commit'ten sonra duser.
        if ($invitation !== null) {
            event(new InvitationChanged($invitation));
        }

        return $order;
    }

    /**
     * Fiyat dokumunu siparise yazar (Faz 13, K133).
     * Ayrintili aciklama: docs/rehber/app/Actions/Payment/StartCheckoutAction.md §A-E5-457
     */
    private function applyPrice(Order $order, PriceQuote $price): void
    {
        $order->amount_minor = $price->totalMinor();
        $order->discount_minor = $price->discountMinor();
        $order->promotion = $price->promotion?->kind;
        $order->promotion_discount_minor = $price->promotionDiscountMinor();
        $order->price_campaign_id = $price->promotion?->campaign?->id;
        $order->currency = Config::string('davetkart.currency');
    }

    /** Kullanim satiri: indirim o anki degeriyle sabitlenir; kod ipucu disinda log yok. */
    private function recordRedemption(Order $order, User $user, GiftCodeQuote $quote): void
    {
        $redemption = new GiftCodeRedemption;

        $redemption->gift_code_id = $quote->giftCode->id;
        $redemption->user_id = $user->id;
        $redemption->order_id = $order->id;
        $redemption->discount_minor = $quote->discountMinor;

        $redemption->save();

        // Duz kod log'a GIRMEZ (D3); yalnizca kimlik ve ipucu.
        Log::info('Gift code redeemed', [
            'gift_code_id' => $quote->giftCode->id,
            'code_hint' => $quote->giftCode->code_hint,
            'order_id' => $order->id,
            'gift_order' => $order->isGift(),
        ]);
    }

    /** K262 (A-E5): surucu isterse telefon zorunlu (422 `phone` `required`); istemezse saklanmaz (en az veri). */
    private function buyerPhoneFor(PaymentGateway $gateway, ?string $phone): ?string
    {
        if (! $gateway instanceof RequiresBuyerPhone) {
            return null;
        }

        Validator::make(['phone' => $phone], ['phone' => ['required']])->validate();

        return $phone;
    }

    /** FakeGateway'in basari adresiyle ayni bicim: odeme donus sayfasi siparisi yoklar. */
    private function successUrlFor(Order $order): string
    {
        return Config::string('payment.return_urls.success').'?order='.$order->getKey();
    }
}
