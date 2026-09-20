<?php

declare(strict_types=1);

namespace App\Http\Requests\Media;

use App\Enums\MediaKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use LogicException;

/**
 * Sahibin ve misafirin yukleme isteklerinin ortak kural tabani.
 *
 * 🔴 Kurallar SABIT DEGIL, gelen `kind` degerine gore hesaplanir: her turun
 * kendi boyut ve MIME siniri var (MediaKind). Bu yuzden rules() once turu
 * cozmek zorunda — ve o cozum D2 geregi SAVUNMACI yapilir: dogrulamadan ONCE
 * okunan girdiye guvenilmez.
 *
 * Alt siniflar yalnizca "hangi turler serbest" sorusunu cevaplar; bu, en az
 * ayricalik (least privilege) ilkesinin tek satirlik ifadesidir.
 * Ayrintili aciklama: docs/rehber/app/Http/Requests/Media/MediaRequest.md
 */
abstract class MediaRequest extends FormRequest
{
    /** Yetki karari Policy'nin/controller'in isi; burada degil. */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        $kind = $this->resolveKind();

        return [
            // 🔴 D6: Rule::enum() DEGIL 'in:' — kural nesnesi hataya sinif
            // adiyla raporlanir ve framework adi sozlesmeye sizar.
            'kind' => ['required', 'string', 'in:'.implode(',', $this->allowedKinds())],

            'file' => [
                'required',
                'file',

                // Laravel'de `max:` dosya kurallarinda KILOBAYT'tir.
                'max:'.$kind->maxSizeKb(),

                // 🔴 'mimes:' DEGIL 'mimetypes:'. mimes uzantiya bakar;
                // mimetypes dosyanin ICERIGINDEN okunan tipe bakar (finfo).
                // Uzantiyi kullanici belirler, icerigi belirleyemez.
                'mimetypes:'.implode(',', $kind->allowedMimeTypes()),

                ...$this->dimensionRules($kind),
            ],
        ];
    }

    /**
     * 🔴 PHP'nin KENDI sinirini gorunur kilar.
     *
     * `upload_max_filesize` asilirsa PHP dosyayi istek BASLARKEN atar. Laravel'e
     * yalnizca hata kodu tasiyan bos bir dosya nesnesi ulasir ve dogrulama
     * 'uploaded' kuraliyla 422 doner: "dosya yuklenemedi". Mesaj dogru ama
     * SEBEBI soylemiyor; sunucunun sinirinin uygulamanin sinirindan dusuk
     * oldugu hicbir yerde gorunmuyor. Bu proje o hatayi bir kez yasadi.
     *
     * Yanit sozlesmesi DEGISMIYOR (hala 422): burada eklenen sey yalnizca
     * teshis. Yapilandirma hatasini kullaniciya anlatmanin bir yolu yok, ama
     * loga yazmanin maliyeti de yok.
     */
    protected function prepareForValidation(): void
    {
        $file = $this->file('file');

        if (! $file instanceof UploadedFile) {
            return;
        }

        if (! in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            return;
        }

        $kind = $this->resolveKind();

        Log::warning('Dosya PHP sinirina takildi: php.ini degeri uygulama sinirindan dusuk.', [
            'kind' => $kind->value,
            'app_max_size_kb' => $kind->maxSizeKb(),
            'php_upload_max_filesize' => ini_get('upload_max_filesize'),
            'php_post_max_size' => ini_get('post_max_size'),
        ]);
    }

    /**
     * Gorsel turlerde PIKSEL ust siniri.
     *
     * 🔴 Dosya boyutu sinirinin yerine gecmez; baska bir seyi olcer. 50 KB'lik
     * bir PNG 20000x20000 piksel acabilir: `max:15360` kuralindan rahatca
     * gecer, ama kuyruktaki is onu cozmeye kalktiginda ~1,6 GB bellek ister ve
     * isciyi cokertir. Buna "sikistirma bombasi" deniyor ve misafirin yukleme
     * ucu auth'suz — yani bu, kota ya da konfor ayari degil bir SAVUNMA.
     *
     * Hazir `dimensions` kurali kullaniliyor:
     *   - dosyanin yalnizca BASLIGINI okur (getimagesize), yani ucuzdur;
     *   - kural NESNESI degil metin oldugu icin hata `dimensions` adiyla
     *     raporlanir, sozlesmeye sinif adi sizmaz (D6, 'in:' ile ayni gerekce).
     *
     * ⚠️ Videoya EKLENMEZ: getimagesize bir mp4'un boyutlarini okuyamaz ve
     * kural her videoyu reddederdi. Sinir, isin GORSELI cozecegi turler icin
     * var — bu yuzden soru `isOptimizable()`.
     *
     * @return list<string>
     */
    private function dimensionRules(MediaKind $kind): array
    {
        if (! $kind->isOptimizable()) {
            return [];
        }

        $max = Config::integer('davetkart.media.optimize.max_dimension_px');

        return ['dimensions:max_width='.$max.',max_height='.$max];
    }

    /** Dogrulanmis tur — Action bunu kullanir. */
    public function kind(): MediaKind
    {
        /** @var array{kind: string} $validated */
        $validated = $this->validated();

        return MediaKind::from($validated['kind']);
    }

    /**
     * Dogrulanmis dosya.
     *
     * `file()` adi FormRequest'te dolu oldugu icin `uploadedFile()`.
     */
    public function uploadedFile(): UploadedFile
    {
        $file = $this->file('file');

        if (! $file instanceof UploadedFile) {
            // Ulasilamaz olmali: 'required|file' bunu zaten elerdi. Yine de
            // sessizce devam etmek yerine GURULTULU patliyoruz — sessiz bir
            // null, diske bos dosya yazmaya kadar giderdi.
            throw new LogicException('Validated media request has no uploaded file.');
        }

        return $file;
    }

    /**
     * Bu uc noktanin kabul ettigi turler.
     *
     * @return list<string>
     */
    abstract protected function allowedKinds(): array;

    /**
     * Kural hesabi icin turu cozer — dogrulamadan ONCE.
     *
     * 🔴 D2: burada okunan veri GUVENILMEZDIR. `kind[]=x` gonderilirse
     * $raw bir DIZI olur; is_string kontrolu olmasa (string) donusumu
     * TypeError firlatir ve 422 yerine 500 doneriz.
     *
     * Tur gecersizse en DAR sinirlari kullaniriz: 'in:' kurali zaten 422
     * uretecek, ama bu arada dosya kurallarinin gevsek kalmasini istemeyiz.
     */
    private function resolveKind(): MediaKind
    {
        $raw = $this->input('kind');

        $kind = is_string($raw) ? MediaKind::tryFrom($raw) : null;

        if ($kind !== null && in_array($kind->value, $this->allowedKinds(), true)) {
            return $kind;
        }

        return $this->strictestAllowedKind();
    }

    /**
     * Bu uctaki en kucuk boyut sinirina sahip tur.
     *
     * Dongu ile yazildi, sort + [0] ile degil: bos bir liste ihtimalini
     * susturmak yerine ACIKCA reddediyoruz. "Hicbir tur kabul etmeyen bir
     * yukleme ucu" bir yapilandirma hatasidir, sessiz kalmamali.
     */
    private function strictestAllowedKind(): MediaKind
    {
        $strictest = null;

        foreach ($this->allowedKinds() as $value) {
            $kind = MediaKind::from($value);

            if ($strictest === null || $kind->maxSizeKb() < $strictest->maxSizeKb()) {
                $strictest = $kind;
            }
        }

        if ($strictest === null) {
            throw new LogicException('A media request must allow at least one kind.');
        }

        return $strictest;
    }
}
