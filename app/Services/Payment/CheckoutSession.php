<?php

declare(strict_types=1);

namespace App\Services\Payment;

use Carbon\CarbonImmutable;

/**
 * Saglayicinin actigi odeme oturumunun SONUCU — saf veri kabi (DTO).
 *
 * Neden dizi degil sinif? Bir dizi ne alan tasidigini soylemez; yazim hatasi
 * (`redirectURL`) calisma aninda `null` olur ve sessizce yayilir. `readonly`
 * sinif hem alanlari hem tipleri sozlesmeye baglar, PHPStan onlari YAZARKEN
 * dogrular.
 *
 * `readonly`: kurucudan sonra hicbir alan degistirilemez. Bir odeme
 * oturumunun kimligi yolda degisemez — degismezlik burada bir kolaylik degil
 * bir GUVENLIK ozelligidir (K23'un tarihlerdeki karsiligi).
 * Ayrintili aciklama: docs/rehber/app/Services/Payment/PaymentGateway.md §4
 */
final readonly class CheckoutSession
{
    public function __construct(
        /** Saglayicinin bu odemeye verdigi kimlik — orders.provider_ref olur. */
        public string $providerRef,

        /** Kullanicinin yonlendirilecegi odeme sayfasi. */
        public string $redirectUrl,

        /** Oturumun son kullanma ani; null = saglayici pencere vermiyor. */
        public ?CarbonImmutable $expiresAt = null,
    ) {}
}
