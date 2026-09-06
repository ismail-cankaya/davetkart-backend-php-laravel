<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Iletisim formunun konu basligi.
 *
 * 🔴 Degerler frontend'in `services/contact.ts` dosyasindaki tiple BIREBIR
 * aynidir ve oyle kalmak zorundadir:
 *
 *     export type ContactSubject =
 *       | 'general' | 'support' | 'pricing' | 'partnership' | 'kvkk';
 *
 * Degerler INGILIZCE ve makine-okunurdur (K21): veritabani tek dil konusur,
 * ceviri frontend'in isidir. RsvpStatus'un ayni karari — 'Genel Soru' gibi
 * bir GOSTERIM metni asla veri degeri olamaz, cunku o durumda arayuzun
 * dilini degistirmek veritabanini degistirmek olurdu.
 *
 * 🔴 label() METODU YOK ve olmayacak. Cazip gorunuyor ("konu basligini
 * Turkceye cevirelim") ama gosterim metni backend'in isi degil (K20/K21) ve
 * RsvpStatus ile OrderStatus'te de yok. Ustelik ders 26 hazir bir ornek
 * veriyor: SubscriptionTier::label() Faz 0'da yazildi ve sekiz faz boyunca
 * hicbir yerden cagrilmadi.
 * Ayrintili aciklama: docs/rehber/app/Enums/ContactSubject.md
 */
enum ContactSubject: string
{
    /** Genel bilgi talebi. */
    case General = 'general';

    /** Var olan bir kayitla ilgili destek. */
    case Support = 'support';

    /** Fiyat ve planlar. */
    case Pricing = 'pricing';

    /** Is birligi / bayilik. */
    case Partnership = 'partnership';

    /** KVKK basvurusu — veri sahibi haklari (silme, erisim, duzeltme). */
    case Kvkk = 'kvkk';

    /**
     * Veritabani CHECK kisiti ve `in:` dogrulama kurali icin ham degerler.
     *
     * 🔴 Tek kaynak: kisit da kural da BURADAN beslenir. Elle yazilsalardi
     * enum'a altinci bir konu eklendigi gun ikisi de sessizce eskirdi (K39).
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
