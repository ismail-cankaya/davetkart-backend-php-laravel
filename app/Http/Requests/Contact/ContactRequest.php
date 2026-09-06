<?php

declare(strict_types=1);

namespace App\Http\Requests\Contact;

use App\Enums\ContactSubject;
use App\Http\Requests\Concerns\HasHoneypot;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Config;

/**
 * POST /api/contact govdesini dogrular.
 *
 * 🔴 Bu sinif sistemin DORDUNCU auth'suz yazma yolunun ilk kapisi.
 * Oncekiler: LCV (Faz 5), misafir medyasi (Faz 6), odeme webhook'u (Faz 7).
 *
 * L1'e gore siralanmis katmanlar — en ucuzdan pahaliya:
 *   0. throttle:contact -> rota katmani; IP basina 3/dk ve 10/saat
 *   1. Honeypot         -> bot SESSIZCE yutulur, veritabanina HIC gidilmez
 *   2. Bicim            -> burada
 *   3. Yazma + KVKK     -> SubmitContactAction
 *
 * Webhook'tan farki, honeypot'un MUMKUN olmasi: orada gorunmez bir alan
 * diye bir sey yoktu (gonderen bir makineydi ve savunma imzaydi), burada
 * gonderen bir tarayici formudur.
 * Ayrintili aciklama: docs/rehber/app/Http/Requests/Contact/ContactRequest.md
 */
final class ContactRequest extends FormRequest
{
    // Honeypot alani ve okuyucusu Faz 5'ten devralindi (8.7'de trait'e
    // cikarildi): ayni tuzak, ikinci form, TEK tanim (C3).
    use HasHoneypot;

    /**
     * Istek alani -> veritabani kolonu (D4).
     *
     * Adlar bugun BIREBIR ayni ve harita gereksiz gorunuyor. Yine de
     * yaziliyor, cunku bu dizinin isi eslemek DEGIL BEYAZ LISTELEMEK: yarin
     * kurallara bir alan eklendiginde (ornegin bir onay kutusu) o alan
     * kolona SESSIZCE sizmasin. StoreRsvpRequest'teki ayni gerekce.
     *
     * @var array<string, string>
     */
    private const COLUMN_MAP = [
        'name' => 'name',
        'email' => 'email',
        'subject' => 'subject',
        'message' => 'message',
    ];

    /** Uc herkese acik; yetki karari yok. */
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
            'name' => ['required', 'string', 'min:2', 'max:120'],

            // 'email' kurali BICIMI dogrular, adresin VAR OLDUGUNU degil.
            // Dogrulamanin dogru siniri bu: var olan bir adres olup olmadigi
            // ancak bir posta gonderilerek ogrenilir ve bu uc posta gondermez.
            'email' => ['required', 'string', 'email', 'max:255'],

            // 🔴 D6: Rule::enum() KULLANILMIYOR. Kural NESNESI hataya SINIF
            // ADIYLA raporlanir (illuminate_validation_rules_enum) ve
            // framework adi hata zarfina sizar. Kural ADI sozlesmenin
            // parcasidir — frontend 'in' anahtarina gore ceviri yazar.
            'subject' => ['required', 'string', 'in:'.implode(',', ContactSubject::values())],

            // Ust sinir config'ten: kisit degil IS TERCIHI (E6).
            'message' => [
                'required',
                'string',
                'max:'.Config::integer('davetkart.contact.max_message_chars'),
            ],

            // Honeypot BILEREK kuralsiz: bir kural koysaydik 422 doner ve
            // bota "yakalandin" derdik. Sessizlik bir savunmadir (L2).
        ];
    }

    /**
     * Dogrulanmis girdiyi ContactMessage kolonlarina esler.
     *
     * @return array<string, mixed>
     */
    public function contactAttributes(): array
    {
        /** @var array<string, mixed> $data */
        $data = $this->validated();

        $attributes = [];

        foreach (self::COLUMN_MAP as $field => $column) {
            // array_key_exists, isset DEGIL: isset(null) false doner
            // (Faz 3, 3.8'in ayni gerekcesi).
            if (array_key_exists($field, $data)) {
                $attributes[$column] = $data[$field];
            }
        }

        return $attributes;
    }
}
