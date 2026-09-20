<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\MediaKind;
use App\Events\InvitationChanged;
use App\Jobs\DeleteReplacedMediaFile;
use App\Jobs\OptimizeUploadedImage;
use App\Models\Invitation;
use App\Models\Media;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Faz 9'un kaniti: kuyruktaki KUCULTME isi.
 *
 * 🔴 Bu testler isin `handle()` metodunu DOGRUDAN cagirir, HTTP ucundan
 * gecmez. Sebebi yontem tercihi degil zorunluluk: `UploadedFile::fake()`
 * gorseli PHP'nin yukleme katmanindan gecirmez ve uretilen kare BOS bir
 * truecolor goruntudur — JPEG onu birkac yuz bayta indirir. Yani yukleme
 * ucundan yapilan bir test "kuculdu mu" sorusunu hic sormamis olurdu.
 *
 * Bu yuzden gorseller burada ELLE uretiliyor: yumusak gecisler + gurultu,
 * sikistirilabilirligi gercek bir fotografa yaklastirir.
 *
 * ⚠️ Bu dosya var olmadan once isin `handle()` metodunun HICBIR testi yoktu;
 * yalnizca "kuyruga atildi mi" sinaniyordu (MediaTest). Kilavuzun andigi
 * `optimize_job_is_idempotent` testi de yoktu.
 * Ayrintili aciklama: docs/rehber/tests/Feature/OptimizeUploadedImageTest.md
 */
