<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Media;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;

/**
 * Optimizasyondan sonra geride kalan ESKI dosyayi siler — gecikmeli.
 *
 * 🔴 Neden gecikmeli? OptimizeUploadedImage kuculttugu gorseli yeni bir yola
 * yaziyor ve satiri o yola ceviriyor. Ama yukleme yanitini almis olan sekme
 * elinde hala ESKI URL'i tutuyor: editordeki galeri onizlemesi, misafirin LCV
 * formundaki "yuklendi" gorseli. Eski dosya o anda silinse acik sayfalarda
 * kirik gorsel olurdu. Bekleme suresi (config: replaced_file_grace_hours)
 * boyunca iki dosya birlikte yasar; sayfa yenilendiginde yeni URL gelir.
 *
 * 🔴 Neden ayri bir is? Cunku silme, optimizasyonun BASARISINA bagli degil
 * ama onun ZAMANINA bagli. Zamanlanmis bir komut (media:prune-orphans gibi)
 * "hangi dosyalar artik kullanilmiyor" sorusunu diski TARAYARAK cevaplamak
 * zorunda kalirdi; S3'te bu, binlerce listeleme istegi demek. Gecikmeli is
 * silinecek yolu ZATEN biliyor.
 *
 * Silmeden once yolun sahipsiz oldugu dogrulanir: bu is yalnizca ARTIK
 * KULLANILMAYAN bir dosyayi siler, "eski" oldugunu dusundugu dosyayi degil.
 * Ayrintili aciklama: docs/rehber/app/Jobs/DeleteReplacedMediaFile.md
 */
final class DeleteReplacedMediaFile implements ShouldQueue
{
    use Queueable;

    /** Gecici disk hatalari icin uc deneme; sonra failed_jobs. */
    public int $tries = 3;

    /**
     * Model DEGIL, metin tasiyoruz.
     *
     * 🔴 Bu isin isaret ettigi sey bir SATIR degil, artik hicbir satirin
     * gostermedigi bir DOSYA. SerializesModels ile bir model gecirilse is,
     * silmesi gereken dosyanin yolunu modelden okumaya calisir — oysa model o
     * yolu artik tasimiyor, cevrildi.
     */
    public function __construct(
        public readonly string $disk,
        public readonly string $path,
    ) {}

    public function handle(): void
    {
        // 🔴 Once sahiplik sorusu. Is gecikmeli kostugu icin arada her sey
        // olabilir: optimizasyon geri alinmis, ayni yol baska bir satira
        // yazilmis ya da is elle yeniden kuyruga atilmis olabilir. Bir satir
        // bu yolu gosteriyorsa dosya YASAYAN bir dosyadir.
        //
        // Sorgu ucuz: media tablosunda (disk, path) UNIQUE indeksi var.
        $stillInUse = Media::query()
            ->where('disk', $this->disk)
            ->where('path', $this->path)
            ->exists();

        if ($stillInUse) {
            return;
        }

        // Storage::delete olmayan dosyada da true doner: is ikinci kez kossa
        // bile hata uretmez (idempotans, PruneOrphanMedia ile ayni gerekce).
        Storage::disk($this->disk)->delete($this->path);
    }
}
