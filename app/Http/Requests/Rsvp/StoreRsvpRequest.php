<?php

declare(strict_types=1);

namespace App\Http\Requests\Rsvp;

use App\Http\Requests\Concerns\HasHoneypot;

/**
 * POST /api/public/invitations/{invitation}/rsvps girdisini dogrular.
 *
 * 🔴 Bu sinif sistemdeki TEK auth'suz yazma yolunun ilk kapisi. Buradan gecen
 * her sey "bicimsel olarak gecerli" sayilir — ama gecerli olmak MESRU olmak
 * demek degildir. Son tarih, modul acikligi ve kota IS KURALIDIR ve Action'da
 * denetlenir (H10 ailesi): FormRequest bicim bilir, is bilmez.
 * Faz 10 (10.59): kurallar RsvpRequest'e tasindi; bu sinif yalnizca
 * honeypot'u ekler (ilk gonderim botlara acik, guncelleme koda bagli).
 * Ayrintili aciklama: docs/rehber/app/Http/Requests/Rsvp/StoreRsvpRequest.md
 */
final class StoreRsvpRequest extends RsvpRequest
{
    // 🔴 Honeypot alani ve okuyucusu Faz 8'de trait'e cikarildi: ayni tuzagi
    // iletisim formu da kuruyor ve bir kural iki dosyada duramaz (C3).
    // HONEYPOT_FIELD sabiti trait'ten geliyor; StoreRsvpRequest::HONEYPOT_FIELD
    // erisimi (RsvpTest) aynen calisir.
    use HasHoneypot;
}
