<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Config;

/**
 * KVKK veri minimizasyonu: ham IP asla saklanmaz (CLAUDE.md §3).
 *
 * 🔴 Bu sinif Faz 8'de bir C3 ihlalini kapatmak icin dogdu. Ayni kural
 * depoda IKI FARKLI REFLEKSLE yaziliydi:
 *
 *     SubmitRsvpAction   ->  hash('sha256', $ip.$key)        (Faz 5)
 *     FakeGateway        ->  hash_hmac('sha256', $body, $key) (Faz 7)
 *
 * Ve ucuncusu (iletisim formu) geliyordu. Bir kuralin iki yerde durmasi,
 * birini duzeltip digerini unutmanin yolunu acar; ucuncusu ise kural degil
 * KOPYALAMA aliskanligi uretir.
 *
 * 🔴 hash_hmac SECILDI, duz hash DEGIL. Ikisi de "geri cevrilemez" ama
 * `hash('sha256', $ip.$key)` uzunluk-uzatma (length-extension) saldirisina
 * aciktir: SHA-256 ic durumu ciktiya sizdirdigi icin, saldirgan siri
 * BILMEDEN `hash($ip.$key.$ek)` degerini uretebilir. HMAC tam olarak bu
 * sinifi kapatmak icin tasarlanmis (iki turlu ic/dis hash) bir yapidir.
 *
 * Degisim zararsizdi: `ip_hash` degerleri hicbir yerde KARSILASTIRILMIYOR
 * (yalnizca yaziliyor), hicbir test degerine bakmiyor. Eski satirlarin
 * "gecersiz" olmasi bir islevi bozmuyor.
 *
 * 🔴 Neden enjekte edilebilir bir sinif degil de STATIK metot? Cunku bu
 * saf bir fonksiyondur: ayni girdi + ayni APP_KEY -> ayni cikti, durum yok,
 * yan etki yok. Bir arayuz koysaydik dikis yerinin OBUR TARAFI bos kalirdi
 * — ders 52'nin tersten okunusu: bir soyutlamanin degeri degistirilecek bir
 * uygulama VARSA doğar. IpHasher'in ikinci bir uygulamasi olamaz.
 * Ayrintili aciklama: docs/rehber/app/Support/IpHasher.md
 */
final class IpHasher
{
    /**
     * @param  string  $ip  Ham IP — cagirandan sonra HICBIR YERE yazilmaz
     *
     * @return string 64 karakterlik onaltilik ozet (rsvps.ip_hash,
     *                contact_messages.ip_hash kolonlariyla ayni genislik)
     */
    public static function hash(string $ip): string
    {
        // APP_KEY bir "pepper"dir: yalnizca sha256(ip) yazsaydik saldirgan
        // tum IPv4 uzayinin (~4.3 milyar) ozetini onceden hesaplayip tabloyu
        // geri cozebilirdi. Anahtar karisima girdiginde bu sozluk saldirisi
        // imkansizlasir.
        return hash_hmac('sha256', $ip, Config::string('app.key'));
    }
}
