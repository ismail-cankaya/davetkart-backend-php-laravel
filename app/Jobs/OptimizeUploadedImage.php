<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Media\SwapOptimizedMediaFileAction;
use App\Models\Media;
use App\Support\Media\ExifOrientation;
use App\Support\Media\ImageDecoder;
use App\Support\Media\ImageEncoder;
use App\Support\Media\ImageResizer;
use App\Support\Media\MemoryBudget;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Yüklenmiş görseli kuyrukta küçültür (15 saniye kuralı). İş yalnızca SIRAYI yönetir; adımlar app/Support/Media'da (ARC-10).
 * `optimized_at` "küçüldü" değil "geçiş tamamlandı" demek. Ayrıntılı açıklama: docs/rehber/app/Jobs/OptimizeUploadedImage.md
 */
final class OptimizeUploadedImage implements ShouldQueue
{
    use Queueable;

    /** Geçici hatalar için üç deneme; sonra failed_jobs (sınırsız deneme kuyruğu tıkardı). */
    public int $tries = 3;

    /** Dosya iş koşmadan silinmişse (galeriden silme) iş sessizce düşer; failed_jobs'a sahte hata yazılmaz. */
    public bool $deleteWhenMissingModels = true;

    /** Model kuyruğa KİMLİĞİYLE serileşir; iş koştuğunda taze okunur. */
    public function __construct(
        public readonly Media $media,
    ) {}

    public function handle(): void
    {
        // İdempotans veriyle (damga veritabanında), kuyruk mekanizmasıyla değil. Video bu işin konusu değil.
        if ($this->media->isOptimized() || ! $this->media->kind->isOptimizable()) {
            return;
        }

        $contents = Storage::disk($this->media->disk)->get($this->media->path);

        if ($contents === null) {
            // Hata değil, iş kalmadı. Damga ATILMAZ: dosya yedekten dönerse iş yeniden koşabilsin.
            Log::info('Optimize atlandi: dosya bulunamadi.', ['media' => $this->media->id]);

            return;
        }

        // Önce BAŞLIK: boyutları çözmeden öğrenmek kısa yolu ve bellek korumasını mümkün kılar.
        $header = @getimagesizefromstring($contents);

        if ($header === false) {
            Log::warning('Gorsel cozulemedi.', ['media' => $this->media->id]);
            $this->stamp();

            return;
        }

        [$width, $height] = [(int) $header[0], (int) $header[1]];

        if ($this->exceedsProcessingLimit($width, $height) || $this->alreadyAtTarget($contents, $width, $height)) {
            $this->stamp();

            return;
        }

        $optimized = MemoryBudget::run(fn (): ?OptimizedImage => $this->encodeOptimized($contents, $width, $height));

        if ($optimized === null || ! $this->isWorthWriting($optimized, $contents)) {
            $this->stamp();

            return;
        }

        app(SwapOptimizedMediaFileAction::class)->handle($this->media, $optimized);
    }

    /** 🔴 Piksel koruması: doğrulamadaki `dimensions` kuralının ikizi; kuyrukta o kuraldan geçmemiş satır olabilir. */
    private function exceedsProcessingLimit(int $width, int $height): bool
    {
        $maxDimension = Config::integer('davetkart.media.optimize.max_dimension_px');

        if ($width <= $maxDimension && $height <= $maxDimension) {
            return false;
        }

        Log::warning('Gorsel cozunurlugu isleme sinirinin ustunde; optimize atlandi.', [
            'media' => $this->media->id,
            'width' => $width,
            'height' => $height,
            'max_dimension_px' => $maxDimension,
        ]);

        return true;
    }

    /** Kısa yol: hedef biçimde, hedef boyut ve ölçünün altında, silinecek EXIF yok → görsel hiç çözülmez. */
    private function alreadyAtTarget(string $contents, int $width, int $height): bool
    {
        return ImageEncoder::targetMimeType($this->media->mime_type) === $this->media->mime_type
            && $this->media->size_bytes <= ImageEncoder::targetBytes()
            && max($width, $height) <= Config::integer('davetkart.media.optimize.max_edge_px')
            && ! ExifOrientation::hasBlock($contents, $this->media->mime_type);
    }

    /** Çöz → küçült → yönü düzelt → kodla. Çözülmüş görsel bu çerçeveden çıkmaz (bellek bütçesi geri inince serbest). */
    private function encodeOptimized(string $contents, int $width, int $height): ?OptimizedImage
    {
        $mediaId = (string) $this->media->id;
        $image = (new ImageDecoder)->decode($contents, $mediaId);

        if ($image === null) {
            return null;
        }

        $image = (new ImageResizer)->toLongestEdge($image, $width, $height, $mediaId);
        $orientation = new ExifOrientation;
        $image = $orientation->apply($image, $orientation->read($contents, $this->media->mime_type), $mediaId);

        return (new ImageEncoder)->compress($image, ImageEncoder::targetMimeType($this->media->mime_type), $mediaId);
    }

    /** Biçim değişti, dosya küçüldü ya da kaynakta EXIF vardı (🔴 KVKK: konum silinir); hiçbiri yoksa dosyaya dokunulmaz. */
    private function isWorthWriting(OptimizedImage $optimized, string $contents): bool
    {
        return $optimized->mimeType !== $this->media->mime_type
            || $optimized->sizeBytes() < $this->media->size_bytes
            || ExifOrientation::hasBlock($contents, $this->media->mime_type);
    }

    /** "Geçiş tamamlandı" damgası; dosya değişmese de atılır, yoksa aynı dosya her işte yeniden çözülürdü. */
    private function stamp(): void
    {
        $this->media->optimized_at = now();
        $this->media->save();
    }
}
