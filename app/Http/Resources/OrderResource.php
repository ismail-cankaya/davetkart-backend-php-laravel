<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

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
 *   paid_at / expires_at     -> sunum kararlari; bugun hicbir ekran okumuyor
 *
 * `redirectUrl` bir SIPARIS ALANI DEGIL: CheckoutResult DTO'sundan geliyor ve
 * yalnizca checkout yanitinda bulunuyor (E1 — turetilmis/gecici veri).
 * Ayrintili aciklama: docs/rehber/app/Http/Resources/OrderResource.md
 *
 * @mixin Order
 */
final class OrderResource extends JsonResource
{
    /** Saglayicinin odeme sayfasi; yalnizca checkout yanitinda dolu. */
    private ?string $redirectUrl = null;

    /**
     * Yanita odeme sayfasi baglantisini ekler.
     *
     * Neden kurucu parametresi degil? JsonResource'un kurucusu
     * `collection()` ve `whenLoaded()` gibi framework yollarindan da
     * cagriliyor; imzasini degistirmek onlari kirar. Akici (fluent) bir
     * ayarlayici hem opsiyonelligi hem de "bu alan modelden gelmiyor"
     * gercegini cagri yerinde gorunur kilar.
     */
    public function withRedirectUrl(string $url): self
    {
        $this->redirectUrl = $url;

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
        ];

        // C7: opsiyonel alan YOKSA HIC GITMEZ; `null` gondermek
        // `string | undefined` sozlesmesini kirardi (Faz 5).
        if ($this->redirectUrl !== null) {
            $payload['redirectUrl'] = $this->redirectUrl;
        }

        return $payload;
    }
}
