<?php

declare(strict_types=1);

namespace App\Support;

/**
 * E-postanin kanonik bicimi: kayit, giris, hiz siniri anahtari ve User
 * modeli AYNI fonksiyonu cagirir (Faz 10, 10.12 · D-5 · denetim K-3).
 *
 * 🔴 Neden `mb_strtolower()` yetmiyor? Turkce buyuk `İ` (U+0130) Unicode'un
 * varsayilan kucuk harf eslemesinde TEK karakter degil, IKI karakter olur:
 *
 *     mb_strtolower('İsmail@…')  ->  "i̇smail@…"   (i + U+0307 birlesen nokta)
 *
 * Ekranda `ismail` gibi gorunur, baytta degildir. Sonuc (denetimde uretildi):
 * `İsmail@…` ile kaydolan kullanici `ismail@…` ile giremez (401) ve ayni
 * adresle IKINCI bir hesap acabilir (201).
 *
 * 🔴 Kural "once İ->i, sonra kucult" DEGIL, "kucult, sonra i̇->i". Ayni
 * harf iki bicimde gelebilir ve kucultulunce ikisi de ayni diziye duser:
 *
 *     'İ'           (U+0130, Turkce klavye)          -> "i̇"
 *     'I' + U+0307  (ayristirilmis bicim, yapistirma) -> "i̇"
 *
 * Tek kural ikisini de, veritabanina onceden yazilmis bozuk satirlari da
 * (10.14) kapsar. Noktasiz `ı` (U+0131) BILEREK donusturulmez: o ayri bir
 * harftir, `ısmail@` ile `ismail@` farkli adreslerdir.
 *
 * 🔴 Neden BURADA ve tek yerde? Bugune kadar dort yer ayri ayri
 * `mb_strtolower(trim(...))` yaziyordu (C3). Biri unutulsaydi hiz siniri
 * kovasi ile kayit ayri anahtarla calisirdi: `İ` ile `i` iki ayri kova =
 * saldirgana iki kat deneme hakki.
 *
 * Statik metot, IpHasher ile ayni gerekce: saf fonksiyon, ikinci bir
 * uygulamasi olamaz.
 * Ayrintili aciklama: docs/rehber/app/Support/EmailNormalizer.md
 */
final class EmailNormalizer
{
    /** `i` + U+0307 (COMBINING DOT ABOVE): `İ`'nin kucultulmus hali. */
    private const DOTTED_SMALL_I = "i\u{0307}";

    public static function normalize(string $email): string
    {
        return str_replace(self::DOTTED_SMALL_I, 'i', mb_strtolower(trim($email)));
    }
}
