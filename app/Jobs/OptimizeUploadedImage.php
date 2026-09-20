<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Events\InvitationChanged;
use App\Models\Invitation;
use App\Models\Media;
use GdImage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Yuklenmis bir gorseli kuyrukta kucultur.
 *
 * 🔴 "15 SANIYE KURALI" (CLAUDE.md §4): istege HEMEN cevap verilir, uzun suren
 * is ana HTTP surecini bekletmez. Frontend'in axios timeout'u 15 saniye; bir
 * gorseli yeniden kodlamak buyuk dosyalarda saniyeler surer ve kullanici
 * yukleme ekraninda beklerdi.
 *
 * Bu yuzden Action dosyayi diske yazip URL'i DONER; kucultme sonra olur.
 *
 * 🔴 Faz 9'da sinir 15 MB'a cikti ve is, gelen her seyi karsilayacak sekilde
 * sertlestirildi. Dort esas karar:
 *   1. Sikistirmayi ONCE TARAYICI yapar (frontend utils/compressImage.ts).
 *      Buraya gelen dosya genelde zaten hedeftedir; o durumda is gorseli HIC
 *      COZMEZ, yalnizca basligi okuyup damgasini atar (kisa yol).
 *   2. Bellek bir YAPILANDIRMADIR, umut degil: GD piksel basina ~4 bayt ister
 *      (24 MP ≈ 99 MB, olculdu) ve CLI varsayilani 128M'dir. Is, isini
 *      yaparken sinirini yukseltir ve BITINCE geri indirir.
 *   3. EXIF yonu UYGULANIR. GD meta veriyi atar; uygulanmasaydi dikey telefon
 *      fotograflari kucultmeden sonra yan yatmis gorunurdu (tarayicilar
 *      orijinali EXIF'e bakarak dogru cevirdigi icin hata ancak biz dosyaya
 *      dokununca ortaya cikardi).
 *   4. Cikti AYNI dosyanin uzerine yazilmaz, YENI bir yola yazilir ve satir
 *      davetiye kilidi altinda o yola cevrilir. Gerekce: swapFile().
 *
 * ⚠️ `optimized_at` "bayt sayisi azaldi" demek DEGIL, "optimizasyon gecisi
 * TAMAMLANDI" demek. Zaten hedefte olan bir gorselde hicbir sey degismeyebilir.
 * Ayrintili aciklama: docs/rehber/app/Jobs/OptimizeUploadedImage.md
 */
final class OptimizeUploadedImage implements ShouldQueue
{
    use Queueable;

    /** Kalite, hedefe inene kadar bu adimla dusurulur. */
    private const QUALITY_STEP = 10;

    /** EXIF APP1 blogunun JPEG'in basinda arandigi pencere. */
    private const EXIF_SCAN_BYTES = 65_536;

    /**
     * Gecici hatalar (disk mesgul, bellek) icin uc deneme; sonra failed_jobs.
     * Sinirsiz deneme, bozuk bir dosya yuzunden kuyrugu tikardi.
     */
    public int $tries = 3;

    /**
     * Dosya is kosmadan SILINMISSE is sessizce duser.
     *
     * Galeriden silme (DeleteGalleryMediaAction) yuklemeden saniyeler sonra
     * gelebilir. Bu bayrak olmasaydi model yeniden okunamaz, is uc kez
     * ModelNotFoundException firlatir ve failed_jobs'a gercek bir hata gibi
     * yazilirdi — oysa ortada kucultulecek bir dosya kalmamistir.
     */
    public bool $deleteWhenMissingModels = true;

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

        $contents = Storage::disk($this->media->disk)->get($this->media->path);

        if ($contents === null) {
            // Dosya silinmis olabilir (davetiye silindi, elle temizlik).
            // Hata degil: yapilacak is kalmadi. DAMGA DA ATILMAZ — dosya geri
            // gelirse (ornegin yedekten) is yeniden calisabilsin.
            Log::info('Optimize atlandi: dosya bulunamadi.', ['media' => $this->media->id]);

            return;
        }

