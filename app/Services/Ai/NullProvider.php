<?php

declare(strict_types=1);

namespace App\Services\Ai;

/**
 * Aga HIC cikmayan surucu — Null Object Pattern.
 *
 * 🔴 FakeGateway ile KARISTIRMA; ikisi bilerek FARKLI seyler yapiyor:
 *
 *   | Sinif        | Ne der                          | Neden                      |
 *   |--------------|---------------------------------|----------------------------|
 *   | FakeGateway  | "Her seyi yap, yalniz para alma"| Imza dogrulama ve durum    |
 *   |              |                                 | cevirisi URETIMDE ilk kez  |
 *   |              |                                 | calismasin diye            |
 *   | NullProvider | "Hicbir sey yapma"              | Taklit edilecek bir ALGORITMA |
 *   |              |                                 | yok — bir dil modelinin    |
 *   |              |                                 | cevabi sahtelenemez        |
 *
 * Odeme surucusunde sahte olan yalnizca PARAYDI; geri kalan her sey (HMAC,
 * sozluk cevirisi) gercekti ve test edilebilirdi. Burada gercek is TAMAMEN
 * modelin icinde: "sahte bir Gemini" yazmak, uydurma bir cumle dondurmekten
 * ibarettir. O yuzden bu sinif taklit etmeye CALISMIYOR, acikca bos
 * donuyor — ve testler bunun uzerine kuruluyor.
 *
 * Null Object Pattern'in kazanci: cagiran tarafta `if ($provider !== null)`
 * kontrolu YOK. Eksik bir bagimlilik, bir `null` kontrolu degil bir NESNE
 * ile temsil edilir; boylece cagri yolu tek bir sekle sahip olur.
 *
 * 🔴 Bu surucu SESSIZ BIR VARSAYILAN DEGILDIR. Yalnizca acikca
 * secildiginde (AI_PROVIDER=null) veya testte baglandiginda devreye girer.
 * "GEMINI_API_KEY yoksa buna dus" demek, uretimde anahtar unutuldugu gun
 * asistanin sahte cevaplar dondurmesi ve kimsenin fark etmemesi demekti —
 * K70'in tam olarak yasakladigi sey.
 * Ayrintili aciklama: docs/rehber/app/Services/Ai/NullProvider.md
 */
final class NullProvider implements AiProvider
{
    /**
     * Sabit yanit — INGILIZCE ve acikca "yapilandirilmamis" diyor.
     *
     * Turkce bir "merhaba, nasil yardimci olabilirim" yazmak cazipti;
     * reddedildi. O metin gercek bir yanittan AYIRT EDILEMEZ olurdu ve
     * gelistirici, asistanin calistigini sanarak saatlerce yanlis yerde hata
     * arardi. Bir yer tutucu, yer tutucu OLDUGUNU soylemelidir.
     *
     * public: testler bu degere bakarak "hangi surucu bagli" sorusunu
     * sihirli string yazmadan dogrular.
     */
    public const REPLY = 'The DavetKart assistant is not configured in this environment.';

    public function name(): string
    {
        return 'null';
    }

    /**
     * Girdiye BAKMAZ ve hicbir yere yazmaz.
     *
     * Parametre adinin onunde alt cizgi yok ama govdede kullanilmiyor: PHP
     * bunu sorun etmez, PHPStan da etmez cunku arayuz imzasi zorunlu kiliyor.
     * Kullanilmamis olmasi burada bir eksiklik degil, sinifin TANIMIDIR.
     */
    public function reply(string $prompt): string
    {
        return self::REPLY;
    }
}
