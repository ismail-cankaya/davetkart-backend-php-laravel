<?php

declare(strict_types=1);

namespace App\Actions\Payment;

use App\Enums\OrderStatus;
use App\Enums\SubscriptionTier;
use App\Exceptions\PaymentProviderException;
use App\Exceptions\PaywallViolationException;
use App\Models\Invitation;
use App\Models\Order;
use App\Models\User;
use App\Services\Payment\PaymentGateway;
use App\Services\Pricing\TierResolver;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
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
 * 4. katman bu fazin en kisa ve en pahali satiridir: fiyat istekten okunsaydi
 * `{"tier":"elit","price":1}` govdesi Elit plani 1 kurusa satardi.
 * Ayrintili aciklama: docs/rehber/app/Actions/Payment/StartCheckoutAction.md
 */
final class StartCheckoutAction
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly TierResolver $tiers,
    ) {}

    /**
     * @param  User  $user  Odemeyi yapan — davetiyenin sahibi (Gate dogruladi)
     * @param  Invitation|null  $invitation  null = PAKET alimi (K42)
     * @param  SubscriptionTier  $tier  Istemcinin sectigi plan — DOGRULANIR
     *
     * @throws PaywallViolationException Secilen plan davetiyeyi kapsamiyor -> 402
     * @throws PaymentProviderException Saglayici oturumu acamadi -> 502
     */
    public function handle(User $user, ?Invitation $invitation, SubscriptionTier $tier): CheckoutResult
    {
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

        $order = $this->createPendingOrder($user, $invitation, $tier);

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

        return new CheckoutResult($order, $session->redirectUrl);
    }

    /**
     * Odenmemis siparis satirini yazar.
     *
     * 🔴 Her alan ACIKCA atanir — Order'in #[Fillable] listesi BOS (E7 ailesi).
     * Toplu atama acik olsaydi `{"status":"paid"}` govdesi odemeyi atlardi.
     */
    private function createPendingOrder(User $user, ?Invitation $invitation, SubscriptionTier $tier): Order
    {
        $order = new Order;

        $order->user_id = $user->id;
        $order->invitation_id = $invitation?->getKey();

        $order->tier = $tier;
        $order->status = OrderStatus::default();

        // 🔴 4. KATMAN. Fiyat SUNUCUDAN okunur. `$request` govdesinden
        // gelseydi kullanici kendi fiyatini yazardi — paywall'in en ucuz
        // atlatilma yolu budur ve hicbir dogrulama kurali onu yakalamaz,
        // cunku gonderilen deger BICIMSEL olarak gecerlidir.
        $order->amount_minor = $tier->price() * 100;
        $order->currency = Config::string('davetkart.currency');

        // F4: surucu KENDI adini soyler; config ile kolon asla ayrisamaz.
        $order->provider = $this->gateway->name();

        $order->expires_at = now()->addMinutes(
            Config::integer('payment.order_expires_after_minutes'),
        );

        $order->save();

        return $order;
    }
}
