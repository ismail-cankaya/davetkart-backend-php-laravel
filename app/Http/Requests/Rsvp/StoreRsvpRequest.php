<?php

declare(strict_types=1);

namespace App\Http\Requests\Rsvp;

use App\Enums\RsvpStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Config;

/**
 * POST /api/public/invitations/{invitation}/rsvps girdisini dogrular.
 *
 * 🔴 Bu sinif sistemdeki TEK auth'suz yazma yolunun ilk kapisi. Buradan gecen
 * her sey "bicimsel olarak gecerli" sayilir — ama gecerli olmak MESRU olmak
 * demek degildir. Son tarih, modul acikligi ve kota IS KURALIDIR ve Action'da
 * denetlenir (H10 ailesi): FormRequest bicim bilir, is bilmez.
 * Ayrintili aciklama: docs/rehber/app/Http/Requests/Rsvp/StoreRsvpRequest.md
 */
final class StoreRsvpRequest extends FormRequest
{
    /**
     * 🔴 Honeypot (bal kupu) alani.
     *
     * Formda insana GORUNMEZ bir alandir. Insan doldurmaz cunku goremez;
     * otomatik doldurma yapan botlarin cogu her input'u doldurur. Adi bilerek
     * masum ve cazip secildi — 'honeypot' deseydik bot da anlardi.
     */
    public const HONEYPOT_FIELD = 'website';

    /**
     * Istek alani -> veritabani kolonu (D4).
     *
     * ACIKCA yazilir, Str::snake ile TURETILMEZ. Listede olmayan alan sessizce
     * duser.
     *
     * 🔴 photoMediaId / videoMediaId BU HARITAYA GIRMEZ (Faz 6). Sebep:
     * bu haritanin ciktisi $invitation->rsvps()->make($attributes) ile TOPLU
     * ATANIYOR ve toplu atama "bu medya bu davetiyeye mi ait?" sorusunu
     * ATLAR. Kimlikler ayri bir erisimciyle (mediaIds) veriliyor ve Action
     * once dogrulayip sonra acikca yaziyor (N1).
     *
     * @var array<string, string>
     */
    private const COLUMN_MAP = [
        'guestName' => 'guest_name',
        'guestCount' => 'guest_count',
        'status' => 'status',
        'menuPreference' => 'menu_preference',
        'message' => 'message',
    ];

    /** Uc herkese acik; yetki karari yok. Gorunurluk kurali Action'da. */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            // Misafir formu TEK SEFERDE gonderir; autosave yok, dolayisiyla
            // 'sometimes' yok. Yarim LCV diye bir sey olmamali.
            'guestName' => ['required', 'string', 'min:2', 'max:120'],

            // 🔴 Ust sinir CONFIG'ten: kisit degil is tercihi (E6). max kurali
            // 'max' parametresini yanita verir ve H9 beyaz listesi buna izin
            // verir — kullanici zaten formda goruyor.
            'guestCount' => [
                'required',
                'integer',
                'min:1',
                'max:'.Config::integer('davetkart.rsvp.max_guests_per_entry'),
            ],

            // 🔴 D6: Rule::enum() KULLANILMIYOR. Kural nesnesi hataya SINIF ADIYLA
            // raporlanir (illuminate_validation_rules_enum) ve framework adi
            // sozlesmeye sizar — Faz 3'te Password::min(8) ile yasandi.
            // 'in' kurali hem sabit bir ad hem de gecerli degerleri verir.
            'status' => ['required', 'string', 'in:'.implode(',', RsvpStatus::values())],

            'menuPreference' => ['sometimes', 'nullable', 'string', 'max:60'],
            'message' => ['sometimes', 'nullable', 'string', 'max:1000'],

            // 🔴 Faz 6: misafirin yukledigi medyanin KIMLIGI (URL degil).
            // 'ulid' kurali BICIMI dogrular, VARLIGI degil. 'exists:media,id'
            // yazmiyoruz cunku:
            //   1) Varlik zaten yabanci anahtar kisitiyla garanti (6.17, E2),
            //   2) Asil soru "var mi" degil "BU DAVETIYEYE AIT MI" — bunu bir
            //      dogrulama kurali cevaplayamaz, Action cevaplar (N1),
            //   3) 'exists' bir SORGU acar; bicimsiz kimlik veritabanina hic
            //      gitmemeli (O6'nin ayni gerekcesi).
            'photoMediaId' => ['sometimes', 'nullable', 'string', 'ulid'],
            'videoMediaId' => ['sometimes', 'nullable', 'string', 'ulid'],

            // Honeypot BILEREK kuralsiz: bir kural koysaydik 422 doner ve bota
            // "yakalandin" derdik. Sessizlik bir savunmadir (5.7).
        ];
    }

    /**
     * Dogrulanmis girdiyi Rsvp kolonlarina esler.
     *
     * array_key_exists, isset DEGIL: isset(null) false doner ve kullanici bir
     * alani bosaltamazdi (Faz 3, 3.8'in ayni gerekcesi).
     *
     * @return array<string, mixed>
     */
    public function rsvpAttributes(): array
    {
        /** @var array<string, mixed> $data */
        $data = $this->validated();

        $attributes = [];

        foreach (self::COLUMN_MAP as $field => $column) {
            if (array_key_exists($field, $data)) {
                $attributes[$column] = $data[$field];
            }
        }

        return $attributes;
    }

    /**
     * Misafirin ilistirdigi medya kimlikleri — DOGRULANMAMIS.
     *
     * 🔴 Donen degerler yalnizca BICIMSEL olarak gecerlidir (ULID). Hangi
     * davetiyeye ait olduklari burada BILINMIYOR ve bilinemez: FormRequest
     * bicim bilir, is bilmez (H10 ailesi). Aidiyeti SubmitRsvpAction sorar.
     *
     * Ayri bir erisimci olmasinin sebebi rsvpAttributes()'tan uzak tutmak:
     * o dizi toplu atamaya gidiyor ve toplu atama dogrulamayi atlar.
     *
     * @return array{photo: ?string, video: ?string}
     */
    public function mediaIds(): array
    {
        /** @var array<string, mixed> $data */
        $data = $this->validated();

        $photo = $data['photoMediaId'] ?? null;
        $video = $data['videoMediaId'] ?? null;

        return [
            'photo' => is_string($photo) ? $photo : null,
            'video' => is_string($video) ? $video : null,
        ];
    }

    /**
     * Gorunmez alan dolduruldu mu?
     *
     * KARARI VERMEZ, yalnizca OLGUYU bildirir: ne yapilacagi (sessizce basarili
     * gorunmek) bir is kuralidir ve Action'a aittir.
     *
     * validated() yerine input() okunuyor cunku alanin dogrulama kurali yok —
     * D2'nin tersi bir durum degil: burada okunan sey bir DEGER degil, bir
     * VARLIK/YOKLUK sinyali; icerigine hicbir yerde guvenilmiyor.
     */
    public function isHoneypotTripped(): bool
    {
        $value = $this->input(self::HONEYPOT_FIELD);

        // ConvertEmptyStringsToNull global middleware'i '' degerini null yapar,
        // yani bos gonderen durustler burada da elenmez.
        return $value !== null && $value !== [];
    }
}
