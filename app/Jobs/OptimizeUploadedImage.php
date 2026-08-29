<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Media;
use GdImage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Yuklenmis bir gorseli kuyrukta kucultur.
 *
 * 🔴 "15 SANIYE KURALI" (CLAUDE.md §4): istege HEMEN cevap verilir, uzun suren
 * is ana HTTP surecini bekletmez. Frontend'in axios timeout'u 15 saniye; bir
 * gorseli yeniden kodlamak buyuk dosyalarda saniyeler surer ve kullanici
 * yukleme ekraninda beklerdi.
 *
 * Bu yuzden Action dosyayi diske yazip URL'i DONER; kucultme sonra olur.
 * Kullanici once buyuk dosyayi gorur, birkac saniye sonra kucugu — ama HICBIR
 * ZAMAN beklemez.
 *
 * ⚠️ `optimized_at` "bayt sayisi azaldi" demek DEGIL, "optimizasyon gecisi
 * TAMAMLANDI" demek. Zaten kucuk bir gorselde hicbir sey degismeyebilir.
 * Ayrintili aciklama: docs/rehber/app/Jobs/OptimizeUploadedImage.md
 */
final class OptimizeUploadedImage implements ShouldQueue
{
    use Queueable;

    /**
     * Gecici hatalar (disk mesgul, bellek) icin uc deneme; sonra failed_jobs.
     * Sinirsiz deneme, bozuk bir dosya yuzunden kuyrugu tikardi.
     */
    public int $tries = 3;

    /** Model kuyruga KIMLIGIYLE serilesir; is kostugunda taze okunur. */
    public function __construct(
        public readonly Media $media,
    ) {}

    public function handle(): void
    {
        // 1) Idempotans — VERIYLE saglaniyor, kuyruk mekanizmasiyla degil.
        // ShouldBeUnique cache surucusune baglidir; cache temizlenirse sessizce
        // devre disi kalir. Damga veritabaninda durur.
        if ($this->media->isOptimized()) {
            return;
        }

        // 2) Video bu isin konusu degil (MediaKind::isOptimizable).
        if (! $this->media->kind->isOptimizable()) {
            return;
        }

        $disk = Storage::disk($this->media->disk);
        $contents = $disk->get($this->media->path);

        if ($contents === null) {
            // Dosya silinmis olabilir (davetiye silindi, elle temizlik).
            // Hata degil: yapilacak is kalmadi.
            Log::info('Optimize atlandi: dosya bulunamadi.', ['media' => $this->media->id]);

            return;
        }

        $optimized = $this->reencode($contents);

        // 3) 🔴 YALNIZCA KUCULDUYSE yaz. Yeniden kodlama bazen dosyayi
        // BUYUTUR (ornegin zaten optimize edilmis bir PNG). "Optimizasyon"
        // adi altinda dosyayi buyutmek, adin yalan soylemesi olurdu.
        if ($optimized !== null && strlen($optimized) < $this->media->size_bytes) {
            $disk->put($this->media->path, $optimized);
            $this->media->size_bytes = strlen($optimized);
        }

        $this->media->optimized_at = now();
        $this->media->save();
    }

    /**
     * Gorseli GD ile yeniden kodlar; basarisizsa null.
     *
     * GD eklentisi ya da bicim destegi yoksa null doner ve is SESSIZCE ama
     * LOGLANARAK gecer: optimize edememek bir kullanici hatasi degil, bir
     * ortam eksikligidir — yuklemeyi geri almak icin sebep degil.
     */
    private function reencode(string $contents): ?string
    {
        if (! extension_loaded('gd')) {
            Log::warning('GD eklentisi yok; gorsel optimizasyonu atlandi.');

            return null;
        }

        $image = @imagecreatefromstring($contents);

        if ($image === false) {
            Log::warning('Gorsel cozulemedi.', ['media' => $this->media->id]);

            return null;
        }

        $image = $this->downscale($image);

        $quality = Config::integer('davetkart.media.optimize.jpeg_quality');

        // Cikti tamponu: GD fonksiyonlari dosya yolu null iken DOGRUDAN ciktiya
        // yazar. ob_start/ob_get_clean onu tamamen yakalar — yani T3'un
        // ("testte cikti uretilmez") ihlali degildir.
        ob_start();

        $written = match ($this->media->mime_type) {
            'image/jpeg' => imagejpeg($image, null, $quality),
            'image/png' => imagepng($image),
            'image/webp' => function_exists('imagewebp') && imagewebp($image, null, $quality),
            default => false,
        };

        $bytes = (string) ob_get_clean();

        imagedestroy($image);

        return $written === true && $bytes !== '' ? $bytes : null;
    }

    /**
     * Cok genis gorselleri config'teki sinira indirir.
     *
     * Telefon kameralari 4000+ piksel genisliginde uretiyor; bir davetiye
     * galerisinde 2000 piksel fazlasiyla yeterli. Asil kazanc burada:
     * yeniden kodlamadan cok, PIKSEL SAYISINI dusurmekten geliyor.
     */
    private function downscale(GdImage $image): GdImage
    {
        $maxWidth = Config::integer('davetkart.media.optimize.max_width_px');
        $width = imagesx($image);

        if ($width <= $maxWidth) {
            return $image;
        }

        $height = (int) round(imagesy($image) * ($maxWidth / $width));
        $resized = imagescale($image, $maxWidth, $height);

        if ($resized === false) {
            return $image;
        }

        imagedestroy($image);

        return $resized;
    }
}
