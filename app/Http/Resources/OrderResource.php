<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Order;
use App\Services\Payment\CheckoutAction;
use App\Services\Payment\FormPostAction;
use App\Services\Payment\RedirectAction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use LogicException;

/**
 * Siparis kaydinin ISTEMCIYE acik surumu — types.ts -> CheckoutResult.
 *
 * 🔴 C1: Resource bir BEYAZ LISTEDIR. Asagida OLMAYANLAR bilincli:
 *
 *   provider / provider_ref -> saglayici ic kimligi. Disari verilmesi hem
 *                              altyapiyi ele verir hem de idempotans
 *                              anahtarini istemciye ogretir (taklit riski)
 *   user_id                  -> istemci zaten kendisi
 *   amount_minor / currency  -> 🔴 acik karar: fiyat frontend'in KATALOGUNDA
 *                              zaten var (data.ts). Ikinci kaynak olarak
 *                              gondermek, iki fiyatin ayrisabildigi bir ekran
 *                              uretirdi. Fatura ucu dogunca (Faz 9) o uc kendi
 *                              Resource'unu alir (C4)
 *   expires_at               -> sunum karari; hicbir ekran okumuyor
 *   scope                    -> "paket mi tekil mi" invitationId'den okunabilir
 *                              kadariyla yeterli; kapsam enum'u bir ic kural
 *
 * Faz 10 (10.24) ile EKLENENLER — odeme donus sayfasi ve siparis listesi icin:
 *   invitationId -> hangi davetiyeye ait (null: paket ya da serbest birakilmis)
 *   createdAt    -> listede siralama ve "ne zaman" sorusu
 *   paidAt       -> "odendi" ekraninin tarihi (null: odenmedi)
 *
 * Faz 11 ile EKLENEN — hediye kodu indirimi:
 *   discountMinor -> dusulen indirim (kurus, int). YALNIZCA indirim varsa gider
 *                    (C7). Tutar alanlari hala YOK (yukaridaki acik karar ve
 *                    OrderTest'in "amount" taramasi): istemci fiyati katalogdan,
 *                    indirimi buradan bilir; odenen = fiyat − indirim.
 *
 * `redirectUrl` bir SIPARIS ALANI DEGIL: CheckoutResult DTO'sundan geliyor ve
 * yalnizca checkout yanitinda bulunuyor (E1 — turetilmis/gecici veri).
 *
 * Faz 12 (K119) — `checkout`, yine yalnizca checkout yanitinda:
 *   {type: 'redirect', url}           -> bir adrese git
 *   {type: 'form', url, fields}       -> imzali alanlari POST et (Shopier)
 * Eski `redirectUrl` `type=redirect` oldugu surece AYNEN gider: frontend'in
 * bugunku surumu onu okuyor. Kaldirilmasi ayri bir karar.
 * Ayrintili aciklama: docs/rehber/app/Http/Resources/OrderResource.md
 *
 * @mixin Order
 */
final class OrderResource extends JsonResource
{
    /** Odeme sayfasina nasil gidilecegi; yalnizca checkout yanitinda dolu. */
    private ?CheckoutAction $checkout = null;

    /**
     * Yanita odeme sayfasi baglantisini ekler.
     *
     * Neden kurucu parametresi degil? JsonResource'un kurucusu
     * `collection()` ve `whenLoaded()` gibi framework yollarindan da
     * cagriliyor; imzasini degistirmek onlari kirar. Akici (fluent) bir
     * ayarlayici hem opsiyonelligi hem de "bu alan modelden gelmiyor"
     * gercegini cagri yerinde gorunur kilar.
     */
    public function withCheckout(CheckoutAction $checkout): self
    {
        $this->checkout = $checkout;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $payload = [
            // C: id STRING doner (ULID) — frontend `orderId: string` bekliyor.
            'orderId' => (string) $this->id,
            'tier' => $this->tier->value,
            'status' => $this->status->value,

            // Faz 10 (10.24). Uc alan da HER ZAMAN var, degeri null olabilir:
            // C7 yalnizca `redirectUrl` gibi baglama gore VAR OLAN alanlar
            // icindir. Bunlar her siparisin alanlari; "yok" ile "henuz
            // odenmedi" ayni sey degil (N4).
            'invitationId' => $this->invitation_id,
            'createdAt' => $this->created_at?->toIso8601String(),
            'paidAt' => $this->paid_at?->toIso8601String(),
        ];

        // Faz 11 · C7: indirimsiz sipariste alan hic gitmez.
        if ($this->discount_minor > 0) {
            $payload['discountMinor'] = $this->discount_minor;
        }

        // C7: opsiyonel alan YOKSA HIC GITMEZ; `null` gondermek
        // `string | undefined` sozlesmesini kirardi (Faz 5).
        if ($this->checkout !== null) {
            $payload = [...$payload, ...$this->checkoutFields($this->checkout)];
        }

        return $payload;
    }

    /**
     * Tagged union'in JSON hali (K119).
     *
     * 🔴 Varsayilan kol FIRLATIR: ucuncu bir CheckoutAction yazilip burasi
     * unutulursa sessizce `redirect` gibi davranmak yerine gurultuyle kirilir.
     *
     * @return array<string, mixed>
     */
    private function checkoutFields(CheckoutAction $checkout): array
    {
        return match (true) {
            $checkout instanceof RedirectAction => [
                'redirectUrl' => $checkout->url(),
                'checkout' => ['type' => 'redirect', 'url' => $checkout->url()],
            ],
            $checkout instanceof FormPostAction => [
                'checkout' => ['type' => 'form', 'url' => $checkout->url(), 'fields' => $checkout->fields()],
            ],
            default => throw new LogicException('Unknown checkout action: '.$checkout::class),
        };
    }
}