        // 3) Once BASLIK, sonra icerik. getimagesizefromstring yalnizca ilk
        // baytlari okur: boyutlari cozmeden ogrenmek, hem kisa yolu hem bellek
        // korumasini mumkun kilan sey budur.
        $header = @getimagesizefromstring($contents);

        if ($header === false) {
            Log::warning('Gorsel cozulemedi.', ['media' => $this->media->id]);
            $this->stamp();

            return;
        }

        [$width, $height] = [(int) $header[0], (int) $header[1]];

        // 4) 🔴 PIKSEL KORUMASI — dogrulamadaki `dimensions` kuralinin ikizi.
        // Kural bugun her yuklemeyi eliyor; bu satir onun ARKASINDA duruyor
        // cunku kuyrukta o kuraldan gecmemis satirlar olabilir: kural
        // eklenmeden once yuklenmis dosyalar, elle acilmis isler, yarin
        // eklenecek baska bir yukleme yolu. Cozme burada 4 bayt/piksel ister;
        // sinirsiz bir gorsel isciyi bellek hatasiyla oldururdu.
        $maxDimension = Config::integer('davetkart.media.optimize.max_dimension_px');

        if ($width > $maxDimension || $height > $maxDimension) {
            Log::warning('Gorsel cozunurlugu isleme sinirinin ustunde; optimize atlandi.', [
                'media' => $this->media->id,
                'width' => $width,
                'height' => $height,
                'max_dimension_px' => $maxDimension,
            ]);
            $this->stamp();

            return;
        }

        // 5) KISA YOL: dosya zaten hedefteyse gorseli hic cozmeyiz. Tarayici
        // sikistirmasi calisan her yukleme buradan cikar — yani sunucunun
        // isi tek bir baslik okumasina iner.
        if ($this->alreadyAtTarget($contents, $width, $height)) {
            $this->stamp();

            return;
        }

        $optimized = $this->encodeOptimized($contents, $width, $height);

        if ($optimized === null || ! $this->isWorthWriting($optimized, $contents)) {
            $this->stamp();

            return;
        }

