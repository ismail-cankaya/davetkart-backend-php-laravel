<?php

declare(strict_types=1);

namespace Tests\Feature\Media;

use App\Actions\Media\SwapOptimizedMediaFileAction;
use App\Enums\MediaKind;
use App\Jobs\DeleteReplacedMediaFile;
use App\Jobs\OptimizedImage;
use App\Models\Invitation;
use App\Models\Media;
use App\Support\Media\ExifOrientation;
use App\Support\Media\GdAlpha;
use App\Support\Media\ImageDecoder;
use App\Support\Media\ImageEncoder;
use App\Support\Media\ImageResizer;
use App\Support\Media\MemoryBudget;
use GdImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Faz 21.12 (ARC-10): `OptimizeUploadedImage`'den ayrılan sınıfların birim testleri; uçtan uca davranış OptimizeUploadedImageTest'te.
 * Ayrıntılı açıklama: docs/rehber/tests/Feature/Media/ImagePipelineTest.md
 */
final class ImagePipelineTest extends TestCase
{
    use RefreshDatabase;

    // ---------------------------------------------------------------- GdAlpha

    #[Test]
    public function gd_alpha_keeps_a_transparent_pixel_through_encoding(): void
    {
        $image = $this->canvas(2, 2);
        GdAlpha::keep($image);
        imagefilledrectangle($image, 0, 0, 1, 1, (int) imagecolorallocatealpha($image, 255, 0, 0, 127));

        ob_start();
        imagepng($image);
        $decoded = imagecreatefromstring((string) ob_get_clean());
        $this->assertInstanceOf(GdImage::class, $decoded);

        $this->assertSame(127, (imagecolorat($decoded, 0, 0) >> 24) & 0x7F);
    }

    // ----------------------------------------------------------- ImageDecoder

    #[Test]
    public function the_decoder_turns_a_palette_png_into_truecolor(): void
    {
        $palette = imagecreate(4, 4);
        $this->assertInstanceOf(GdImage::class, $palette);
        imagecolorallocate($palette, 10, 20, 30);
        ob_start();
        imagepng($palette);
        $bytes = (string) ob_get_clean();

        $image = (new ImageDecoder)->decode($bytes, 'm1');

        $this->assertInstanceOf(GdImage::class, $image);
        $this->assertTrue(imageistruecolor($image));
    }

    #[Test]
    public function the_decoder_returns_null_for_garbage(): void
    {
        $this->assertNull((new ImageDecoder)->decode('bu bir gorsel degil', 'm1'));
    }

    // ----------------------------------------------------------- ImageResizer

    #[Test]
    public function the_resizer_limits_the_longest_edge_not_the_width(): void
    {
        $resizer = new ImageResizer;

        $landscape = $resizer->toLongestEdge($this->canvas(3000, 2000), 3000, 2000, 'm1');
        $portrait = $resizer->toLongestEdge($this->canvas(2000, 3000), 2000, 3000, 'm1');

        $this->assertSame([2000, 1333], [imagesx($landscape), imagesy($landscape)]);
        $this->assertSame([1333, 2000], [imagesx($portrait), imagesy($portrait)]);
    }

    #[Test]
    public function the_resizer_leaves_a_small_image_as_it_is(): void
    {
        $small = $this->canvas(800, 600);

        $this->assertSame($small, (new ImageResizer)->toLongestEdge($small, 800, 600, 'm1'));
    }

    // -------------------------------------------------------- ExifOrientation

    #[Test]
    public function orientation_six_turns_the_picture_ninety_degrees_clockwise(): void
    {
        // 4x2, sol üst kırmızı. 90 derece saat yönünde: 2x4, kırmızı sağ üstte.
        $image = $this->canvas(4, 2);
        imagesetpixel($image, 0, 0, (int) imagecolorallocate($image, 255, 0, 0));

        $turned = (new ExifOrientation)->apply($image, 6, 'm1');

        $this->assertSame([2, 4], [imagesx($turned), imagesy($turned)]);
        $this->assertSame(0xFF0000, imagecolorat($turned, 1, 0) & 0xFFFFFF);
    }

    #[Test]
    public function orientation_one_and_unknown_values_leave_the_picture_alone(): void
    {
        $image = $this->canvas(4, 2);
        $orientation = new ExifOrientation;

        $this->assertSame($image, $orientation->apply($image, 1, 'm1'));
        $this->assertSame($image, $orientation->apply($image, 9, 'm1'));
    }

    #[Test]
    public function only_a_jpeg_with_an_exif_header_counts_as_carrying_exif(): void
    {
        $withExif = "\xFF\xD8\xFF\xE1\x00\x10Exif\0\0rest";

        $this->assertTrue(ExifOrientation::hasBlock($withExif, 'image/jpeg'));
        $this->assertFalse(ExifOrientation::hasBlock($withExif, 'image/png'));
        $this->assertFalse(ExifOrientation::hasBlock("\xFF\xD8\xFF\xE0JFIF", 'image/jpeg'));
        $this->assertSame(1, (new ExifOrientation)->read($withExif, 'image/png'));
    }

    // ----------------------------------------------------------- ImageEncoder

    #[Test]
    public function png_targets_webp_and_the_rest_keep_their_format(): void
    {
        $this->assertSame('image/webp', ImageEncoder::targetMimeType('image/png'));
        $this->assertSame('image/jpeg', ImageEncoder::targetMimeType('image/jpeg'));
        $this->assertSame('image/webp', ImageEncoder::targetMimeType('image/webp'));
        $this->assertSame(2048 * 1024, ImageEncoder::targetBytes());
    }

