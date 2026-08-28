<?php

declare(strict_types=1);

namespace App\Http\Requests\Media;

use App\Enums\MediaKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
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
            ],
        ];
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