        $this->swapFile($optimized);
    }

    /**
     * Dosya oldugu gibi kalabilir mi?
     *
     * Dort kosul birden: hedef bicimde, hedef boyutun altinda, hedef olcunun
     * altinda ve uzerinde silinecek meta veri yok. Biri bile tutmazsa gorsel
     * cozulur.
     */
    private function alreadyAtTarget(string $contents, int $width, int $height): bool
    {
        if ($this->targetMimeType() !== $this->media->mime_type) {
            return false;
        }

        if ($this->media->size_bytes > $this->targetBytes()) {
            return false;
        }

        if (max($width, $height) > Config::integer('davetkart.media.optimize.max_edge_px')) {
            return false;
        }

        return ! $this->hasExifBlock($contents);
    }

    /**
     * Gorseli cozer, kucultur, yonunu duzeltir ve kodlar.
     *
     * Butun GD isi TEK bir yerde ve bellek butcesinin ICINDE: cozulmus gorsel
     * bu metodun cercevesinden disari cikmaz, dolayisiyla butce geri
     * indirildiginde bellek zaten serbest kalmis olur.
     */
    private function encodeOptimized(string $contents, int $width, int $height): ?OptimizedImage
    {
        return $this->withMemoryLimit(function () use ($contents, $width, $height): ?OptimizedImage {
            $image = @imagecreatefromstring($contents);

            if ($image === false) {
                Log::warning('Gorsel cozulemedi.', ['media' => $this->media->id]);

                return null;
            }

            // Paletli PNG'ler (256 renk) truecolor DEGIL; olcekleyici ve WebP
            // kodlayicisi truecolor ister. Donusum ayni zamanda seffafligi
            // alfa kanalina tasir.
            if (! imageistruecolor($image)) {
                imagepalettetotruecolor($image);
            }

            $this->keepAlpha($image);

            $image = $this->downscale($image, $width, $height);
            $image = $this->applyOrientation($image, $contents);

            return $this->compress($image);
        });
    }

    /**
     * En uzun kenari sinira indirir.
     *
     * 🔴 Asil kazanc burada: genisligi yariya indirmek piksel sayisini DORTTE
     * BIRE dusurur (alan = genislik x yukseklik). Yeniden kodlamanin kalite
     * ayarindan cok daha buyuk bir kazanc.
     *
     * Sinir GENISLIGE degil EN UZUN KENARA uygulanir. Genislik sinirlandiginda
     * 3000x4000'lik dikey bir fotograf 2000x2667 kaliyordu: hedeflenen 4 MP
     * yerine 5,3 MP. Dikey fotograf bir kenar durum degil, telefonla cekilen
     * fotograflarin cogunlugu.
     */
    private function downscale(GdImage $image, int $width, int $height): GdImage
    {
        $maxEdge = Config::integer('davetkart.media.optimize.max_edge_px');
        $longestEdge = max($width, $height);

        if ($longestEdge <= $maxEdge) {
            return $image;
        }

        $ratio = $maxEdge / $longestEdge;

        // 🔴 IMG_TRIANGLE, varsayilan IMG_BILINEAR_FIXED DEGIL. Varsayilan
        // yontem hedef pikseli kaynaktan ORNEKLEYEREK bulur: 4 kat kucultmede
        // aradaki pikselleri hic gormez ve dantel, sac, ince cizgi gibi
        // dokularda tirtik (aliasing) birakir. IMG_TRIANGLE iki gecisli
        // olcekleyiciyi kullanir, yani pencereye giren TUM pikselleri hesaba
        // katar. Olculdu (24 MP -> 2000 px): bilinear 112 ms, IMG_TRIANGLE
        // 370 ms. Aradaki 0,26 saniyeyi kaliteye veriyoruz — is kuyrukta,
        // kimse beklemiyor.
        $resized = imagescale(
            $image,
            max(1, (int) round($width * $ratio)),
            max(1, (int) round($height * $ratio)),
            IMG_TRIANGLE,
        );

        if ($resized === false) {
            // Bellek yetmemis olabilir. Optimize EDEMEMEK, yuklemeyi geri
            // almak icin sebep degil.
            Log::warning('Gorsel kucultulemedi.', ['media' => $this->media->id]);

            return $image;
        }

        $this->keepAlpha($resized);

        return $resized;
    }

    /**
     * EXIF yon etiketini PIKSELLERE uygular.
     *
     * 🔴 Telefonlar fotografi sensorun gordugu gibi kaydeder ve "bunu
     * gosterirken 90 derece cevir" notunu EXIF'e yazar. Tarayicilar bu notu
     * okur, GD OKUMAZ: yeniden kodlanan dosyada hem not silinir hem pikseller
     * donmemis kalir — sonuc yan yatmis bir fotograftir. Hata ancak biz
     * dosyaya dokundugumuzda dogar, yani "optimizasyonun" kendi urettigi bir
     * hatadir.
     *
     * ⚠️ KUCULTMEDEN SONRA cagriliyor: imagerotate goruntunun BIR KOPYASINI
     * daha ayirir. 48 MP'lik kareyi cevirmek 190 MB daha isterdi; 2000 px'e
     * inmis kare icin ayni is 16 MB. En uzun kenar sinirini cevirme
     * degistirmedigi icin sira serbest — maliyet degil.
     *
     * Etiket degerleri (exiftool adlandirmasi) ve GD karsiliklari: imagerotate
     * SAAT YONUNUN TERSINE cevirir, bu yuzden "90 CW" -> 270.
     */
    private function applyOrientation(GdImage $image, string $contents): GdImage
    {
        return match ($this->readOrientation($contents)) {
            2 => $this->flip($image, IMG_FLIP_HORIZONTAL),           // yatay ayna
            3 => $this->rotate($image, 180),                         // 180
            4 => $this->flip($image, IMG_FLIP_VERTICAL),             // dikey ayna
            5 => $this->rotate($this->flip($image, IMG_FLIP_HORIZONTAL), 90),   // ayna + 270 CW
            6 => $this->rotate($image, 270),                         // 90 CW
            7 => $this->rotate($this->flip($image, IMG_FLIP_HORIZONTAL), 270),  // ayna + 90 CW
            8 => $this->rotate($image, 90),                          // 270 CW
            default => $image,
        };
    }

    /**
     * JPEG'in EXIF yon etiketi (1-8); yoksa ya da okunamazsa 1.
     *
     * Baytlar `php://temp` akisina yaziliyor: exif_read_data bir dosya ya da
     * akis ister, elimizdeki ise bellekteki bir metin. `php://temp` 2 MB'a
     * kadar bellekte kalir, sonrasinda kendini gecici dosyaya tasir — yani
     * 15 MB'lik bir fotograf icin ikinci bir 15 MB'lik kopya olusmaz.
     *
     * Susturma (@) bilincli: bozuk EXIF uyari uretir ve bu uyari hem loglari
     * doldurur hem testlerde `failOnWarning` ile suiteyi kirar. Yon
     * okunamiyorsa yapilacak sey zaten cevirmemektir.
     */
    private function readOrientation(string $contents): int
    {
        if ($this->media->mime_type !== 'image/jpeg' || ! function_exists('exif_read_data')) {
            return 1;
        }

        $stream = fopen('php://temp', 'r+b');

        if ($stream === false) {
            return 1;
        }

        try {
            fwrite($stream, $contents);
            rewind($stream);

            $exif = @exif_read_data($stream, 'IFD0');
        } finally {
            fclose($stream);
        }

        if ($exif === false || ! isset($exif['Orientation']) || ! is_numeric($exif['Orientation'])) {
            return 1;
        }

        $orientation = (int) $exif['Orientation'];

        return $orientation >= 1 && $orientation <= 8 ? $orientation : 1;
    }

    /** imagerotate yeni bir gorsel uretir; basarisizsa elimizdekiyle devam. */
    private function rotate(GdImage $image, int $degrees): GdImage
    {
        $rotated = imagerotate($image, $degrees, 0);

        if ($rotated === false) {
            Log::warning('Gorsel cevrilemedi.', ['media' => $this->media->id]);

            return $image;
        }

        $this->keepAlpha($rotated);

        return $rotated;
    }

    /** imageflip YERINDE calisir, yeni gorsel uretmez. */
    private function flip(GdImage $image, int $mode): GdImage
    {
        imageflip($image, $mode);

        return $image;
    }

    /**
     * Seffaflik korunsun.
     *
     * Iki bayrak birlikte gerekir: alfa karistirmasi ACIK kalirsa seffaf
     * pikseller cevirme/olcekleme sirasinda siyahla karisir; `saveAlpha`
     * olmazsa da alfa kanali kodlanirken atilir. Her donusumden sonra
     * tekrarlaniyor cunku bayraklar YENI gorsele miras kalmiyor.
     */
    private function keepAlpha(GdImage $image): void
    {
        imagealphablending($image, false);
        imagesavealpha($image, true);
    }

    /**
     * Hedef boyuta inene kadar kaliteyi basamak basamak dusurur.
     *
     * 2000 px'lik bir fotografi kodlamak JPEG'de ~70 ms, WebP'de ~410 ms
     * (olculdu), yani ikinci bir deneme ucuzdur. Taban `min_quality`: altinda
     * bozulma gorunur hale gelir ve hedefe inmek icin fotografi harcamaya
     * degmez — o dosya buyuk kalir.
     */
    private function compress(GdImage $image): ?OptimizedImage
    {
        $mimeType = $this->targetMimeType();
        $minQuality = Config::integer('davetkart.media.optimize.min_quality');
        $quality = $mimeType === 'image/webp'
            ? Config::integer('davetkart.media.optimize.webp_quality')
            : Config::integer('davetkart.media.optimize.jpeg_quality');

        while (true) {
            $bytes = $this->encode($image, $mimeType, $quality);

            if ($bytes === null) {
                return null;
            }

            if (strlen($bytes) <= $this->targetBytes() || $quality <= $minQuality) {
                return new OptimizedImage($bytes, $mimeType, $this->extensionFor($mimeType));
            }

            $quality = max($minQuality, $quality - self::QUALITY_STEP);
        }
    }

    /**
     * Gorseli baytlara cevirir; kodlayici yoksa null.
     *
     * Cikti tamponu: GD fonksiyonlari dosya yolu null iken DOGRUDAN ciktiya
     * yazar. ob_start/ob_get_clean onu tamamen yakalar — yani T3'un
     * ("testte cikti uretilmez") ihlali degildir.
     *
     * GD ya da bicim destegi yoksa null doner ve is SESSIZCE ama LOGLANARAK
     * gecer: kodlayamamak bir kullanici hatasi degil, bir ortam eksikligidir —
     * yuklemeyi geri almak icin sebep degil. (composer.json artik ext-gd
     * istiyor, yani bu durum yalnizca yanlis derlenmis bir PHP'de gorulur.)
     */
    private function encode(GdImage $image, string $mimeType, int $quality): ?string
    {
        if ($mimeType === 'image/webp' && ! function_exists('imagewebp')) {
            Log::warning('GD WebP destegi yok; gorsel optimizasyonu atlandi.', [
                'media' => $this->media->id,
            ]);

            return null;
        }

        ob_start();

        $written = match ($mimeType) {
            'image/jpeg' => imagejpeg($image, null, $quality),
            'image/webp' => imagewebp($image, null, $quality),
            default => false,
        };

        $bytes = (string) ob_get_clean();

        return $written === true && $bytes !== '' ? $bytes : null;
    }

    /**
     * Yeni dosyayi yazmaya deger mi?
     *
     * Uc sebepten biri yeterli:
     *   1. Bicim degisti (PNG -> WebP). Donusumun sebebi zaten boyut.
     *   2. Dosya kuculdu.
     *   3. 🔴 Kaynakta EXIF vardi. Yeniden kodlanmis cikti meta veri TASIMAZ,
     *      yani konum (GPS) bilgisi silinir. KVKK'ya gore konum kisisel
     *      veridir (CLAUDE.md §3) ve misafirin yukledigi fotograf davetiye
     *      sahibine gosteriliyor. Bu tek kosul icin dosyanin bir miktar
     *      buyumesini kabul ediyoruz: burada eksilen sey bayt degil VERI.
     *
     * Hicbiri yoksa dosyaya DOKUNULMAZ. Yeniden kodlama bazen dosyayi
     * BUYUTUR; "optimizasyon" adi altinda dosyayi buyutmek adin yalan
     * soylemesi olurdu.
     */
    private function isWorthWriting(OptimizedImage $optimized, string $contents): bool
    {
        return $optimized->mimeType !== $this->media->mime_type
            || $optimized->sizeBytes() < $this->media->size_bytes
            || $this->hasExifBlock($contents);
    }

    /**
     * Kuculmus dosyayi YENI bir yola yazar ve satiri o yola cevirir.
     *
     * 🔴 Neden ayni dosyanin uzerine yazilmiyor? Uc sebep:
     *
     *   1. PNG -> WebP'de UZANTI degisiyor. Icerigi WebP olan bir `.png`
     *      dosyasi, uzantisini icerikten ureten yukleme katmanina (4. katman,
     *      StoreUploadedMediaAction) ters duserdi.
     *   2. YARIS: is dosyayi okuduktan sonra fotograf silinirse, uzerine
     *      yazmak dosyayi SAHIPSIZ olarak geri yaratir. Yeni yol + kilitli
     *      satir kontrolu bunu imkansiz kilar; yazilan dosya sahipsiz kalirsa
     *      telafi onu siler.
     *   3. ONBELLEK: CDN ve tarayici ayni URL altinda BUYUK orijinali
     *      tutabilir. Yeni yol, yeni icerige yeni bir adres verir.
     *
     * Kilit DAVETIYE satiri: StoreUploadedMediaAction ve
     * DeleteGalleryMediaAction da ayni ortak kilidi aliyor, boylece "sil" ile
     * "cevir" siraya girer. PostgreSQL'in READ COMMITTED seviyesinde var
     * olmayan satir kilitlenemedigi icin kilitlenebilecek tek ortak nesne UST
     * KAYITTIR (Faz 5 ve 6 ile ayni desen).
     */
    private function swapFile(OptimizedImage $optimized): void
    {
        $disk = $this->media->disk;
        $previousPath = $this->media->path;
        $newPath = $this->newPath($optimized->extension);

        Storage::disk($disk)->put($newPath, $optimized->bytes);

        $invitation = DB::transaction(function () use ($optimized, $previousPath, $newPath): ?Invitation {
            $invitation = Invitation::query()
                ->whereKey($this->media->invitation_id)
                ->lockForUpdate()
                ->first();

            if ($invitation === null) {
                return null;
            }

            // Satir kilit ALTINDA yeniden okunuyor: elimizdeki ornek istegin
            // basindan kalma. Yol degismisse is ikinci kez kosuyor demektir
            // (kuyruk "en az bir kez" teslim eder) ve yapilacak bir sey yok.
            $media = $invitation->media()->whereKey($this->media->getKey())->first();

            if ($media === null || $media->path !== $previousPath) {
                return null;
            }

            $media->path = $newPath;
            $media->mime_type = $optimized->mimeType;
            $media->size_bytes = $optimized->sizeBytes();
            $media->optimized_at = now();
            $media->save();

            return $invitation;
        });

        if ($invitation === null) {
            // 🔴 TELAFI. Diske yazma transaction'a DAHIL DEGILDIR: rollback
            // dosyayi geri almaz. Satir gittiyse yazdigimiz dosyayi elimizle
            // sileriz, yoksa yetim kalirdi (StoreUploadedMediaAction ile ayni
            // refleks).
            Storage::disk($disk)->delete($newPath);

            return;
        }

        // Misafir onbellegi galeri URL'lerini TASIYOR (PublicInvitationResource);
        // temizlenmezse yeni yol TTL dolana kadar gorunmez ve eski URL silinen
        // dosyayi gosterir. `touch()` DEGIL olay: `updated_at` frontend'de
        // "son kaydetme" olarak gosteriliyor, arka plan isi onu kaydirmamali.
        if ($this->media->kind->isGalleryItem()) {
            event(new InvitationChanged($invitation));
        }

        // 🔴 Eski dosya HEMEN silinmez. Editordeki sekme, yukleme yanitindan
        // gelen ESKI URL'i elinde tutuyor; dosya o anda silinse acik sayfada
        // kirik gorsel olurdu. Bekleme suresi sonunda yeni is siler ve
        // silmeden once o yolu gosteren bir satir kalmadigini dogrular.
        DeleteReplacedMediaFile::dispatch($disk, $previousPath)
            ->delay(now()->addHours(
                Config::integer('davetkart.media.optimize.replaced_file_grace_hours'),
            ));
    }

    /**
     * Yeni dosyanin yolu — `store()` ile AYNI ad deseni.
     *
     * Ad yine SUNUCU tarafindan uretiliyor (Str::random(40)); kullanicinin
     * verdigi ad hicbir asamada yola girmez.
     */
    private function newPath(string $extension): string
    {
        return 'media/'.$this->media->kind->value.'/'.Str::random(40).'.'.$extension;
    }

    /**
     * Bu dosyanin HEDEF bicimi.
     *
     * 🔴 PNG'nin karsiligi WebP. PNG kayipsizdir: bir fotograf 2000 px'e
     * indirildikten sonra bile ~3-5 MB kalir (olculdu), yani "2 MB'in altina
     * in" hedefi PNG'de ULASILAMAZ. WebP hem kayipli sikistirir hem alfa
     * kanalini tasir — JPEG'e cevirmek seffaf gorselleri siyahlatirdi.
     *
     * JPEG ve WebP kendi bicimlerinde kalir: bicim degisimi URL degisimi
     * demek, ve sebebi olmayan bir degisim yalnizca risk.
     */
    private function targetMimeType(): string
    {
        return $this->media->mime_type === 'image/png'
            ? 'image/webp'
            : $this->media->mime_type;
    }

    private function extensionFor(string $mimeType): string
    {
        return match ($mimeType) {
            'image/webp' => 'webp',
            default => 'jpg',
        };
    }

    private function targetBytes(): int
    {
        return Config::integer('davetkart.media.optimize.target_kb') * 1024;
    }

    /**
     * Kaynakta EXIF blogu var mi?
     *
     * JPEG'de APP1 segmenti dosyanin BASINDA durur; ilk 64 KB'a bakmak yeterli
     * ve tam bir EXIF ayristiricisindan cok daha ucuz. Soru iki yerde ayni
     * anlama geliyor: kisa yolda "silinecek meta veri var mi", yazma
     * kararinda "silmek icin yeniden yazmaya deger mi".
     */
    private function hasExifBlock(string $contents): bool
    {
        if ($this->media->mime_type !== 'image/jpeg') {
            return false;
        }

        return str_contains(substr($contents, 0, self::EXIF_SCAN_BYTES), "Exif\0\0");
    }

    /**
     * Bellek butcesini yukseltir, isi kosar ve eski siniri GERI YUKLER.
     *
     * 🔴 Neden kodda, php.ini'de degil? Cunku php.ini makineye aittir, depoya
     * girmez: ekipteki her gelistirici ve her sunucu ayni hatayla yeniden
     * karsilasirdi (phpstan'in memory-limit dersi ile ayni gerekce). Isin
     * ihtiyaci koddan okunabilir olmali.
     *
     * Neden geri yukluyoruz? Isci UZUN OMURLU bir surectir; bir sonraki is bu
     * yukseltmeyi miras alsaydi, kacak bir bellek tuketimi 512 MB'a kadar
     * sessizce buyuyebilirdi. Geri indirme guvenli: cozulmus gorsel
     * $callback'in cercevesiyle birlikte serbest kalir, yani kullanim eski
     * sinirin altina inmis olur (aksi halde ini_set uyari verirdi).
     *
     * @param  callable(): ?OptimizedImage  $callback
     */
    private function withMemoryLimit(callable $callback): ?OptimizedImage
    {
        $budget = Config::string('davetkart.media.optimize.memory_limit');
        $current = ini_get('memory_limit');

        // Mevcut sinir sinirsiz (-1) ya da butcenin ustundeyse dokunmuyoruz:
        // is asla bulundugu ortamin sinirini DUSURMEMELI.
        $shouldRaise = is_string($current)
            && ini_parse_quantity($current) > 0
            && ini_parse_quantity($current) < ini_parse_quantity($budget);

        if (! $shouldRaise) {
            return $callback();
        }

        ini_set('memory_limit', $budget);

        try {
            return $callback();
        } finally {
            ini_set('memory_limit', $current);
        }
    }

    /**
     * "Optimizasyon gecisi tamamlandi" damgasi.
     *
     * Dosya degismemis olsa bile atilir: damga bayt sayisini degil, GECISIN
     * tamamlandigini anlatir. Atilmasaydi ayni dosya her yeni iste yeniden
     * cozulurdu.
     */
    private function stamp(): void
    {
        $this->media->optimized_at = now();
        $this->media->save();
    }
}