    #[Test]
    public function the_encoder_names_the_extension_after_the_format(): void
    {
        $encoder = new ImageEncoder;

        $jpeg = $encoder->compress($this->canvas(40, 40), 'image/jpeg', 'm1');
        $webp = $encoder->compress($this->canvas(40, 40), 'image/webp', 'm1');

        $this->assertInstanceOf(OptimizedImage::class, $jpeg);
        $this->assertInstanceOf(OptimizedImage::class, $webp);
        $this->assertSame(['image/jpeg', 'jpg'], [$jpeg->mimeType, $jpeg->extension]);
        $this->assertSame(['image/webp', 'webp'], [$webp->mimeType, $webp->extension]);
    }

    #[Test]
    public function the_encoder_lowers_quality_until_the_floor_when_the_target_is_unreachable(): void
    {
        $image = $this->noise(300, 300);
        Config::set('davetkart.media.optimize.target_kb', 1);
        $atFloor = (new ImageEncoder)->compress($image, 'image/jpeg', 'm1');

        Config::set('davetkart.media.optimize.target_kb', 100_000);
        $atStart = (new ImageEncoder)->compress($image, 'image/jpeg', 'm1');

        $this->assertInstanceOf(OptimizedImage::class, $atFloor);
        $this->assertInstanceOf(OptimizedImage::class, $atStart);
        $this->assertLessThan($atStart->sizeBytes(), $atFloor->sizeBytes());
    }

    // ----------------------------------------------------------- MemoryBudget

    #[Test]
    public function the_budget_raises_the_limit_only_while_the_work_runs(): void
    {
        $original = ini_get('memory_limit');
        ini_set('memory_limit', '256M');
        Config::set('davetkart.media.optimize.memory_limit', '512M');

        try {
            $inside = MemoryBudget::run(fn (): string => ini_get('memory_limit'));

            $this->assertSame('512M', $inside);
            $this->assertSame('256M', ini_get('memory_limit'));
        } finally {
            ini_set('memory_limit', $original === false ? '-1' : $original);
        }
    }

    #[Test]
    public function the_budget_never_lowers_an_unlimited_or_higher_limit(): void
    {
        $original = ini_get('memory_limit');
        Config::set('davetkart.media.optimize.memory_limit', '512M');

        try {
            ini_set('memory_limit', '-1');
            $this->assertSame('-1', MemoryBudget::run(fn (): string => ini_get('memory_limit')));

            ini_set('memory_limit', '1G');
            $this->assertSame('1G', MemoryBudget::run(fn (): string => ini_get('memory_limit')));
        } finally {
            ini_set('memory_limit', $original === false ? '-1' : $original);
        }
    }

    // ------------------------------------------------ SwapOptimizedMediaFile

    #[Test]
    public function the_swap_writes_a_new_path_and_schedules_the_old_file(): void
    {
        Queue::fake();
        $media = $this->storedMedia();
        $previous = $media->path;

        app(SwapOptimizedMediaFileAction::class)->handle($media, new OptimizedImage('yeni-bayt', 'image/webp', 'webp'));

        $media->refresh();
        $this->assertNotSame($previous, $media->path);
        $this->assertStringEndsWith('.webp', $media->path);
        $this->assertSame(['image/webp', 9], [$media->mime_type, $media->size_bytes]);
        $this->assertNotNull($media->optimized_at);
        Storage::disk($media->disk)->assertExists([$media->path, $previous]);
        Queue::assertPushed(DeleteReplacedMediaFile::class);
    }

    #[Test]
    public function the_swap_backs_off_and_removes_its_file_when_the_row_moved_on(): void
    {
        Queue::fake();
        $media = $this->storedMedia();
        $stale = clone $media;
        Media::query()->whereKey($media->getKey())->update(['path' => 'media/gallery/baska-bir-is.jpg']);

        app(SwapOptimizedMediaFileAction::class)->handle($stale, new OptimizedImage('yeni-bayt', 'image/webp', 'webp'));

        $this->assertSame('media/gallery/baska-bir-is.jpg', $media->refresh()->path);
        $this->assertSame([$stale->path], Storage::disk($media->disk)->allFiles());
        Queue::assertNotPushed(DeleteReplacedMediaFile::class);
    }

    private function storedMedia(): Media
    {
        $disk = Config::string('davetkart.media.disk');
        Storage::fake($disk);
        $path = 'media/gallery/eski.jpg';
        Storage::disk($disk)->put($path, 'eski-bayt');

        return Media::factory()->create([
            'invitation_id' => Invitation::factory(),
            'kind' => MediaKind::Gallery,
            'disk' => $disk,
            'path' => $path,
            'mime_type' => 'image/jpeg',
            'size_bytes' => 9,
        ]);
    }

    /**
     * @param  int<1, max>  $width
     * @param  int<1, max>  $height
     */
    private function canvas(int $width, int $height): GdImage
    {
        $image = imagecreatetruecolor($width, $height);
        $this->assertInstanceOf(GdImage::class, $image);

        return $image;
    }

    /**
     * Sıkışmayan içerik: kalite düştükçe bayt belirgin azalır.
     *
     * @param  int<1, max>  $width
     * @param  int<1, max>  $height
     */
    private function noise(int $width, int $height): GdImage
    {
        $image = $this->canvas($width, $height);
        mt_srand(21);
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                imagesetpixel($image, $x, $y, mt_rand(0, 0xFFFFFF));
            }
        }

        return $image;
    }
}
