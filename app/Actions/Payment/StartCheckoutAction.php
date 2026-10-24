<?php

declare(strict_types=1);

namespace App\Actions\Payment;

use App\Actions\GiftCode\GiftCodeQuote;
use App\Actions\GiftCode\ReserveGiftCodeAction;
use App\Enums\OrderScope;
use App\Enums\OrderStatus;
use App\Enums\SubscriptionTier;
use App\Events\InvitationChanged;
use App\Exceptions\GiftCodeException;
use App\Exceptions\PaymentProviderException;
use App\Exceptions\PaywallViolationException;
use App\Models\GiftCodeRedemption;
use App\Models\Invitation;
use App\Models\Order;
use App\Models\User;
use App\Services\Payment\PaymentGateway;
use App\Services\Payment\RedirectAction;
use App\Services\Pricing\TierResolver;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Bir plan icin odeme baslatir: siparis satirini yazar, saglayicida oturum acar.
 *
 * 🔴 Katmanli savunma — ama buradaki tehdit "bot" degil, ISTEMCIYE GUVENMEK:
 *
 *   1. Yetki       -> rota + Gate::authorize('publish') (controller)
 *   2. Bicim       -> StoreCheckoutRequest ('in:' plan degerleri)
 *   3. Yeterlilik  -> secilen plan davetiyenin modullerini KAPSIYOR mu
 *   4. FIYAT       -> govdeden DEGIL, sunucudaki config'ten
 *   5. Telafi      -> saglayici patlarsa siparis 'failed' isaretlenir (F3)
 *
 * Faz 11: istege bagli hediye kodu. Kod rezervasyonu + siparis + kullanim
 * satiri TEK transaction'da; tutar 0 ise saglayiciya hic gidilmez (D1).
 *
 * 4. katman bu fazin en kisa ve en pahali satiridir: fiyat istekten okunsaydi
 * `{"tier":"elit","price":1}` govdesi Elit plani 1 kurusa satardi.
 * Ayrintili aciklama: docs/rehber/app/Actions/Payment/StartCheckoutAction.md
 */
