<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

/**
 * Bot tuzagi: forma insana GORUNMEZ bir alan konur (L2).
 *
 * Insan doldurmaz cunku goremez; otomatik doldurma yapan botlarin cogu her
 * input'u doldurur. Alan adi bilerek masum ve cazip secildi — 'honeypot'
 * deseydik bot da anlardi.
 *
 * 🔴 Faz 8'de trait'e cikarildi. Faz 5'te tek bir formda duruyordu ve
 * ikinci form (iletisim) onu KOPYALAYACAKTI. Kopyalasaydik alan adini bir
 * gun degistirmek iki dosyayi birden hatirlamayi gerektirirdi ve unutulan
 * form sessizce savunmasiz kalirdi (C3).
 *
 * 🔴 Bu trait KARAR VERMEZ, yalnizca OLGUYU bildirir. "Alan doluysa ne
 * yapilacagi" bir is kuralidir ve Action'a aittir — FormRequest bicim
 * bilir, is bilmez (H10 ailesi). Bu ayrim onemli: karar burada olsaydi
 * 422 donmek cazip gelirdi ve bota "yakalandin" demis olurduk; savunma bir
 * kez kullanilip olurdu (L2: bot tespiti SESSIZDIR).
 * Ayrintili aciklama: docs/rehber/app/Http/Requests/Concerns/HasHoneypot.md
 *
 * @phpstan-require-extends \Illuminate\Foundation\Http\FormRequest
 */
trait HasHoneypot
{
    /**
     * Gorunmez alanin adi.
     *
     * 🔴 Bu alana DOGRULAMA KURALI KONMAZ. Bir kural koysaydik ihlali 422
     * doner ve zarfin `fields` bolumunde alanin adi gorunurdu — yani tuzagin
     * yerini saldirgana biz soylerdik.
     */
    public const HONEYPOT_FIELD = 'website';

    /**
     * validated() yerine input() okunuyor cunku alanin dogrulama kurali yok.
     * Burada okunan sey bir DEGER degil, bir VARLIK/YOKLUK sinyalidir;
     * icerigine hicbir yerde guvenilmiyor.
     */
    public function isHoneypotTripped(): bool
    {
        /** @var mixed $value */
        $value = $this->input(self::HONEYPOT_FIELD);

        // ConvertEmptyStringsToNull global middleware'i '' degerini null
        // yapar, yani alani bos gonderen durustler burada elenmez.
        return $value !== null && $value !== [];
    }
}
