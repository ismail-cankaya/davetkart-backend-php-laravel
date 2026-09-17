<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\InvitationStatus;
use App\Enums\MediaKind;
use App\Events\InvitationChanged;
use Database\Factories\InvitationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;

/**
 * Kullanicinin olusturdugu davetiye.
 *
 * 🔴 `user_id`, `status` ve `published_at` BILEREK doldurulabilir DEGIL:
 * sahiplik iliski uzerinden, durum ise yayin akisi tarafindan belirlenir.
 *
 * 🔴 `gallery_media_ids` de doldurulabilir DEGIL: galeri sirasini yalnizca
 * medya Action'lari yazar (yukleme ekler, silme cikarir). Istek govdesinden
 * gelen bir liste baska davetiyenin dosyasina isaret edebilirdi.
 * Ayrintili aciklama: docs/rehber/app/Models/Invitation.md
 */
#[Fillable([
    'category_id', 'preset_id', 'palette',
    'title', 'subtitle', 'names', 'venue', 'map_url', 'event_at', 'timezone',
    'show_envelope', 'show_timer', 'show_timeline',
    'show_gallery', 'show_gift', 'show_rsvp',
    'bank_name', 'account_holder', 'iban', 'gift_options',
    'rsvp_deadline', 'ask_menu_preference',
])]
class Invitation extends Model
{
    /** @use HasFactory<InvitationFactory> */
    use HasFactory, HasUlids, SoftDeletes;

    /**
     * Eloquent olayi -> alan (domain) olayi haritasi.
     *
     * Cache temizleme buradan tetiklenir. Action'lardan ELLE firlatmak yerine
     * modele gomuldu: yeni bir yazma yolu eklendiginde kimsenin hatirlamasi
     * gerekmesin. `created` BILEREK yok — yeni kaydin cache girdisi olamaz.
     * Ayrintili aciklama: docs/rehber/app/Models/Invitation.md §11
     */
    protected $dispatchesEvents = [
        'updated' => InvitationChanged::class,
        'deleted' => InvitationChanged::class,
        'restored' => InvitationChanged::class,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // 🔴 InvitationPolicy kati karsilastirma yapar; HasUlids yuzunden
            // getIncrementing() false, yani anahtar cast'i otomatik gelmiyor.
            'user_id' => 'integer',

            'status' => InvitationStatus::class,

            // K23: degismez tarih. $d->addDay() orijinali bozmaz, kopya doner.
            'event_at' => 'immutable_datetime',
            'rsvp_deadline' => 'immutable_date',
            'published_at' => 'immutable_datetime',

            'gift_options' => 'array',

            // Galerinin sirasi: media ULID'leri (2026_09_17 migration'i).
            'gallery_media_ids' => 'array',

            'show_envelope' => 'boolean',
            'show_timer' => 'boolean',
            'show_timeline' => 'boolean',
            'show_gallery' => 'boolean',
            'show_gift' => 'boolean',
            'show_rsvp' => 'boolean',
            'ask_menu_preference' => 'boolean',
        ];
    }

    /**
     * Misafire acik surumun cache anahtari.
     *
     * Controller yazar (4.3), ClearInvitationCache siler (4.6): iki tuketici,
     * TEK uretici (C3). Anahtari elle kuran ikinci bir yer olsaydi, birinde
     * yapilan bir degisiklik digerini sessizce bayat cache'e kilitlerdi.
     * Ayrintili aciklama: docs/rehber/app/Models/Invitation.md §10
     */
    public static function publicCacheKey(string $id): string
    {
        return Config::string('davetkart.cache.key_prefix').':public-invitation:'.$id;
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Program akisi — HER ZAMAN kullanicinin sirasiyla doner.
     *
     * Siralama iliskinin icinde: cagiran yerde unutulursa sira rastgele olurdu.
     *
     * @return HasMany<TimelineEvent, $this>
     */
    public function timelineEvents(): HasMany
    {
        return $this->hasMany(TimelineEvent::class)->orderBy('sort_order');
    }

    /**
     * Bu davetiyeye gelen LCV yanitlari.
     *
     * Siralama BILEREK yok. Program adimlarinin sirasi anlamin parcasiydi ve
     * iliskiye gomulmustu; LCV listesinin sirasi ise bir SUNUM tercihidir —
     * kota sorgusu hic siralamaz, panel en yeniyi ustte ister. Karar cagirana
     * birakildi (Faz 3, 2.8'in ayni ailesi).
     *
     * @return HasMany<Rsvp, $this>
     */
    public function rsvps(): HasMany
    {
        return $this->hasMany(Rsvp::class);
    }

    /**
     * Bu davetiye ICIN alinmis siparisler — yalnizca TEKIL alimlar (K42).
     *
     * 🔴 Paket alimlar (invitation_id = NULL) bu iliskiden GORUNMEZ ve bu bir
     * eksiklik degil: iliski bir yabanci anahtari izler, "hesabin her
     * davetiyesi" gibi bir kurali izleyemez. Yayin hakki bu iliskiden DEGIL,
     * PublishEntitlementResolver arayuzunden sorulur (7.9) — iki kaynagi tek
     * cevaba indiren yer orasidir.
     *
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Bu davetiyeye yuklenmis dosyalarin KAYITLARI.
     *
     * Siralama yok: galeri sirasi bu tabloda degil, `gallery_media_ids`
     * dizisinde tutuluyor. Buradaki satirlar sunucunun kaydi — kota sayimi ve
     * temizlik icin.
     *
     * @return HasMany<Media, $this>
     */
    public function media(): HasMany
    {
        return $this->hasMany(Media::class);
    }

    /**
     * Yalnizca galeri dosyalari — Resource'larin eager load ettigi iliski.
     *
     * `media()` LCV foto/videolarini da tasir; galeriyi cizmek icin misafirlerin
     * yukledigi yuzlerce dosyayi bellege almak gereksiz olurdu. Siralama yine
     * YOK: sira `orderedGalleryMedia()`'da, dizi uzerinden kurulur.
     *
     * @return HasMany<Media, $this>
     */
    public function galleryMedia(): HasMany
    {
        return $this->hasMany(Media::class)->where('kind', MediaKind::Gallery);
    }

    /**
     * `gallery_media_ids` kolonunun TIPLI okumasi.
     *
     * Kolon JSON; cast bize `array` verir ama icerigini garanti etmez. Metin
     * olmayan her oge atilir — bozuk bir oge galeriyi degil yalnizca kendisini
     * dusurur.
     *
     * @return list<string>
     */
    public function galleryMediaIds(): array
    {
        $raw = $this->gallery_media_ids;

        if (! is_array($raw)) {
            return [];
        }

        return array_values(array_filter($raw, is_string(...)));
    }

    /**
     * Galerideki dosyalar KULLANICININ SIRASIYLA.
     *
     * 🔴 `galleryMedia` iliskisi YUKLU olmali (kati kip, 3.9). Dizide olup
     * satiri olmayan kimlik sessizce atlanir: misafire kirik bir gorsel
     * gostermek, o ogeyi hic gostermemekten kotudur.
     *
     * @return Collection<int, Media>
     */
    public function orderedGalleryMedia(): Collection
    {
        $byId = $this->galleryMedia->keyBy('id');
        $ordered = [];

        foreach ($this->galleryMediaIds() as $id) {
            $media = $byId->get($id);

            if ($media instanceof Media) {
                $ordered[] = $media;
            }
        }

        return collect($ordered);
    }
}
