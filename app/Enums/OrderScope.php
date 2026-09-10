<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Bir siparisin NE SATIN ALDIGI — tekil bir davetiye mi, hesabin tamami mi.
 *
 * 🔴 Bu kolon `invitation_id`'nin cevaplayamadigi soruyu cevaplar. Faz 7'de
 * ikisi tek kolondaydi ve `invitation_id IS NULL` "paket alimi" demekti; ayni
 * NULL "tekil siparisin davetiyesi silindi" anlamina da geldigi icin iki ayri
 * olgu tek bicimde temsil ediliyordu (N4). Ayrim:
 *
 *   scope          -> DEGISMEZ. Satin alma aninda yazilir, bir daha degismez.
 *   invitation_id  -> DEGISEBILIR. Hakkin SU AN hangi davetiyede durdugu.
 *
 * Boylece bir tekil siparis serbest birakildiginda (invitation_id = NULL)
 * pakete DONUSEMEZ; sahibi onu yeni bir davetiyeye baglayana kadar hicbir
 * yayin hakki vermez.
 * Ayrintili aciklama: docs/rehber/app/Enums/OrderScope.md
 */
enum OrderScope: string
{
    /** Tek bir davetiye icin alindi. Hak, o an bagli oldugu davetiyede durur. */
    case Invitation = 'invitation';

    /** Hesap icin alindi (paket). Hicbir davetiyeye baglanmaz. */
    case Account = 'account';

    /**
     * Bu kapsam, sahibinin HERHANGI bir davetiyesine yayin hakki verir mi?
     *
     * OrderStatus::grantsPublishRight() ile ayni desen: "hangi degerler sayilir"
     * sorusu SQL'de degil enum'da cevaplanir. OrderEntitlementResolver bu
     * yuklemi kullanarak kapsamlari suzer; yarin ucuncu bir kapsam eklenirse
     * (kampanya, hediye kodu) sorgu degismez, yalnizca bu metot degisir.
     */
    public function grantsAcrossAccount(): bool
    {
        return $this === self::Account;
    }

    /**
     * Bu kapsamdaki bir siparisin hakki bir davetiyeden GERI ALINABILIR mi?
     *
     * Yalnizca tekil siparisler icin anlamli: paket zaten hicbir davetiyeye
     * bagli degildir, dolayisiyla serbest birakilacak bir bagi da yoktur.
     * Silme akisi (DeleteInvitationAction) once bunu sorar, sonra 3 gunluk
     * pencereye bakar.
     */
    public function isReleasable(): bool
    {
        return $this === self::Invitation;
    }

    // 🔴 default() BILEREK YOK.
    //
    // Yeni bir siparisin kapsami tahmin edilemez: satin alma yolu (K64'un iki
    // ucu) hangi kapsamin alindigini KESIN olarak bilir ve onu acikca yazar
    // (E7). Buraya bir varsayilan konsaydi, kapsami yazmayi unutan bir cagri
    // yolu sessizce o varsayilana duserdi — 'account' ise bedava sinirsiz
    // yayin, 'invitation' ise odenen paketin calismamasi. K70'in "bilinmeyen
    // surucude sessiz varsayilan yok" refleksiyle ayni sebep: yanlis bir
    // varsayilan, yapilandirma hatasini PARA hatasina cevirir.

    /**
     * Veritabani CHECK kisiti icin ham degerler.
     *
     * Elle yazilmaz: enum degisince kisit sessizce eskimesin (K39).
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
