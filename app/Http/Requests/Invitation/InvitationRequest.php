<?php

declare(strict_types=1);

namespace App\Http\Requests\Invitation;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Store ve Update isteklerinin ortak kural tabani.
 *
 * D1: kurallar istegin GONDERDIGI adlarla (camelCase) yazilir.
 * D4: camelCase -> snake_case eslemesi bu katmanda yapilir; Action HTTP alan
 * adlarini bilmez.
 * Ayrintili aciklama: docs/rehber/app/Http/Requests/Invitation/InvitationRequest.md
 */
abstract class InvitationRequest extends FormRequest
{
    /**
     * Istek alani -> veritabani kolonu. ACIKCA yazilir, turetilmez.
     *
     * `showRSVP` sihirli bir donusumle `show_rsvp` olmaz (Str::snake onu
     * `show_r_s_v_p` yapar). Listede olmayan alan (phoneBackground,
     * galleryImages) sessizce dusser — beyaz liste (C1).
     *
     * @var array<string, string>
     */
    private const COLUMN_MAP = [
        'categoryId' => 'category_id',
        'imageTheme' => 'preset_id',
        'palette' => 'palette',
        'title' => 'title',
        'subtitle' => 'subtitle',
        'names' => 'names',
        'venue' => 'venue',
        'mapUrl' => 'map_url',
        'date' => 'event_at',
        'timezone' => 'timezone',
        'showEnvelope' => 'show_envelope',
        'showTimer' => 'show_timer',
        'showTimeline' => 'show_timeline',
        'showGallery' => 'show_gallery',
        'showGift' => 'show_gift',
        'showRSVP' => 'show_rsvp',
        'bankName' => 'bank_name',
        'accountHolder' => 'account_holder',
        'iban' => 'iban',
        'giftOptions' => 'gift_options',
        'rsvpDeadline' => 'rsvp_deadline',
        'askMenuPreference' => 'ask_menu_preference',
    ];

    /** Yetki karari Policy'nin isi; controller authorizeResource ile cagirir. */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        $catalog = $this->catalogPresence();

        return [
            'invitation' => ['required', 'array'],

            // Katalog anahtarlari: icerik dogrulamasi YOK, yalnizca uzunluk.
            // Gecerli deger listesi frontend'in malidir (3.2 §4).
            'invitation.categoryId' => [...$catalog, 'string', 'max:32'],
            'invitation.imageTheme' => [...$catalog, 'string', 'max:48'],
            'invitation.palette' => [...$catalog, 'string', 'max:16'],

            // Icerik: autosave yarim veri gonderir — hicbiri zorunlu degil.
            // Eksiksizlik yayin aninda aranir (Faz 7).
            'invitation.title' => ['sometimes', 'nullable', 'string', 'max:120'],
            'invitation.subtitle' => ['sometimes', 'nullable', 'string', 'max:255'],
            'invitation.names' => ['sometimes', 'nullable', 'string', 'max:120'],
            'invitation.venue' => ['sometimes', 'nullable', 'string', 'max:180'],
            'invitation.mapUrl' => ['sometimes', 'nullable', 'string', 'url', 'max:2048'],
            'invitation.date' => ['sometimes', 'nullable', 'date'],

            // K63: IANA saat dilimi kimligi. 'timezone' kurali degeri PHP'nin
            // kayitli listesine karsi dogrular — uydurma bir deger ('TR+3')
            // veritabanina hic ulasmaz. Kural ADI sozlesmenin parcasidir (D6):
            // hata zarfina {"rule":"timezone"} diye cikar, sinif adi sizmaz.
            'invitation.timezone' => ['sometimes', 'nullable', 'string', 'timezone', 'max:64'],

            'invitation.showEnvelope' => ['sometimes', 'boolean'],
            'invitation.showTimer' => ['sometimes', 'boolean'],
            'invitation.showTimeline' => ['sometimes', 'boolean'],
            'invitation.showGallery' => ['sometimes', 'boolean'],
            'invitation.showGift' => ['sometimes', 'boolean'],
            'invitation.showRSVP' => ['sometimes', 'boolean'],

            'invitation.bankName' => ['sometimes', 'nullable', 'string', 'max:80'],
            'invitation.accountHolder' => ['sometimes', 'nullable', 'string', 'max:120'],
            'invitation.iban' => ['sometimes', 'nullable', 'string', 'max:34'],
            'invitation.giftOptions' => ['sometimes', 'nullable', 'array', 'max:10'],
            'invitation.giftOptions.*' => ['integer', 'min:0', 'max:1000000'],

            'invitation.rsvpDeadline' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'invitation.askMenuPreference' => ['sometimes', 'boolean'],

            // K44: id STRING'dir ve taninmayan deger hata degil, YENI satir demektir.
            // Aidiyet kontrolu senkronizasyonda, iliski uzerinden yapilir (3.10).
            'invitation.timelineEvents' => ['sometimes', 'array', 'max:50'],
            'invitation.timelineEvents.*.id' => ['nullable', 'string', 'max:64'],
            'invitation.timelineEvents.*.time' => ['nullable', 'string', 'date_format:H:i'],
            'invitation.timelineEvents.*.title' => ['nullable', 'string', 'max:120'],
            'invitation.timelineEvents.*.description' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * Dogrulanmis girdiyi Invitation kolonlarina esler.
     *
     * 🔴 array_key_exists kullaniliyor, isset DEGIL: isset(null) false doner ve
     * kullanici bir alani TEMIZLEYEMEZDI.
     *
     * @return array<string, mixed>
     */
    public function invitationAttributes(): array
    {
        $data = $this->validatedInvitation();
        $attributes = [];

        foreach (self::COLUMN_MAP as $field => $column) {
            if (array_key_exists($field, $data)) {
                $attributes[$column] = $data[$field];
            }
        }

        return $attributes;
    }

    /**
     * Program adimlari — istekte hic yoksa null doner.
     *
     * null ile bos dizi FARKLIDIR: null "dokunma", [] "hepsini sil" demektir.
     *
     * @return list<array<string, mixed>>|null
     */
    public function timelineEvents(): ?array
    {
        $data = $this->validatedInvitation();

        if (! array_key_exists('timelineEvents', $data)) {
            return null;
        }

        /** @var list<array<string, mixed>> $events */
        $events = $data['timelineEvents'];

        return $events;
    }

    /** Store 'required', Update 'sometimes','required' verir. */
    /** @return list<string> */
    abstract protected function catalogPresence(): array;

    /**
     * D5: Action'a giden veri validated()'ten gelir, all()'dan degil.
     *
     * @return array<string, mixed>
     */
    private function validatedInvitation(): array
    {
        /** @var array{invitation?: array<string, mixed>} $validated */
        $validated = $this->validated();

        return $validated['invitation'] ?? [];
    }
}
