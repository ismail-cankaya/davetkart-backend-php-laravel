<?php

declare(strict_types=1);

namespace App\Actions\Contact;

use App\Models\ContactMessage;
use App\Support\IpHasher;

/**
 * Iletisim formu mesajini kaydeder — sistemin DORDUNCU auth'suz yazma yolu.
 *
 * 🔴 SubmitRsvpAction'in sadelesmis kardesi. Faz 5'in bes katmanindan
 * UCUNU devraliyor, ikisini BILEREK devralmiyor:
 *
 *   ✅ 1. Honeypot   -> bot sessizce yutulur, veritabanina hic gidilmez (L2)
 *   ❌ 2. Hedef acik mi -> ortada bir UST KAYNAK yok; form bir davetiyeye ait
 *         degil, dolayisiyla "yayinda mi / modul acik mi / son tarih" sorusu
 *         anlamsiz
 *   ❌ 3. Medya aidiyeti -> dosya kabul etmiyor
 *   ❌ 4. Kota      -> "kac mesaj" sinirinin metrigi yok; hacim kontrolunu
 *         hiz siniri yapiyor (throttle:contact, dakika + saat). L3'un tersi
 *         degil: burada kotanin cevaplayacagi bir soru YOK
 *   ✅ 5. KVKK      -> ham IP degil ip_hash (IpHasher)
 *
 * Bir katmani KOPYALAMAK yerine CIKARMAK, savunmayi zayiflatmak degil dogru
 * boyutlandirmaktir: her katmanin cevapladigi bir soru olmali, yoksa
 * bakimda "bu neden burada?" diye silinir ve gerekli olani da beraberinde
 * goturur.
 * Ayrintili aciklama: docs/rehber/app/Actions/Contact/SubmitContactAction.md
 */
final class SubmitContactAction
{
    /**
     * @param  array<string, mixed>  $attributes  ContactRequest::contactAttributes()
     * @param  string  $ip  Ham IP — SAKLANMAZ, yalnizca hash'lenir
     * @param  bool  $honeypotTripped  Gorunmez alan dolduruldu mu
     */
    public function handle(array $attributes, string $ip, bool $honeypotTripped): void
    {
        // 1. KATMAN — en basta, cunku en ucuzu. Bot ne bir sorgu actirir ne
        // bir satir yazar; yine de basarili gorunen bir yanit alir.
        //
        // 🔴 SubmitRsvpAction burada SAHTE BIR MODEL uretiyordu; sebep,
        // LCV ucunun 201 + govde dondurmesi ve gercek bir kayittan ayirt
        // edilememesi gerektigiydi. Bu uc 204 doner — govde YOK, dolayisiyla
        // ayirt edilecek bir sey de yok. Sessiz red burada BEDAVA geliyor.
        if ($honeypotTripped) {
            return;
        }

        $message = new ContactMessage($attributes);

        // 🔴 ip_hash #[Fillable] listesinde YOK: toplu atamayla degil sunucu
        // kodu tarafindan atanir. Istemciden gelen bir "IP" veri degil
        // YALANDIR (E7 ailesi).
        $message->ip_hash = IpHasher::hash($ip);

        $message->save();

        // 🔴 BILDIRIM YOK ve bu bilincli. Frontend'in contact.ts dosyasi
        // "destek ekibine yonlendirilir" diyor ama BUGUN oyle bir kanal yok:
        // sistemde tek bir Mailable, tek bir bildirim ayari yok. Buraya bir
        // Job eklemek, govdesi yer tutucu olan bir sinif uretirdi (ders 26 /
        // K48). Kanal secildikten sonra eklenecek — o karar Faz 8'in acik
        // kararlar listesinde, frontend'in yanlis vaadi de oyle (B4).
    }
}