final class OptimizeUploadedImageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake($this->disk());
    }

    // --------------------------------------------------------- KUCULTME

    #[Test]
    public function a_landscape_photo_is_reduced_to_the_longest_edge(): void
    {
        $media = $this->storeMedia($this->photoJpeg(3000, 2000), 'image/jpeg');
        $before = $media->size_bytes;

        $this->optimize($media);

        $media->refresh();

        $this->assertSame([2000, 1333], $this->dimensionsOf($media));
        $this->assertLessThan($before, $media->size_bytes);
        $this->assertSame('image/jpeg', $media->mime_type);
        $this->assertNotNull($media->optimized_at);
    }

    /**
     * 🔴 Eski kod YALNIZCA genisligi sinirliyordu: 2000x3000'lik dikey bir
     * fotograf "genisligi 2000, sinir 2000" diye HIC kucultulmuyordu — oysa
     * 6 MP'lik bir dosyaydi. Telefonla cekilen fotograflarin cogunlugu dikey.
     */
    #[Test]
    public function a_portrait_photo_is_reduced_by_its_height(): void
    {
        $media = $this->storeMedia($this->photoJpeg(2000, 3000), 'image/jpeg');

        $this->optimize($media);

        $this->assertSame([1333, 2000], $this->dimensionsOf($media->refresh()));
    }

    /**
     * Tarayici sikistirmasi calisan her yukleme buradan cikar: dosya hedefteyse
     * gorsel HIC cozulmez, yalnizca damga atilir.
     */
    #[Test]
    public function an_image_that_is_already_at_target_is_left_untouched(): void
    {
        Queue::fake();

        $contents = $this->photoJpeg(600, 400, 70);
        $media = $this->storeMedia($contents, 'image/jpeg');
        $path = $media->path;

        $this->optimize($media);

        $media->refresh();

        $this->assertSame($path, $media->path);
        $this->assertSame($contents, Storage::disk($this->disk())->get($path));
        $this->assertSame([$path], Storage::disk($this->disk())->allFiles());
        $this->assertNotNull($media->optimized_at);

        Queue::assertNothingPushed();
    }

    /**
     * Hedefe ilk kodlamada inilemezse kalite basamak basamak duser.
     *
     * Test KENDINI olcekliyor: ayni kaynak iki kez isleniyor, ikincisinde hedef
     * ulasilamayacak kadar kucuk. Beklenen bayt sayisini sabit yazmak, GD ve
     * libjpeg surumlerine bagli kirilgan bir test olurdu.
     */
    #[Test]
    public function quality_is_lowered_until_the_file_fits_the_target(): void
    {
        $contents = $this->photoJpeg(2400, 1600);

        $withDefaultTarget = $this->storeMedia($contents, 'image/jpeg');
        $this->optimize($withDefaultTarget);

        Config::set('davetkart.media.optimize.target_kb', 1);

        $withTinyTarget = $this->storeMedia($contents, 'image/jpeg');
        $this->optimize($withTinyTarget);

        $this->assertLessThan(
            $withDefaultTarget->refresh()->size_bytes,
            $withTinyTarget->refresh()->size_bytes,
        );

        // Taban kaliteye inildi ve orada DURULDU: sonsuz dongu yok, dosya hala
        // cozulebilir bir gorsel.
        $this->assertSame([2000, 1333], $this->dimensionsOf($withTinyTarget));
    }

    // ------------------------------------------------------------- EXIF

    /**
     * 🔴 Telefon fotografini yan yatirmamanin testi.
     *
     * Kaynak 1200x600 (yatay) ama EXIF "gosterirken 90 derece cevir" diyor.
     * Yon uygulanmazsa cikti 1200x600 kalir ve tarayici artik cevirmez —
     * cunku yeniden kodlanan dosyada o not silinmistir. Dogru sonuc 600x1200.
     */
    #[Test]
    public function the_exif_orientation_is_applied_and_the_metadata_is_dropped(): void
    {
        $contents = $this->withExifOrientation($this->photoJpeg(1200, 600), 6);
        $media = $this->storeMedia($contents, 'image/jpeg');

        $this->optimize($media);

        $media->refresh();

        $this->assertSame([600, 1200], $this->dimensionsOf($media));
        $this->assertStringNotContainsString(
            "Exif\0\0",
            Storage::disk($this->disk())->get($media->path) ?? '',
        );
    }

    /**
     * KVKK: yeniden kodlanmis cikti meta veri tasimaz, yani konum (GPS) silinir.
     * Bu yuzden EXIF'li bir dosya, KUCULMESE BILE yeniden yazilir.
     */
    #[Test]
    public function a_file_with_exif_is_rewritten_even_when_it_does_not_shrink(): void
    {
        // Kucuk ve zaten dusuk kaliteli: yeniden kodlama bayt kazandirmaz.
        $contents = $this->withExifOrientation($this->photoJpeg(400, 300, 40), 1);
        $media = $this->storeMedia($contents, 'image/jpeg');
        $path = $media->path;

        $this->optimize($media);

        $media->refresh();

        $this->assertNotSame($path, $media->path);
        $this->assertStringNotContainsString(
            "Exif\0\0",
            Storage::disk($this->disk())->get($media->path) ?? '',
        );
    }

    // -------------------------------------------------------- PNG -> WEBP

    /**
     * 🔴 PNG kayipsizdir: 2000 px'e indirilmis bir fotograf PNG olarak ~3-5 MB
     * kalir, yani "2 MB'in altina in" hedefi PNG'de ULASILAMAZ. WebP hem
     * kayipli sikistirir hem alfa kanalini tasir (JPEG seffafligi siyahlatirdi).
     */
    #[Test]
    public function a_png_becomes_a_webp_and_keeps_its_transparency(): void
    {
        $media = $this->storeMedia($this->transparentPng(800, 800), 'image/png');

        $this->optimize($media);

        $media->refresh();

        $this->assertSame('image/webp', $media->mime_type);
        $this->assertStringEndsWith('.webp', $media->path);

        // Sol ust cerik seffaf uretildi, sag alt opak.
        $this->assertGreaterThan(100, $this->alphaAt($media, 10, 10));
        $this->assertLessThan(10, $this->alphaAt($media, 790, 790));
    }

    // -------------------------------------------------- YAPILAMAYAN ISLER

    /**
     * 🔴 Dogrulamadaki `dimensions` kuralinin ikizi. Kural bugun her yuklemeyi
     * eliyor; bu satir kuraldan gecmemis satirlar icin duruyor (kural
     * eklenmeden once yuklenmis dosyalar, elle acilmis isler). Cozme 4
     * bayt/piksel ister; sinirsiz bir gorsel isciyi oldururdu.
     */
    #[Test]
    public function an_image_above_the_processing_limit_is_left_untouched(): void
    {
        $limit = Config::integer('davetkart.media.optimize.max_dimension_px');

        $contents = $this->photoJpeg(max(1, $limit + 1), 10);
        $media = $this->storeMedia($contents, 'image/jpeg');
        $path = $media->path;

        $this->optimize($media);

        $media->refresh();

        $this->assertSame($path, $media->path);
        $this->assertSame($contents, Storage::disk($this->disk())->get($path));
        $this->assertNotNull($media->optimized_at);
    }

    /** Bozuk dosya bir KULLANICI hatasi degil; is loglar, damgalar ve gecer. */
    #[Test]
    public function an_unreadable_file_is_stamped_and_left_untouched(): void
    {
        $media = $this->storeMedia('bu bir gorsel degil', 'image/jpeg');

        $this->optimize($media);

        $media->refresh();

        $this->assertNotNull($media->optimized_at);
        $this->assertSame('bu bir gorsel degil', Storage::disk($this->disk())->get($media->path));
    }

    /**
     * Dosya yoksa DAMGA DA ATILMAZ: "yapilacak is kalmadi" ile "is tamamlandi"
     * ayni sey degil. Dosya geri gelirse (yedek, elle kopyalama) is yeniden
     * kosabilsin.
     */
    #[Test]
    public function a_missing_file_is_not_stamped(): void
    {
        $media = Media::factory()->create();

        $this->optimize($media);

        $this->assertNull($media->refresh()->optimized_at);
    }

    /** Video bu isin konusu degil: transcode ffmpeg ister, ayri bir is. */
    #[Test]
    public function a_video_is_never_processed(): void
    {
        $media = Media::factory()->rsvpVideo()->create();
        Storage::disk($media->disk)->put($media->path, 'sahte video');

        $this->optimize($media);

        $this->assertNull($media->refresh()->optimized_at);
        $this->assertSame('sahte video', Storage::disk($media->disk)->get($media->path));
    }

    // ----------------------------------------------------------- IDEMPOTANS

    /** Kuyruk "en az bir kez" teslim eder: ikinci kosu hicbir sey degistirmemeli. */
    #[Test]
    public function optimize_job_is_idempotent(): void
    {
        $media = $this->storeMedia($this->photoJpeg(2400, 1600), 'image/jpeg');

        $this->optimize($media);

        $media->refresh();
        $path = $media->path;
        $size = $media->size_bytes;
        $files = Storage::disk($this->disk())->allFiles();

        $this->optimize($media);

        $media->refresh();

        $this->assertSame($path, $media->path);
        $this->assertSame($size, $media->size_bytes);
        $this->assertSame($files, Storage::disk($this->disk())->allFiles());
    }

    // ---------------------------------------------------------------- BELLEK

    /**
     * 🔴 Isci UZUN OMURLU bir surectir: bir sonraki is bu yukseltmeyi miras
     * almamali, yoksa kacak bir bellek tuketimi 512 MB'a kadar sessizce
     * buyuyebilir.
     */
    #[Test]
    public function the_memory_limit_is_restored_after_the_job(): void
    {
        $original = ini_get('memory_limit');
        ini_set('memory_limit', '256M');

        try {
            $media = $this->storeMedia($this->photoJpeg(2400, 1600), 'image/jpeg');

            $this->optimize($media);

            $this->assertSame('256M', ini_get('memory_limit'));
        } finally {
            ini_set('memory_limit', $original === false ? '-1' : $original);
        }
    }

    // ------------------------------------------------------- YENI YOL / YARIS

    /**
     * 🔴 Satir, is calisirken silinmis olabilir (galeriden silme yuklemeden
     * saniyeler sonra gelir). O zaman yazilan yeni dosya SAHIPSIZ kalirdi;
     * telafi onu siler.
     *
     * Not: kuyrukta bu is `deleteWhenMissingModels` sayesinde hic baslamazdi.
     * Test `handle()`'i dogrudan cagirarak yarisin ORTASINI taklit ediyor:
     * dosya okundu, satir gitti.
     */
    #[Test]
    public function a_row_deleted_mid_flight_leaves_no_file_behind(): void
    {
        Queue::fake();

        $media = $this->storeMedia($this->photoJpeg(2400, 1600), 'image/jpeg');
        $path = $media->path;

        Media::query()->whereKey($media->getKey())->delete();

        $this->optimize($media);

        $this->assertSame([$path], Storage::disk($this->disk())->allFiles());
        Queue::assertNothingPushed();
    }

    /**
     * Misafir onbellegi galeri URL'lerini tasiyor; temizlenmezse yeni yol TTL
     * dolana kadar gorunmez ve eski URL silinmis dosyayi gosterir.
     */
    #[Test]
    public function a_gallery_swap_refreshes_the_public_invitation(): void
    {
        Event::fake([InvitationChanged::class]);

        $media = $this->storeMedia($this->photoJpeg(2400, 1600), 'image/jpeg');

        $this->optimize($media);

        Event::assertDispatched(InvitationChanged::class);
    }

    /** LCV fotografi misafir sayfasinda gorunmez: temizlenecek onbellek yok. */
    #[Test]
    public function an_rsvp_photo_swap_does_not_refresh_the_invitation(): void
    {
        Event::fake([InvitationChanged::class]);

        $media = $this->storeMedia($this->photoJpeg(2400, 1600), 'image/jpeg', MediaKind::RsvpPhoto);

        $this->optimize($media);

        $this->assertNotNull($media->refresh()->optimized_at);
        Event::assertNotDispatched(InvitationChanged::class);
    }

    /**
     * Eski dosya HEMEN silinmez: editordeki sekme yukleme yanitindan gelen eski
     * URL'i elinde tutuyor.
     */
    #[Test]
    public function the_replaced_file_is_scheduled_for_deletion(): void
    {
        Queue::fake();

        $media = $this->storeMedia($this->photoJpeg(2400, 1600), 'image/jpeg');
        $previousPath = $media->path;

        $this->optimize($media);

        Queue::assertPushed(
            DeleteReplacedMediaFile::class,
            fn (DeleteReplacedMediaFile $job): bool => $job->path === $previousPath
                && $job->disk === $media->disk
                && $job->delay !== null,
        );

        // Silme gecikmeli oldugu icin dosya HALA yerinde.
        Storage::disk($this->disk())->assertExists($previousPath);
    }

    #[Test]
    public function the_cleanup_job_deletes_a_file_no_row_points_to(): void
    {
        $path = 'media/gallery/eski.jpg';
        Storage::disk($this->disk())->put($path, 'eski icerik');

        (new DeleteReplacedMediaFile($this->disk(), $path))->handle();

        Storage::disk($this->disk())->assertMissing($path);
    }

    /**
     * 🔴 Is yalnizca ARTIK KULLANILMAYAN dosyayi siler. Gecikme boyunca her sey
     * olabilir: optimizasyon geri alinmis ya da ayni yol baska bir satira
     * yazilmis olabilir.
     */
    #[Test]
    public function the_cleanup_job_keeps_a_file_that_is_still_in_use(): void
    {
        $media = Media::factory()->create();
        Storage::disk($media->disk)->put($media->path, 'yasayan icerik');

        (new DeleteReplacedMediaFile($media->disk, $media->path))->handle();

        Storage::disk($media->disk)->assertExists($media->path);
    }

    // ------------------------------------------------------------- YARDIMCI

    private function optimize(Media $media): void
    {
        (new OptimizeUploadedImage($media))->handle();
    }

    private function disk(): string
    {
        return Config::string('davetkart.media.disk');
    }

    /** Diske gercek bir dosya + onu gosteren bir satir. */
    private function storeMedia(
        string $contents,
        string $mimeType,
        MediaKind $kind = MediaKind::Gallery,
    ): Media {
        $extension = match ($mimeType) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'jpg',
        };

        $path = 'media/'.$kind->value.'/'.Str::random(40).'.'.$extension;

        Storage::disk($this->disk())->put($path, $contents);

        return Media::factory()->create([
            'invitation_id' => Invitation::factory(),
            'kind' => $kind,
            'disk' => $this->disk(),
            'path' => $path,
            'mime_type' => $mimeType,
            'size_bytes' => strlen($contents),
        ]);
    }

    /** @return array{0: int, 1: int} */
    private function dimensionsOf(Media $media): array
    {
        $contents = Storage::disk($media->disk)->get($media->path);
        $this->assertIsString($contents);

        $size = getimagesizefromstring($contents);
        $this->assertIsArray($size);

        return [(int) $size[0], (int) $size[1]];
    }

    /** GD'de alfa 0 (opak) ile 127 (tam seffaf) arasindadir. */
    private function alphaAt(Media $media, int $x, int $y): int
    {
        $contents = Storage::disk($media->disk)->get($media->path);
        $this->assertIsString($contents);

        $image = imagecreatefromstring($contents);
        $this->assertNotFalse($image);

        return (imagecolorat($image, $x, $y) >> 24) & 0x7F;
    }

    /**
     * Gercek bir fotografi temsil eden JPEG.
     *
     * 🔴 Duz renkli bir kare KULLANILAMAZ: JPEG onu birkac yuz bayta indirir,
     * "kuculdu mu" sorusu anlamsizlasir ve kalite basamagi hic tetiklenmez.
     *
     * @param  positive-int  $width
     * @param  positive-int  $height
     */
    private function photoJpeg(int $width, int $height, int $quality = 92): string
    {
        $image = imagecreatetruecolor($width, $height);

        for ($y = 0; $y < $height; $y++) {
            $color = imagecolorallocate(
                $image,
                $this->channel(255 * $y / $height),
                $this->channel(128 + 100 * sin($y / 97)),
                $this->channel(255 - 255 * $y / $height),
            );

            if ($color !== false) {
                imageline($image, 0, $y, $width - 1, $y, $color);
            }
        }

        for ($i = 0, $dots = (int) ($width * $height / 80); $i < $dots; $i++) {
            imagesetpixel($image, random_int(0, $width - 1), random_int(0, $height - 1), random_int(0, 0xFFFFFF));
        }

        return $this->capture(fn (): bool => imagejpeg($image, null, $quality));
    }

    /**
     * Renk kanalini gecerli araliga sikistirir.
     *
     * @return int<0, 255>
     */
    private function channel(float $value): int
    {
        return max(0, min(255, (int) $value));
    }

    /**
     * Sol ust cerigi SEFFAF, geri kalani opak bir PNG.
     *
     * @param  positive-int  $width
     * @param  positive-int  $height
     */
    private function transparentPng(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagealphablending($image, false);
        imagesavealpha($image, true);

        $opaque = imagecolorallocatealpha($image, 200, 40, 40, 0);
        $clear = imagecolorallocatealpha($image, 0, 0, 0, 127);

        if ($opaque !== false && $clear !== false) {
            imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, $opaque);
            imagefilledrectangle($image, 0, 0, intdiv($width, 2) - 1, intdiv($height, 2) - 1, $clear);
        }

        return $this->capture(fn (): bool => imagepng($image));
    }

    /**
     * JPEG'e yalnizca Orientation etiketi tasiyan bir EXIF (APP1) blogu ekler.
     *
     * Elle kuruluyor: GD EXIF YAZAMAZ, depoya ikili bir ornek dosya koymak da
     * testin neyi sinadigini gizlerdi. Yapi: APP1 isaretcisi + "Exif\0\0" +
     * TIFF basligi (little-endian) + tek girdili IFD0.
     */
    private function withExifOrientation(string $jpeg, int $orientation): string
    {
        $tiff = "II\x2a\x00\x08\x00\x00\x00"   // little-endian, IFD0 ofseti 8
            ."\x01\x00"                          // girdi sayisi: 1
            ."\x12\x01"                          // etiket 0x0112 (Orientation)
            ."\x03\x00"                          // tip 3 (SHORT)
            ."\x01\x00\x00\x00"                  // sayi 1
            .pack('v', $orientation)."\x00\x00"  // deger + dolgu
            ."\x00\x00\x00\x00";                 // sonraki IFD yok

        $payload = "Exif\x00\x00".$tiff;
        $segment = "\xff\xe1".pack('n', strlen($payload) + 2).$payload;

        // Segment SOI (FFD8) isaretcisinin HEMEN ardina giriyor.
        return substr($jpeg, 0, 2).$segment.substr($jpeg, 2);
    }

    /**
     * GD'nin dogrudan ciktiya yazdigi baytlari yakalar.
     *
     * @param  callable(): bool  $writer
     */
    private function capture(callable $writer): string
    {
        ob_start();
        $written = $writer();
        $bytes = (string) ob_get_clean();

        $this->assertTrue($written);
        $this->assertNotSame('', $bytes);

        return $bytes;
    }
}