final class StartCheckoutAction
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly TierResolver $tiers,
        private readonly ReserveGiftCodeAction $reserveGiftCode,
    ) {}

    /**
     * @param  User  $user  Odemeyi yapan — davetiyenin sahibi (Gate dogruladi)
     * @param  Invitation|null  $invitation  null = PAKET alimi (K42)
     * @param  SubscriptionTier  $tier  Istemcinin sectigi plan — DOGRULANIR
     * @param  string|null  $giftCode  Kullanicinin yazdigi hediye kodu (Faz 11)
     *
     * @throws PaywallViolationException Secilen plan davetiyeyi kapsamiyor -> 402
     * @throws GiftCodeException Kod kullanilamiyor -> 422
     * @throws PaymentProviderException Saglayici oturumu acamadi -> 502
     */
    public function handle(
        User $user,
        ?Invitation $invitation,
        SubscriptionTier $tier,
        ?string $giftCode = null,
    ): CheckoutResult {
        // 3. KATMAN — yeterlilik. Kullanicinin daha UCUZ bir plan secip
        // galerili bir davetiye yayinlamasini burada engelliyoruz; yayin
        // aninda da tekrar bakilacak (7.12), cunku modul davetiyeye ODEME
        // SONRASINDA da eklenebilir.
        //
        // Paket aliminda ($invitation === null) kontrol edilecek bir davetiye
        // yok: paket, hesaptaki HERHANGI bir davetiye icin alinabilir ve
        // yeterlilik yayin aninda sinanir.
        if ($invitation !== null) {
            $required = $this->tiers->requiredFor($invitation);

            if (! $tier->covers($required)) {
                throw PaywallViolationException::insufficientTier($required, $tier);
            }
        }

        // Faz 11: rezervasyon + siparis + kullanim satiri TEK transaction.
        // Kod satirinin kilidi kullanim satiri yazilana kadar tutulur (E9).
        $order = DB::transaction(function () use ($user, $invitation, $tier, $giftCode): Order {
            $quote = $giftCode === null
                ? null
                : $this->reserveGiftCode->handle($user, $giftCode, $tier);

            $order = $quote !== null && $quote->coversEverything()
                ? $this->createGiftOrder($user, $invitation, $tier, $quote)
                : $this->createPendingOrder($user, $invitation, $tier, $quote);

            if ($quote !== null) {
                $this->recordRedemption($order, $user, $quote);
            }

            return $order;
        });

        // D1: hediye siparisi zaten odenmis; saglayici YOK.
        if ($order->isGift()) {
            return new CheckoutResult($order, new RedirectAction($this->successUrlFor($order)));
        }

        // 🔴 F3'un dis servis hali: SAGLAYICI DA TRANSACTION'A DAHIL DEGILDIR.
        // Satir once yaziliyor, oturum sonra aciliyor. Ters sirada saglayici
        // oturumu acildiktan sonra veritabani yazimi patlarsa ODENMIS AMA
        // KAYDI OLMAYAN bir odeme kalirdi — geri alinamayan is EN SONA.
        try {
            $session = $this->gateway->startCheckout($order);
        } catch (Throwable $e) {
            // TELAFI (compensating transaction). Siparis silinmiyor,
            // 'failed' isaretleniyor: silmek denemenin izini de silerdi ve
            // "neden odeyemiyorum" sorusu cevapsiz kalirdi.
            $order->status = OrderStatus::Failed;
            $order->save();

            // H8: saglayicinin HAM hatasi yanita GIRMEZ, log'a gider.
            // Hediye kodu kullanimi varsa 'failed' ile kendiliginden iade olur (D4).
            Log::error('Payment checkout failed', [
                'order_id' => $order->getKey(),
                'provider' => $this->gateway->name(),
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            throw PaymentProviderException::rejected($e);
        }

        // Saglayicinin verdigi kimlik ve pencere satira isleniyor.
        // provider_ref UNIQUE: ayni referansi tasiyan ikinci bir siparis
        // veritabani seviyesinde imkansiz (idempotansin yarisi, 7.2).
        $order->provider_ref = $session->providerRef;

        if ($session->expiresAt !== null) {
            $order->expires_at = $session->expiresAt;
        }

        $order->save();

        // Faz 12 (K118): adres ya da imzali form; Action hangisi oldugunu bilmez.
        return new CheckoutResult($order, $session->action);
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
        SubscriptionTier $tier,
        ?GiftCodeQuote $quote,
    ): Order {
        $order = new Order;

        $order->user_id = $user->id;
        $order->invitation_id = $invitation?->getKey();

        // 🔴 KAPSAM SATIN ALMA ANINDA YAZILIR, okuma aninda turetilmez.
        // Faz 7'de bu bilgi hic saklanmadi; `invitation_id IS NULL` sorusuyla
        // her okumada yeniden turetiliyordu. O turetme, davetiye silinip
        // nullOnDelete atesledigi gun SESSIZCE yanlislaniyordu (N4).
        //
        // Buradaki turetme farklidir ve mesrudur: cagri YOLU hangi kapsamin
        // alindigini kesin olarak biliyor (K64'un iki ucu) ve sonuc kolona
        // YAZILIYOR. Sinirda bir kez turet, sakla; her okumada yeniden
        // turetme (ders 30'un veri katmanindaki hali).
        //
        // Faz 10 (10.58 · K99): paket de tek davetiyelik. Davetiyesiz alınan
        // sipariş bağlanmamış bir tekil sipariştir; ilk yayında o davetiyeye
        // bağlanır (ClaimReleasedOrderAction). 'account' kapsamı artık yazılmıyor.
        $order->scope = OrderScope::Invitation;

        $order->tier = $tier;
        $order->status = OrderStatus::default();

        // 🔴 4. KATMAN. Fiyat SUNUCUDAN okunur. `$request` govdesinden
        // gelseydi kullanici kendi fiyatini yazardi — paywall'in en ucuz
        // atlatilma yolu budur ve hicbir dogrulama kurali onu yakalamaz,
        // cunku gonderilen deger BICIMSEL olarak gecerlidir.
        //
        // Faz 11: indirim de SUNUCUDA hesaplanir (GiftCode::discountFor, D2).
        $discount = $quote === null ? 0 : $quote->discountMinor;
        $order->amount_minor = $tier->price() * 100 - $discount;
        $order->discount_minor = $discount;
        $order->currency = Config::string('davetkart.currency');

        // F4: surucu KENDI adini soyler; config ile kolon asla ayrisamaz.
        $order->provider = $this->gateway->name();

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
        SubscriptionTier $tier,
        GiftCodeQuote $quote,
    ): Order {
        $order = new Order;

        $order->user_id = $user->id;
        $order->invitation_id = $invitation?->getKey();
        $order->scope = OrderScope::Invitation;
        $order->tier = $tier;

        // orders_paid_at_check: odenmis siparis damga TASIR.
        $order->status = OrderStatus::Paid;
        $order->paid_at = now();

        // orders_gift_amount_check: provider='gift' <=> amount_minor=0.
        $order->amount_minor = 0;
        $order->discount_minor = $quote->discountMinor;
        $order->currency = Config::string('davetkart.currency');
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

    /** FakeGateway'in basari adresiyle ayni bicim: odeme donus sayfasi siparisi yoklar. */
    private function successUrlFor(Order $order): string
    {
        return Config::string('payment.return_urls.success').'?order='.$order->getKey();
    }
}
